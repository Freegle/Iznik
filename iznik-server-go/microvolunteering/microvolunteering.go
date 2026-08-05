package microvolunteering

import (
	"fmt"
	"math/rand"
	"os"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/misc"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// AIImageChallenge represents an AI image to review
type AIImageChallenge struct {
	ID         uint64 `json:"id"`
	Name       string `json:"name"`
	URL        string `json:"url"`
	UsageCount uint64 `json:"usage_count"`
}

// EEELabelChallenge represents a Freegle item to be labelled for EEE
// (Electrical / Electronic Equipment) classification training.
type EEELabelChallenge struct {
	Messageid uint64 `json:"messageid"`
	Attid     uint64 `json:"attid"`
	ItemName  string `json:"itemName"`
	ImageURL  string `json:"imageUrl"`
}

// Challenge represents a micro-volunteering challenge
type Challenge struct {
	Type     string             `json:"type"`
	Msgid    *uint64            `json:"msgid,omitempty"`
	Terms    []SearchTerm       `json:"terms,omitempty"`
	Photos   []Photo            `json:"photos,omitempty"`
	URL      *string            `json:"url,omitempty"`
	AIImage  *AIImageChallenge  `json:"aiimage,omitempty"`
	EEELabel *EEELabelChallenge `json:"eeelabel,omitempty"`
}

// SearchTerm represents a search term for matching
type SearchTerm struct {
	ID   uint64 `json:"id"`
	Term string `json:"term"`
}

// Photo represents a photo for rotation challenge
type Photo struct {
	ID   uint64 `json:"id"`
	Path string `json:"path"`
}

// Challenge types
const (
	ChallengeCheckMessage  = "CheckMessage"
	ChallengeSearchTerm    = "SearchTerm"
	ChallengePhotoRotate   = "PhotoRotate"
	ChallengeSurvey        = "Survey2"
	ChallengeInvite        = "Invite"
	ChallengeAIImageReview = "AIImageReview"
	ChallengeEEELabel      = "EEELabel"
)

// Trust levels
const (
	TrustExcluded = "Excluded"
	TrustDeclined = "Declined"
	TrustBasic    = "Basic"
	TrustModerate = "Moderate"
	TrustAdvanced = "Advanced"
)

// Microvolunteering quorum constants
const (
	ApprovalQuorum      = 2
	DissentingQuorum    = 3
	AIImageReviewQuorum = 5
	EEELabelQuorum      = 3
)

// EEE label vocabularies — kept here so the server rejects invalid client
// submissions and the Vue component / sync command have a single source of
// truth via a comment.
//
//	Condition: reusable | damaged | unsure
//	Weight:    under_1kg | 1_5kg | 5_20kg | 20_100kg | over_100kg | unsure
//	Size:      tiny | small | medium | large | unsure
var (
	validEEEConditions = map[string]bool{"reusable": true, "damaged": true, "unsure": true}
	validEEEWeights    = map[string]bool{"under_1kg": true, "1_5kg": true, "5_20kg": true, "20_100kg": true, "over_100kg": true, "unsure": true}
	validEEESizes      = map[string]bool{"tiny": true, "small": true, "medium": true, "large": true, "unsure": true}
)

func isValidEEECondition(v string) bool { return validEEEConditions[v] }
func isValidEEEWeight(v string) bool    { return validEEEWeights[v] }
func isValidEEESize(v string) bool      { return validEEESizes[v] }

// CoinFlip picks between AI image review and approved message review when both
// are available. Overridable from tests so both branches can be exercised
// deterministically; otherwise `rand.Intn(2)` leaves the fallback paths
// covered only probabilistically, which flips Coveralls' per-job status check.
var CoinFlip = func() int { return rand.Intn(2) }

// GetChallenge returns a micro-volunteering challenge for the logged-in user
// @Summary Get micro-volunteering challenge
// @Description Returns a micro-volunteering challenge for the logged-in user
// @Tags microvolunteering
// @Accept json
// @Produce json
// @Param groupid query int false "Group ID to get challenges for"
// @Param types query []string false "Challenge types to include"
// @Success 200 {object} Challenge "Micro-volunteering challenge"
// @Failure 401 {object} map[string]string "Not logged in"
// @Router /microvolunteering [get]
func GetChallenge(c *fiber.Ctx) error {
	db := database.DBConn

	// Use WhoAmI (not GetJWTFromRequest) so the auth middleware enforces server-side
	// session revocation: a logged-out-but-unexpired JWT should not still be handed a
	// microvolunteering challenge. Same pattern as the giftaid handlers.
	userID := user.WhoAmI(c)
	if userID == 0 {
		return c.Status(401).JSON(fiber.Map{
			"error": "Not logged in",
		})
	}

	// when list=true, return moderator listing of microactions.
	if c.Query("list") == "true" || c.Query("list") == "1" {
		return listMicroActions(c, db, userID)
	}

	// Get parameters
	groupID := c.QueryInt("groupid", 0)

	// Parse types from query — handle both "types=A,B" and repeated "types=A&types=B" and "types[]=A&types[]=B".
	var challengeTypes []string
	c.Context().QueryArgs().VisitAll(func(key, value []byte) {
		k := string(key)
		if k == "types" || k == "types[]" {
			for _, t := range strings.Split(string(value), ",") {
				t = strings.TrimSpace(t)
				if t != "" {
					challengeTypes = append(challengeTypes, t)
				}
			}
		}
	})

	if len(challengeTypes) == 0 {
		challengeTypes = []string{
			ChallengeInvite,
			ChallengeCheckMessage,
			ChallengeAIImageReview,
			ChallengeEEELabel,
			ChallengePhotoRotate,
		}
	}

	// Get user's trust level
	var trustLevel string
	// ORM migration site 2d399fd44a2c (wave 1).
	err := db.Table("users").Select("COALESCE(trustlevel, ?)", TrustBasic).
		Where("id = ?", userID).Scan(&trustLevel).Error

	if err != nil {
		return c.Status(500).JSON(fiber.Map{
			"error": "Failed to fetch user trust level",
		})
	}

	// Don't offer challenges to declined/excluded users
	if trustLevel == TrustDeclined || trustLevel == TrustExcluded {
		return c.JSON(fiber.Map{})
	}

	// Get user's group IDs
	var groupIDs []uint64
	if groupID > 0 {
		groupIDs = []uint64{uint64(groupID)}
	} else {
		// Get all user's Freegle groups
		// ORM migration site 41b78139b026 (wave 4).
		db.Table("memberships").
			Select("groupid").
			Joins("INNER JOIN `groups` ON memberships.groupid = `groups`.id").
			Where("userid = ? AND type = ?", userID, utils.GROUP_TYPE_FREEGLE).
			Scan(&groupIDs)
	}

	// Try Invite challenge first
	if contains(challengeTypes, ChallengeInvite) {
		if challenge := getInviteChallenge(db, userID); challenge != nil {
			return c.JSON(challenge)
		}
	}

	// Try pending message review for Moderate trust level users
	if trustLevel == TrustModerate && len(groupIDs) > 0 {
		if challenge := getPendingMessageChallenge(db, userID, groupIDs); challenge != nil {
			return c.JSON(challenge)
		}
	}

	// Try EEELabel first (ahead of AIImageReview/CheckMessage). The EEE
	// labelling pipeline relies on volunteer labels reaching quorum quickly,
	// and AIImageReview / CheckMessage have plentiful backlogs that would
	// otherwise crowd it out entirely.
	if contains(challengeTypes, ChallengeEEELabel) {
		if challenge := getEEELabelChallenge(db, userID); challenge != nil {
			return c.JSON(challenge)
		}
	}

	// Randomize between approved message review and AI image review (50/50) so that
	// AI image review actually gets served — otherwise CheckMessage always has work
	// and AI image review is never reached.
	wantCheckMessage := contains(challengeTypes, ChallengeCheckMessage) && len(groupIDs) > 0
	wantAIImage := contains(challengeTypes, ChallengeAIImageReview)

	if wantCheckMessage && wantAIImage {
		if CoinFlip() == 0 {
			if challenge := getAIImageReviewChallenge(db, userID); challenge != nil {
				return c.JSON(challenge)
			}
			if challenge := getApprovedMessageChallenge(db, userID, groupIDs); challenge != nil {
				return c.JSON(challenge)
			}
		} else {
			if challenge := getApprovedMessageChallenge(db, userID, groupIDs); challenge != nil {
				return c.JSON(challenge)
			}
			if challenge := getAIImageReviewChallenge(db, userID); challenge != nil {
				return c.JSON(challenge)
			}
		}
	} else if wantCheckMessage {
		if challenge := getApprovedMessageChallenge(db, userID, groupIDs); challenge != nil {
			return c.JSON(challenge)
		}
	} else if wantAIImage {
		if challenge := getAIImageReviewChallenge(db, userID); challenge != nil {
			return c.JSON(challenge)
		}
	}

	// Try photo rotate challenge
	if contains(challengeTypes, ChallengePhotoRotate) && len(groupIDs) > 0 {
		if challenge := getPhotoRotateChallenge(db, userID, groupIDs); challenge != nil {
			return c.JSON(challenge)
		}
	}

	// Try search term challenge
	if contains(challengeTypes, ChallengeSearchTerm) {
		// Check if user is in a group with word matching enabled.
		//
		// ORM migration site 80c36f2da91e (Tier 3 keep-raw review). groupID>0
		// is the only toggle - 2 possible rendered forms, both declared in
		// ormharness/shapes.json and proven by TestTier3Shapes_80c36f2da91e
		// (iznik-server-go/test).
		// WHERE built as a single string for ONE Where() call: GORM's
		// clause.Where wraps any fragment containing "AND"/"OR" in an extra
		// paren pair once there is more than one Where expression to
		// combine (clause/where.go buildExprs), which would diverge from
		// the golden.
		enabledWhereSQL := "memberships.userid = ?"
		enabledWhereArgs := []interface{}{userID}
		if groupID > 0 {
			// Filter to specific group if provided
			enabledWhereSQL += " AND memberships.groupid = ?"
			enabledWhereArgs = append(enabledWhereArgs, groupID)
		}
		enabledWhereSQL += " AND (microvolunteeringoptions IS NULL OR JSON_EXTRACT(microvolunteeringoptions, '$.wordmatch') = 1)"

		var enabled int
		db.Table("memberships").
			Select("COUNT(*)").
			Joins("INNER JOIN `groups` ON memberships.groupid = `groups`.id").
			Where(enabledWhereSQL, enabledWhereArgs...).
			Scan(&enabled)

		if enabled > 0 {
			// Get 10 random popular items
			type ItemTerm struct {
				ID   uint64 `json:"id"`
				Term string `json:"term"`
			}
			var terms []ItemTerm

			// ORM migration site 39233a746ed4 (wave 5). Derived-table trick: GORM's
			// Table() passes its name argument through verbatim (no quoting) once it
			// contains a space, so a parenthesized subquery can be given as the
			// "table name".
			db.Table("(SELECT id, name FROM items WHERE LENGTH(name) > 2 ORDER BY popularity DESC LIMIT 300) t").
				Select("DISTINCT id, name AS term").
				Order("RAND()").
				Limit(10).
				Scan(&terms)

			if len(terms) > 0 {
				var searchTerms []SearchTerm
				for _, t := range terms {
					searchTerms = append(searchTerms, SearchTerm{
						ID:   t.ID,
						Term: t.Term,
					})
				}

				return c.JSON(Challenge{
					Type:  ChallengeSearchTerm,
					Terms: searchTerms,
				})
			}
		}
	}

	// If no challenge found, return empty object
	return c.JSON(fiber.Map{})
}

// Helper function to check if slice contains string
func contains(slice []string, str string) bool {
	for _, s := range slice {
		if s == str {
			return true
		}
	}
	return false
}

// getInviteChallenge returns an invite challenge if the user hasn't been asked recently
func getInviteChallenge(db *gorm.DB, userID uint64) *Challenge {
	// Check if we've asked in the last 31 days
	var count int64
	// ORM migration site fa53f46653e6 (wave 1).
	db.Table("microactions").
		Where("userid = ? AND actiontype = ? AND DATEDIFF(NOW(), timestamp) < 31", userID, ChallengeInvite).
		Count(&count)

	if count == 0 {
		// Record a placeholder to ensure we don't ask too often
		// ORM migration site d4ce9c3f1fc1 (wave 2).
		db.Table("microactions").Create(map[string]interface{}{
			"actiontype":     ChallengeInvite,
			"userid":         userID,
			"version":        gorm.Expr("4"),
			"comments":       gorm.Expr("'Ask to invite'"),
			"score_negative": gorm.Expr("0"),
		})

		return &Challenge{
			Type: ChallengeInvite,
		}
	}

	return nil
}

// getPendingMessageChallenge returns a pending message for moderate trust users to review
func getPendingMessageChallenge(db *gorm.DB, userID uint64, groupIDs []uint64) *Challenge {
	if len(groupIDs) == 0 {
		return nil
	}

	type MessageResult struct {
		Msgid uint64 `json:"msgid"`
	}
	var msg MessageResult

	// ORM migration site 309561e40e15 (Tier 3 keep-raw review). groupIDsStr
	// was a hand-built comma-joined literal-int list; GORM's native "IN (?)"
	// slice-bind is the direct replacement (proven pattern, see plan 7.5) and
	// gives this exactly one rendered form, declared in
	// ormharness/shapes.json and proven by TestTier3Shapes_309561e40e15
	// (iznik-server-go/test).
	// WHERE built as a single string for ONE Where() call: GORM's
	// clause.Where wraps any fragment containing "AND"/"OR" in an extra
	// paren pair once there is more than one Where expression to combine
	// (clause/where.go buildExprs), which would diverge from the golden.
	pendingWhereSQL := "messages_groups.groupid IN (?) AND DATE(messages.arrival) = CURDATE() AND fromuser != ? " +
		"AND microvolunteering = 1 AND messages.deleted IS NULL AND microactions.id IS NULL " +
		"AND (microvolunteeringoptions IS NULL OR JSON_EXTRACT(microvolunteeringoptions, '$.approvedmessages') = 1) " +
		"AND collection = ? AND autoreposts = 0"
	err := db.Table("messages_groups").
		Select("messages_groups.msgid").
		Joins("INNER JOIN messages ON messages.id = messages_groups.msgid").
		Joins("INNER JOIN `groups` ON groups.id = messages_groups.groupid").
		Joins("LEFT JOIN microactions ON microactions.msgid = messages_groups.msgid AND microactions.userid = ?", userID).
		Where(pendingWhereSQL, groupIDs, userID, utils.COLLECTION_PENDING).
		Order("messages_groups.arrival ASC").Limit(1).Scan(&msg).Error

	if err == nil && msg.Msgid > 0 {
		return &Challenge{
			Type:  ChallengeCheckMessage,
			Msgid: &msg.Msgid,
		}
	}

	return nil
}

// getApprovedMessageChallenge returns an approved message for any user to review
func getApprovedMessageChallenge(db *gorm.DB, userID uint64, groupIDs []uint64) *Challenge {
	if len(groupIDs) == 0 {
		return nil
	}

	type MessageResult struct {
		Msgid uint64 `json:"msgid"`
	}
	var msg MessageResult

	resultApprove := "Approve"

	// ORM migration site bde82a974f05 (Tier 3 keep-raw review). Same
	// literal-int-list-to-native-bind replacement as 309561e40e15 above -
	// exactly one rendered form, declared in ormharness/shapes.json and
	// proven by TestTier3Shapes_bde82a974f05 (iznik-server-go/test).
	// WHERE built as a single string for ONE Where() call: GORM's
	// clause.Where wraps any fragment containing "AND"/"OR" in an extra
	// paren pair once there is more than one Where expression to combine
	// (clause/where.go buildExprs), which would diverge from the golden.
	approvedWhereSQL := "messages_groups.groupid IN (?) AND DATE(messages.arrival) = CURDATE() AND fromuser != ? " +
		"AND microvolunteering = 1 AND messages_outcomes.id IS NULL AND messages.deleted IS NULL AND microactions.id IS NULL " +
		"AND (microvolunteeringoptions IS NULL OR JSON_EXTRACT(microvolunteeringoptions, '$.approvedmessages') = 1) " +
		"AND collection = ? AND autoreposts = 0"
	err := db.Table("messages_spatial").
		Select("messages_spatial.msgid, "+
			"(SELECT COUNT(*) AS count FROM microactions WHERE msgid = messages_spatial.msgid) AS reviewcount, "+
			"(SELECT COUNT(*) AS count FROM microactions WHERE msgid = messages_spatial.msgid AND result = ?) AS approvalcount",
			resultApprove).
		Joins("INNER JOIN messages_groups ON messages_spatial.msgid = messages_groups.msgid").
		Joins("INNER JOIN messages ON messages.id = messages_spatial.msgid").
		Joins("INNER JOIN `groups` ON groups.id = messages_groups.groupid").
		Joins("LEFT JOIN microactions ON microactions.msgid = messages_spatial.msgid AND microactions.userid = ?", userID).
		Joins("LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages_spatial.msgid").
		Where(approvedWhereSQL, groupIDs, userID, utils.COLLECTION_APPROVED).
		Having("approvalcount < ? AND reviewcount < ?", ApprovalQuorum, DissentingQuorum).
		Order("messages_groups.arrival ASC").Limit(1).Scan(&msg).Error

	if err == nil && msg.Msgid > 0 {
		return &Challenge{
			Type:  ChallengeCheckMessage,
			Msgid: &msg.Msgid,
		}
	}

	return nil
}

// getPhotoRotateChallenge returns photos that need rotation review
func getPhotoRotateChallenge(db *gorm.DB, userID uint64, groupIDs []uint64) *Challenge {
	if len(groupIDs) == 0 {
		return nil
	}

	type PhotoResult struct {
		ID uint64 `json:"id"`
	}
	var photos []PhotoResult

	today := time.Now().Format("2006-01-02")

	// ORM migration site ff5193d35cf8 (Tier 3 keep-raw review). Same
	// literal-int-list-to-native-bind replacement as 309561e40e15 above -
	// exactly one rendered form, declared in ormharness/shapes.json and
	// proven by TestTier3Shapes_ff5193d35cf8 (iznik-server-go/test).
	err := db.Table("messages_groups").
		Select("messages_attachments.id, "+
			"(SELECT COUNT(*) AS count FROM microactions WHERE rotatedimage = messages_attachments.id) AS reviewcount").
		Joins("INNER JOIN messages_attachments ON messages_attachments.msgid = messages_groups.msgid").
		Joins("LEFT JOIN microactions ON microactions.rotatedimage = messages_attachments.id AND userid = ?", userID).
		Joins("INNER JOIN `groups` ON groups.id = messages_groups.groupid AND microvolunteering = 1 AND (microvolunteeringoptions IS NULL OR JSON_EXTRACT(microvolunteeringoptions, '$.photorotate') = 1)").
		Where("arrival >= ? AND groupid IN (?) AND microactions.id IS NULL", today, groupIDs).
		Having("reviewcount < ?", DissentingQuorum).
		Order("RAND()").Limit(9).Scan(&photos).Error

	if err == nil && len(photos) > 0 {
		var photoList []Photo

		// Get image domain from environment
		imageDomain := os.Getenv("IMAGE_DOMAIN")
		if imageDomain == "" {
			imageDomain = "images.ilovefreegle.org"
		}

		for _, p := range photos {
			// Construct thumbnail path similar to how message.go does it
			path := "https://" + imageDomain + "/timg_" + fmt.Sprintf("%d", p.ID) + ".jpg"

			photoList = append(photoList, Photo{
				ID:   p.ID,
				Path: path,
			})
		}

		return &Challenge{
			Type:   ChallengePhotoRotate,
			Photos: photoList,
		}
	}

	return nil
}

// Version is the current microvolunteering protocol version.
const Version = 4

// getAIImageReviewChallenge returns an AI image for the user to review.
// Images are served in descending order of usage_count (most-used first),
// skipping images the user has already reviewed, images that have reached quorum,
// and images that are not in 'active' status (e.g. already rejected/regenerating).
func getAIImageReviewChallenge(db *gorm.DB, userID uint64) *Challenge {
	type AIImageResult struct {
		ID          uint64 `json:"id"`
		Name        string `json:"name"`
		Externaluid string `json:"externaluid"`
		UsageCount  uint64 `json:"usage_count"`
	}

	var img AIImageResult

	// ORM migration site 8c2181ff22ae (wave 5).
	err := db.Table("ai_images ai").
		Select("ai.id, ai.name, ai.externaluid, ai.usage_count").
		Joins("LEFT JOIN microactions ma ON ma.aiimageid = ai.id AND ma.userid = ? AND ma.actiontype = ?", userID, ChallengeAIImageReview).
		Where("ai.externaluid IS NOT NULL AND ai.externaluid != '' AND ai.status = 'active' AND ma.id IS NULL AND (SELECT COUNT(*) FROM microactions WHERE aiimageid = ai.id AND actiontype = ?) < ?",
			ChallengeAIImageReview, AIImageReviewQuorum).
		Order("ai.usage_count DESC").
		Limit(1).
		Scan(&img).Error

	if err != nil || img.ID == 0 {
		return nil
	}

	return &Challenge{
		Type: ChallengeAIImageReview,
		AIImage: &AIImageChallenge{
			ID:         img.ID,
			Name:       img.Name,
			URL:        misc.GetImageDeliveryUrl(img.Externaluid, ""),
			UsageCount: img.UsageCount,
		},
	}
}

// getEEELabelChallenge returns a Freegle attachment for the user to label
// for EEE (Electrical / Electronic Equipment) classification. Restricts to
// attachments the model classifier has already processed (joined via the
// `eee_classified_attachments` pointer table), so volunteer labels can be
// scored directly against model output.
//
// Previously this picked any recent OFFER with a photo, which produced lots
// of "wasted" labels on items the classifier never saw (confirmed
// 2026-05-30: 161 MV-labelled msgids ∩ eee_classifications = 0). Now the
// candidate set is the intersection of recent OFFER attachments and the
// classifier's pointer set, so every label can be paired with a model
// prediction.
//
// Performance: drives off `eee_classified_attachments` PRIMARY KEY by
// joining each pointer to its messages_attachments row. The pointer set
// is bounded (currently ~5k rows) so the join is cheap, no full scan of
// messages_attachments. Ordering by classified_at DESC surfaces the most
// recently classified items first.
func getEEELabelChallenge(db *gorm.DB, userID uint64) *Challenge {
	type AttachmentResult struct {
		Attid       uint64 `json:"attid"`
		Msgid       uint64 `json:"msgid"`
		Externaluid string `json:"externaluid"`
		Subject     string `json:"subject"`
	}

	var att AttachmentResult

	// ORM migration site 9f06198e9799 (wave 5).
	err := db.Table("eee_classified_attachments ec").
		Select("ma_att.id AS attid, m.id AS msgid, ma_att.externaluid, m.subject").
		Joins("INNER JOIN messages_attachments ma_att ON ma_att.id = ec.attid").
		Joins("INNER JOIN messages m ON m.id = ec.messageid").
		Where("m.deleted IS NULL AND ma_att.externaluid IS NOT NULL AND ma_att.externaluid != '' AND NOT EXISTS ( SELECT 1 FROM microactions ma WHERE ma.eee_attachment_id = ma_att.id AND ma.userid = ? AND ma.actiontype = ? ) AND (SELECT COUNT(*) FROM microactions WHERE eee_attachment_id = ma_att.id AND actiontype = ?) < ?",
			userID, ChallengeEEELabel, ChallengeEEELabel, EEELabelQuorum).
		Order("ec.classified_at DESC").
		Limit(1).
		Scan(&att).Error

	if err != nil || att.Attid == 0 {
		return nil
	}

	return &Challenge{
		Type: ChallengeEEELabel,
		EEELabel: &EEELabelChallenge{
			Messageid: att.Msgid,
			Attid:     att.Attid,
			ItemName:  cleanSubject(att.Subject),
			ImageURL:  misc.GetImageDeliveryUrl(att.Externaluid, ""),
		},
	}
}

// cleanSubject strips the "OFFER: " prefix and the trailing "(Location PC)"
// from a Freegle subject line so volunteers see the bare item name.
func cleanSubject(subject string) string {
	s := strings.TrimSpace(subject)
	// Strip leading "OFFER:" / "WANTED:" / etc.
	lower := strings.ToLower(s)
	for _, prefix := range []string{"offer:", "offered:", "wanted:", "request:", "requested:", "taken:", "received:"} {
		if strings.HasPrefix(lower, prefix) {
			s = strings.TrimSpace(s[len(prefix):])
			break
		}
	}
	// Strip trailing parenthesised location, e.g. " (Hanwell W7)"
	if i := strings.LastIndex(s, "("); i > 0 && strings.HasSuffix(strings.TrimSpace(s), ")") {
		s = strings.TrimSpace(s[:i])
	}
	return s
}

// PostResponseRequest represents the body for POST /microvolunteering
type PostResponseRequest struct {
	Msgid          uint64  `json:"msgid"`
	Groupid        uint64  `json:"groupid"`
	MsgCategory    *string `json:"msgcategory,omitempty"`
	Response       *string `json:"response,omitempty"`
	Comments       *string `json:"comments,omitempty"`
	Searchterm1    uint64  `json:"searchterm1"`
	Searchterm2    uint64  `json:"searchterm2"`
	Photoid        uint64  `json:"photoid"`
	Invite         bool    `json:"invite"`
	Deg            int     `json:"deg"`
	AIImageID      uint64  `json:"aiimageid"`
	ContainsPeople *bool   `json:"containspeople,omitempty"`
	// EEELabel response fields
	EEEAttachmentID uint64  `json:"eee_attachment_id,omitempty"`
	EEECondition    *string `json:"eee_condition,omitempty"`
	EEEWeight       *string `json:"eee_weight,omitempty"`
	EEESize         *string `json:"eee_size,omitempty"`
}

// PostResponse records a user's response to a micro-volunteering challenge
// @Summary Submit micro-volunteering response
// @Description Records the user's response to a micro-volunteering challenge
// @Tags microvolunteering
// @Accept json
// @Produce json
// @Security BearerAuth
// @Success 200 {object} fiber.Map
// @Failure 400 {object} fiber.Error "Invalid parameters"
// @Failure 401 {object} fiber.Error "Not logged in"
// @Router /microvolunteering [post]
func PostResponse(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req PostResponseRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	db := database.DBConn

	if req.Msgid > 0 && req.Response != nil {
		// Response to a CheckMessage challenge
		response := *req.Response

		if response == "Approve" || response == "Reject" {
			// SECURITY: only accept a verdict for a message the user could
			// legitimately have been challenged with — one posted to a group they
			// belong to, and not their own post. Without this any logged-in account
			// (no group membership, any trust level) could vote on arbitrary live
			// posts across the whole site, and at quorum force them back to Pending
			// (a platform-wide content-takedown). Mirrors GetChallenge's own
			// group-membership scoping on the read side.
			var eligible int64
			// ORM migration site f272e5ec73c0 (wave 4).
			db.Table("messages_groups").
				Select("COUNT(*)").
				Joins("INNER JOIN memberships ON memberships.groupid = messages_groups.groupid AND memberships.userid = ?", myid).
				Joins("INNER JOIN messages ON messages.id = messages_groups.msgid").
				Where("messages_groups.msgid = ? AND messages_groups.deleted = 0 AND COALESCE(messages.fromuser, 0) != ? AND messages.deleted IS NULL", req.Msgid, myid).
				Scan(&eligible)
			if eligible == 0 {
				return fiber.NewError(fiber.StatusForbidden, "Not eligible to review this message")
			}

			// Mark any notifications regarding this message as read
			// ORM migration site 0e09727e66aa (wave 2).
			db.Table("users_notifications").
				Where("touser = ? AND url LIKE CONCAT('/microvolunteering/message/', ?) AND type = 'Exhort'", myid, req.Msgid).
				Update("seen", gorm.Expr("1"))

			// Record the response - insert or update
			var msgcategory interface{}
			if req.MsgCategory != nil {
				msgcategory = *req.MsgCategory
			}

			var comments interface{}
			if req.Comments != nil {
				comments = *req.Comments
			}

			// ORM migration site e78fcf444c47 (wave 3).
			db.Table("microactions").Clauses(clause.OnConflict{
				DoUpdates: clause.Assignments(map[string]interface{}{
					"result": response, "comments": comments, "version": Version, "msgcategory": msgcategory,
				}),
			}).Create(map[string]interface{}{
				"actiontype":     ChallengeCheckMessage,
				"userid":         myid,
				"msgid":          req.Msgid,
				"result":         response,
				"msgcategory":    msgcategory,
				"comments":       comments,
				"version":        Version,
				"score_negative": gorm.Expr("0"),
			})

			// If rejection, check if we have quorum to send for review
			if response == "Reject" {
				var rejectCount int64
				// ORM migration site 5a4706a34749 (wave 1).
				db.Table("microactions").
					Where("msgid = ? AND result = 'Reject' AND comments IS NOT NULL AND (msgcategory IS NULL OR msgcategory = 'ShouldntBeHere')", req.Msgid).
					Count(&rejectCount)

				if rejectCount >= int64(ApprovalQuorum) {
					// Quorum reached — pull the post back to Pending on ALL the
					// groups it is live on (home + rippled-out copies), so every
					// affected community's moderators review it, not only the group
					// where this vote happened, then freeze the ripple.
					SendForReviewAllGroups(db, req.Msgid, "Members think there is something wrong with this message.")
					FreezeReachIfOriginPending(db, req.Msgid)
				}
			}
		}

		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})

	} else if req.Searchterm1 > 0 && req.Searchterm2 > 0 {
		// Response to a SearchTerm challenge.
		// The result column is enum('Approve','Reject') NOT NULL with no default.
		// Set to 'Approve' since search term responses don't map to approve/reject.
		// ORM migration site 4bc6d0615816 (wave 3).
		db.Table("microactions").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{
				"userid": gorm.Expr("userid"), "version": Version,
			}),
		}).Create(map[string]interface{}{
			"actiontype":     ChallengeSearchTerm,
			"userid":         myid,
			"item1":          req.Searchterm1,
			"item2":          req.Searchterm2,
			"version":        Version,
			"result":         gorm.Expr("'Approve'"),
			"score_negative": gorm.Expr("0"),
		})

		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})

	} else if req.Photoid > 0 {
		// Response to a PhotoRotate challenge
		var response interface{}
		if req.Response != nil {
			response = *req.Response
		}

		// SECURITY: the photo must belong to a message still live on a group the user
		// is a member of. At quorum a Reject drives an automatic rotation, so an
		// arbitrary attachment id must not be votable by an unrelated account. Unlike
		// CheckMessage there is deliberately no author exclusion: getPhotoRotateChallenge
		// can legitimately serve a user their own freshly-posted photo to review.
		var eligible int64
		// ORM migration site ec2405eade43 (wave 4).
		db.Table("messages_attachments").
			Select("COUNT(*)").
			Joins("INNER JOIN messages_groups ON messages_groups.msgid = messages_attachments.msgid AND messages_groups.deleted = 0").
			Joins("INNER JOIN memberships ON memberships.groupid = messages_groups.groupid AND memberships.userid = ?", myid).
			Joins("INNER JOIN messages ON messages.id = messages_attachments.msgid").
			Where("messages_attachments.id = ? AND messages.deleted IS NULL", req.Photoid).
			Scan(&eligible)
		if eligible == 0 {
			return fiber.NewError(fiber.StatusForbidden, "Not eligible to review this photo")
		}

		// ORM migration site f82ee651d4b9 (wave 3).
		db.Table("microactions").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"actiontype":     ChallengePhotoRotate,
			"userid":         myid,
			"rotatedimage":   req.Photoid,
			"result":         response,
			"version":        Version,
			"score_negative": gorm.Expr("0"),
		})

		// Check if we have enough votes to rotate the photo
		rotated := false
		if req.Response != nil && *req.Response == "Reject" {
			var voteCount int64
			// ORM migration site c94e3bfae9b3 (wave 1).
			db.Table("microactions").Where("rotatedimage = ? AND result = 'Reject'", req.Photoid).Count(&voteCount)

			if voteCount >= int64(ApprovalQuorum) {
				// Enough votes - the batch process handles the actual rotation
				rotated = true
			}
		}

		return c.JSON(fiber.Map{"ret": 0, "status": "Success", "rotated": rotated})

	} else if req.AIImageID > 0 && req.Response != nil {
		// Response to an AIImageReview challenge.
		response := *req.Response

		if response == "Approve" || response == "Reject" || response == "Suppress" {
			var containsPeople interface{}
			if req.ContainsPeople != nil {
				if *req.ContainsPeople {
					containsPeople = 1
				} else {
					containsPeople = 0
				}
			}

			// ORM migration site 6dadb189bddc (wave 3).
			db.Table("microactions").Clauses(clause.OnConflict{
				DoUpdates: clause.Assignments(map[string]interface{}{
					"result": response, "containspeople": containsPeople, "version": Version,
				}),
			}).Create(map[string]interface{}{
				"actiontype":     ChallengeAIImageReview,
				"userid":         myid,
				"aiimageid":      req.AIImageID,
				"result":         response,
				"containspeople": containsPeople,
				"version":        Version,
				"score_negative": gorm.Expr("0"),
			})

			// After recording the vote, check the quorums. 'Suppress' ("this item
			// should never have an AI image") is terminal, so check it first; 'Reject'
			// ("this image is bad") leaves the name open to regeneration.
			checkAIImageSuppressQuorum(db, req.AIImageID)
			checkAIImageRejectQuorum(db, req.AIImageID)
		}

		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})

	} else if req.EEEAttachmentID > 0 && req.EEECondition != nil && req.EEEWeight != nil && req.EEESize != nil {
		// Response to an EEELabel challenge — Condition / Weight / Size labels.
		// All three are stored on a single microactions row. result is set to
		// 'Approve' as a placeholder because the column is NOT NULL.
		if !isValidEEECondition(*req.EEECondition) ||
			!isValidEEEWeight(*req.EEEWeight) ||
			!isValidEEESize(*req.EEESize) {
			return fiber.NewError(fiber.StatusBadRequest, "Invalid EEE label values")
		}

		// ORM migration site 9b0560d85c4d (wave 3).
		db.Table("microactions").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{
				"eee_condition": *req.EEECondition, "eee_weight": *req.EEEWeight, "eee_size": *req.EEESize, "version": Version,
			}),
		}).Create(map[string]interface{}{
			"actiontype":        ChallengeEEELabel,
			"userid":            myid,
			"eee_attachment_id": req.EEEAttachmentID,
			"eee_condition":     *req.EEECondition,
			"eee_weight":        *req.EEEWeight,
			"eee_size":          *req.EEESize,
			"result":            gorm.Expr("'Approve'"),
			"version":           Version,
			"score_negative":    gorm.Expr("0"),
		})

		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})

	} else if req.Invite {
		// Response to an Invite challenge.
		// The result column is enum('Approve','Reject') NOT NULL. Set to 'Approve' as
		// the default value since invite responses don't map to approve/reject.
		// ORM migration site 6602f9905a74 (wave 3).
		db.Table("microactions").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"actiontype": ChallengeInvite,
			"userid":     myid,
			"version":    Version,
			"result":     gorm.Expr("'Approve'"),
		})

		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
	}

	return fiber.NewError(fiber.StatusBadRequest, "Invalid parameters")
}

// ModFeedbackRequest represents the body for PATCH /microvolunteering
type ModFeedbackRequest struct {
	ID            uint64  `json:"id"`
	Feedback      string  `json:"feedback"`
	ScorePositive float64 `json:"score_positive"`
	ScoreNegative float64 `json:"score_negative"`
}

// ModFeedback allows a moderator to provide feedback on a microaction
// @Summary Provide moderator feedback on microaction
// @Description Allows a moderator to set feedback, score_positive, and score_negative on a microaction
// @Tags microvolunteering
// @Accept json
// @Produce json
// @Param id body int true "Microaction ID"
// @Param feedback body string true "Moderator feedback text"
// @Param score_positive body number false "Positive score"
// @Param score_negative body number false "Negative score"
// @Security BearerAuth
// @Success 200 {object} fiber.Map
// @Failure 400 {object} fiber.Error "Invalid parameters"
// @Failure 401 {object} fiber.Error "Not logged in"
// @Failure 403 {object} fiber.Error "Not a moderator"
// @Router /microvolunteering [patch]
func ModFeedback(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	// Only moderators can provide feedback.
	if !auth.IsSystemMod(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator")
	}

	var req ModFeedbackRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.ID == 0 || req.Feedback == "" {
		return fiber.NewError(fiber.StatusBadRequest, "id and feedback are required")
	}

	db := database.DBConn

	// Update the microaction with mod feedback and scores.
	// ORM migration site c5c083c3dc6e (wave 2).
	db.Table("microactions").Where("id = ?", req.ID).Updates(map[string]interface{}{
		"modfeedback":    req.Feedback,
		"score_positive": req.ScorePositive,
		"score_negative": req.ScoreNegative,
	})

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// SendForReviewAllGroups moves a message back to Pending on ALL the groups it is
// currently live (Approved) on - its home group AND any rippled-out copies. Used once
// the aggregate review quorum is reached (from in-app CheckMessage checks or website
// reports) or on a moderator Back to Pending, so every affected community's moderators
// see the post. Only Approved rows are touched. Exported so the moderation path reuses it.
func SendForReviewAllGroups(db *gorm.DB, msgid uint64, reason string) {
	if msgid == 0 {
		return
	}
	// ORM migration site 5092091807c2 (wave 2).
	db.Table("messages_groups").Where("msgid = ? AND collection = ?", msgid, utils.COLLECTION_APPROVED).
		Updates(map[string]interface{}{"collection": utils.COLLECTION_PENDING, "spamreason": reason})
}

// FreezeReachIfOriginPending freezes a post's ripple once its ORIGIN copy is no longer
// live-Approved (pulled back for review). Freezing keeps the rippling_reach row but sets
// status='held' + next_expansion_at=NULL, so: (a) it stops expanding, (b) ExpandService's
// retraction paths skip it and the Pending rippled copies persist for per-group
// moderation, and (c) initialiseNew's anti-join can never re-reach + re-notify it if a
// moderator later re-approves a copy. No-op if the origin is still live, or there is no
// reach row. Exported for the moderation Back to Pending path.
func FreezeReachIfOriginPending(db *gorm.DB, msgid uint64) {
	if msgid == 0 {
		return
	}
	var approvedOrigin int64
	// ORM migration site 85e57d30b7c7 (wave 1).
	db.Table("messages_groups").
		Where("msgid = ? AND rippled_in = 0 AND deleted = 0 AND collection = ?", msgid, utils.COLLECTION_APPROVED).
		Count(&approvedOrigin)
	if approvedOrigin > 0 {
		return
	}
	// ORM migration site 328303c750b3 (wave 2).
	db.Table("rippling_reach").Where("msgid = ? AND status <> 'held'", msgid).
		Updates(map[string]interface{}{"status": gorm.Expr("'held'"), "next_expansion_at": gorm.Expr("NULL")})
}

// reporterIsModOf reports whether the reporter's verdict on the group they reported on
// is authoritative on its own: Support/Admin, or a Moderator/Owner of that group.
func reporterIsModOf(db *gorm.DB, reporterID uint64, groupid uint64) bool {
	if auth.IsAdminOrSupport(reporterID) {
		return true
	}
	if groupid == 0 {
		return false
	}
	var c int64
	// ORM migration site 2b059ba266dc (wave 1).
	db.Table("memberships").
		Where("userid = ? AND groupid = ? AND role IN (?, ?)", reporterID, groupid, utils.ROLE_MODERATOR, utils.ROLE_OWNER).
		Count(&c)
	return c > 0
}

// RecordReportVerdict treats a report of a post (the User2Mod chat message the website
// report flow sends, targeted at `groupid`) as a microvolunteering "Reject" verdict, so
// website reports feed the SAME review quorum as in-app CheckMessage checks:
//   - a MODERATOR of the reported group (or Support/Admin) counts as quorum on their own:
//     the post is pulled to Pending on ALL its groups (home + rippled-out) immediately.
//     A scoped single-group pend proved insufficient - the other copies stayed live in
//     browse and the next digest (Discourse 9862);
//   - otherwise, once ApprovalQuorum distinct Reject verdicts accumulate, the post is
//     pulled to Pending on ALL its groups.
//
// Whenever the origin copy ends up Pending the ripple is frozen, so the copies persist for
// per-group moderation and it never re-ripples/re-notifies on a later re-approval.
// Best-effort: never blocks the report itself.
func RecordReportVerdict(db *gorm.DB, reporterID uint64, msgid uint64, groupid uint64, comments string) {
	if reporterID == 0 || msgid == 0 {
		return
	}

	// A poster reporting their own post must not count toward the quorum.
	var fromuser uint64
	// ORM migration site 1514d35d670c (wave 1).
	db.Table("messages").Select("fromuser").Where("id = ?", msgid).Scan(&fromuser)
	if fromuser == reporterID {
		return
	}

	// Record the report as a CheckMessage Reject verdict: ShouldntBeHere + non-null
	// comments so it counts toward the quorum, exactly like an in-app "something's not
	// right" check. The (userid, msgid) unique key makes a repeat report one verdict.
	if comments == "" {
		comments = "Reported via the website"
	}
	// ORM migration site 062b91c70acc (wave 3).
	db.Table("microactions").Clauses(clause.OnConflict{
		DoUpdates: clause.Assignments(map[string]interface{}{
			"result": gorm.Expr("'Reject'"), "msgcategory": gorm.Expr("'ShouldntBeHere'"), "comments": comments, "version": Version,
		}),
	}).Create(map[string]interface{}{
		"actiontype":     ChallengeCheckMessage,
		"userid":         reporterID,
		"msgid":          msgid,
		"result":         gorm.Expr("'Reject'"),
		"msgcategory":    gorm.Expr("'ShouldntBeHere'"),
		"comments":       comments,
		"version":        Version,
		"score_negative": gorm.Expr("0"),
	})

	const reason = "Members or moderators think there is something wrong with this message."

	// A moderator's report is quorum on its own: pull the post to Pending everywhere.
	if reporterIsModOf(db, reporterID, groupid) {
		SendForReviewAllGroups(db, msgid, reason)
	} else {
		// Aggregate quorum (all distinct Reject verdicts, reports or in-app checks)
		// pulls the post to Pending on every community it is on.
		var rejectCount int64
		// ORM migration site bc4e3f39c868 (wave 1).
		db.Table("microactions").
			Where("msgid = ? AND result = 'Reject' AND comments IS NOT NULL AND (msgcategory IS NULL OR msgcategory = 'ShouldntBeHere')", msgid).
			Count(&rejectCount)
		if rejectCount >= int64(ApprovalQuorum) {
			SendForReviewAllGroups(db, msgid, reason)
		}
	}

	// If the origin copy is now Pending, freeze the ripple (stops spread + re-reach).
	FreezeReachIfOriginPending(db, msgid)
}

// listMicroActions returns microvolunteering activity for moderator review.
// MicroVolunteering::list() in MicroVolunteering.php.
func listMicroActions(c *fiber.Ctx, db *gorm.DB, myid uint64) error {
	groupidParam := c.QueryInt("groupid", 0)
	limitParam := c.QueryInt("limit", 10)
	start := c.Query("start", "1970-01-01")
	context := c.QueryInt("context", 0)

	// Determine which groups to query.
	var groupIDs []uint64
	if groupidParam > 0 {
		groupIDs = []uint64{uint64(groupidParam)}
	} else {
		groupIDs = user.GetActiveModGroupIDs(myid)
	}

	if len(groupIDs) == 0 {
		return c.JSON(fiber.Map{
			"ret":                0,
			"status":             "Success",
			"microvolunteerings": make([]interface{}, 0),
			"context":            fiber.Map{},
		})
	}

	// Build query matching V1: microactions joined with memberships filtered by group.
	type MicroAction struct {
		ID            uint64    `json:"id"`
		Actiontype    string    `json:"actiontype"`
		Userid        uint64    `json:"userid"`
		Msgid         *uint64   `json:"msgid"`
		Msgcategory   *string   `json:"msgcategory"`
		Result        string    `json:"result"`
		Timestamp     time.Time `json:"timestamp"`
		Comments      *string   `json:"comments"`
		Item1         *uint64   `json:"item1"`
		Item2         *uint64   `json:"item2"`
		Rotatedimage  *uint64   `json:"rotatedimage"`
		ScorePositive float64   `json:"score_positive"`
		ScoreNegative float64   `json:"score_negative"`
		Modfeedback   *string   `json:"modfeedback"`
	}

	// ORM migration site 3762cb36efcf (Tier 3 keep-raw review). context>0 is
	// the only toggle - 2 possible rendered forms, both declared in
	// ormharness/shapes.json and proven by TestTier3Shapes_3762cb36efcf
	// (iznik-server-go/test).
	// WHERE built as a single string for ONE Where() call: GORM's
	// clause.Where wraps any fragment containing "AND"/"OR" in an extra
	// paren pair once there is more than one Where expression to combine
	// (clause/where.go buildExprs), which would diverge from the golden.
	microactionsWhereSQL := "memberships.groupid IN (?) AND microactions.timestamp >= ?"
	microactionsWhereArgs := []interface{}{groupIDs, start}
	if context > 0 {
		microactionsWhereSQL += " AND microactions.id < ?"
		microactionsWhereArgs = append(microactionsWhereArgs, context)
	}

	var items []MicroAction
	db.Table("microactions").
		Select("DISTINCT microactions.*").
		Joins("INNER JOIN memberships ON memberships.userid = microactions.userid").
		Where(microactionsWhereSQL, microactionsWhereArgs...).
		Order("microactions.id DESC").Limit(limitParam).Scan(&items)

	if items == nil {
		items = []MicroAction{}
	}

	// Build pagination context.
	newCtx := fiber.Map{}
	if len(items) > 0 {
		newCtx["id"] = items[len(items)-1].ID
	}

	return c.JSON(fiber.Map{
		"ret":                0,
		"status":             "Success",
		"microvolunteerings": items,
		"context":            newCtx,
	})
}

// RecordAIAttachmentDeletion records a Reject microaction for an AI image when a human
// (poster or moderator) deletes an AI-generated attachment from a message. This signals
// that the AI illustration was inappropriate for the item.
func RecordAIAttachmentDeletion(db *gorm.DB, userID uint64, aiImageID uint64) {
	// ORM migration site c2b7425e88e0 (wave 3). Converted together with its
	// identical twin in ForceRejectAIImage (98a9897d62e5): a half-converted
	// pair renumbers the survivor's site ID, so gate (h) refuses the split
	// state.
	db.Table("microactions").Clauses(clause.OnConflict{
		DoUpdates: clause.Assignments(map[string]interface{}{
			"result": gorm.Expr("'Reject'"), "version": Version,
		}),
	}).Create(map[string]interface{}{
		"actiontype":     ChallengeAIImageReview,
		"userid":         userID,
		"aiimageid":      aiImageID,
		"result":         gorm.Expr("'Reject'"),
		"version":        Version,
		"score_negative": gorm.Expr("0"),
	})
	checkAIImageRejectQuorum(db, aiImageID)
}

// ForceRejectAIImage immediately sets an AI image to rejected status, bypassing
// the normal quorum process. Used when a moderator explicitly signals the image
// is bad for any post of that item (not just irrelevant to the current post).
// Records an audit microaction so the rejection is traceable.
func ForceRejectAIImage(db *gorm.DB, userID uint64, aiImageID uint64) {
	// ORM migration site 92faccbe5a21 (wave 2).
	db.Table("ai_images").Where("id = ? AND status = 'active'", aiImageID).Update("status", gorm.Expr("'rejected'"))
	// ORM migration site 98a9897d62e5 (wave 3). Twin of c2b7425e88e0 above.
	db.Table("microactions").Clauses(clause.OnConflict{
		DoUpdates: clause.Assignments(map[string]interface{}{
			"result": gorm.Expr("'Reject'"), "version": Version,
		}),
	}).Create(map[string]interface{}{
		"actiontype":     ChallengeAIImageReview,
		"userid":         userID,
		"aiimageid":      aiImageID,
		"result":         gorm.Expr("'Reject'"),
		"version":        Version,
		"score_negative": gorm.Expr("0"),
	})
}

// checkAIImageRejectQuorum checks whether an AI image has reached the reject quorum
// (≥ AIImageReviewQuorum votes with a majority being Reject). If so, sets status='rejected'
// so the image is hidden from end users and surfaced for admin regeneration.
func checkAIImageRejectQuorum(db *gorm.DB, aiImageID uint64) {
	var totalVotes, rejectVotes int64
	// ORM migration site 253bc6651f22 (wave 1).
	db.Table("microactions").Where("aiimageid = ? AND actiontype = ?", aiImageID, ChallengeAIImageReview).Count(&totalVotes)
	// ORM migration site 4a4e6ef7b504 (wave 1).
	db.Table("microactions").
		Where("aiimageid = ? AND actiontype = ? AND result = 'Reject'", aiImageID, ChallengeAIImageReview).
		Count(&rejectVotes)

	if totalVotes >= int64(AIImageReviewQuorum) && rejectVotes > totalVotes/2 {
		// ORM migration site c1b4117a14d4 (wave 2).
		db.Table("ai_images").Where("id = ? AND status = 'active'", aiImageID).Update("status", gorm.Expr("'rejected'"))
	}
}

// checkAIImageSuppressQuorum checks whether an AI image has reached the suppress
// quorum (≥ AIImageReviewQuorum votes with a majority being Suppress). If so, sets
// status='suppressed' — a TERMINAL state meaning this item name should never have an
// AI image: the Pollinations generator skips the name, the image is never shown, and
// Regenerate refuses. Suppress overrides a prior 'rejected' state.
func checkAIImageSuppressQuorum(db *gorm.DB, aiImageID uint64) {
	var totalVotes, suppressVotes int64
	// ORM migration site bb15df86ae5f (wave 1).
	db.Table("microactions").Where("aiimageid = ? AND actiontype = ?", aiImageID, ChallengeAIImageReview).Count(&totalVotes)
	// ORM migration site c011457f1962 (wave 1).
	db.Table("microactions").
		Where("aiimageid = ? AND actiontype = ? AND result = 'Suppress'", aiImageID, ChallengeAIImageReview).
		Count(&suppressVotes)

	if totalVotes >= int64(AIImageReviewQuorum) && suppressVotes > totalVotes/2 {
		// ORM migration site d62b9f1b747c (wave 2).
		db.Table("ai_images").Where("id = ? AND status IN ('active','rejected')", aiImageID).
			Update("status", gorm.Expr("'suppressed'"))
	}
}
