package admin

import (
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"net/mail"
	"os"
	"regexp"
	"strconv"
	"strings"
	"time"

	"gorm.io/gorm"
)

// maxMjmlBytes caps the MJML part. A designed newsletter is a few tens of kilobytes; this
// leaves room while stopping a pasted image-as-data-URI from bloating every copy.
const maxMjmlBytes = 256 * 1024

// testTokenLifetime is how long a test send authorises creating that exact ADMIN.
const testTokenLifetime = 24 * time.Hour

// htmlTagInText spots real HTML tags in the plain-text part, by element name, so that placeholder
// text such as "<your names here>" is still allowed.
var htmlTagInText = regexp.MustCompile(`(?i)<\s*/?\s*(a|abbr|b|blockquote|body|br|button|center|code|div|em|font|form|h[1-6]|head|hr|html|i|iframe|img|input|li|link|meta|ol|p|pre|script|section|small|span|strong|style|sub|sup|table|tbody|td|th|thead|tr|u|ul|mjml|mj-[a-z-]+)(\s[^<>]*)?/?>|<!--`)

// mjmlForbidden are the elements an MJML part may not contain. The part is only the sections that
// go inside <mj-body>: Freegle's template supplies the document, head, header and footer.
// mj-include would make the MJML server read a file.
var mjmlForbidden = regexp.MustCompile(`(?i)<\s*(mjml|mj-head|mj-body|mj-include)\b`)

// mjmlTopLevel is what an MJML part must contain at least one of to put anything in the body.
var mjmlTopLevel = regexp.MustCompile(`(?i)<\s*(mj-section|mj-wrapper|mj-hero)\b`)

// checkText returns a message if the plain-text part contains HTML, or "" if it is fine.
func checkText(text string) string {
	if m := htmlTagInText.FindString(text); m != "" {
		return "The text part must be plain text, with no HTML (found " + m + "). " +
			"Put formatted content in the MJML part instead."
	}
	return ""
}

// checkMjml returns a message if the MJML part is unusable, or "" if it is fine. Dangerous HTML
// inside it is not rejected here: iznik-batch sanitises the MJML every time it builds the email.
func checkMjml(mjml string) string {
	if strings.TrimSpace(mjml) == "" {
		return ""
	}
	if len(mjml) > maxMjmlBytes {
		return "The MJML part is too long (limit " + strconv.Itoa(maxMjmlBytes/1024) + "KB)."
	}
	if m := mjmlForbidden.FindString(mjml); m != "" {
		return "The MJML part must be only the sections that go inside <mj-body>, without " +
			strings.TrimSpace(m) + ">. Freegle adds the head, header and footer."
	}
	if !mjmlTopLevel.MatchString(mjml) {
		return "The MJML part must contain at least one <mj-section>, <mj-wrapper> or <mj-hero>."
	}
	return ""
}

// checkTestEmail returns the address if it is exactly one plain email address, or "".
func checkTestEmail(in string) string {
	in = strings.TrimSpace(in)
	addr, err := mail.ParseAddress(in)
	if err != nil || addr.Address != in || addr.Name != "" {
		return ""
	}
	return in
}

// emailContent is everything that changes what the email looks like. A test send authorises
// creating an ADMIN with exactly this content.
type emailContent struct {
	GroupID   uint64 `json:"groupid"`
	Subject   string `json:"subject"`
	Text      string `json:"text"`
	Mjml      string `json:"mjml"`
	CTAText   string `json:"ctatext"`
	CTALink   string `json:"ctalink"`
	Essential bool   `json:"essential"`
	Template  string `json:"template"`
}

func contentOf(req PostAdminRequest) emailContent {
	deref := func(s *string) string {
		if s == nil {
			return ""
		}
		return *s
	}
	return emailContent{
		GroupID:   req.GroupID,
		Subject:   req.Subject,
		Text:      req.Text,
		Mjml:      deref(req.Mjml),
		CTAText:   deref(req.CTA_Text),
		CTALink:   deref(req.CTA_Link),
		Essential: req.Essential == nil || *req.Essential,
		Template:  deref(req.Template),
	}
}

func signContent(myid uint64, content emailContent, expiry int64) string {
	j, _ := json.Marshal(content)
	sum := sha256.Sum256(j)
	mac := hmac.New(sha256.New, []byte(os.Getenv("JWT_SECRET")))
	mac.Write([]byte("admintest:" + strconv.FormatUint(myid, 10) + ":" + strconv.FormatInt(expiry, 10) + ":" + hex.EncodeToString(sum[:])))
	return hex.EncodeToString(mac.Sum(nil))
}

// testToken proves this user sent a test of this content. It is "<expiry>.<signature>".
func testToken(myid uint64, content emailContent) string {
	expiry := time.Now().Add(testTokenLifetime).Unix()
	return strconv.FormatInt(expiry, 10) + "." + signContent(myid, content, expiry)
}

// testTokenValid reports whether token was issued to this user for exactly this content and has
// not expired.
func testTokenValid(token string, myid uint64, content emailContent) bool {
	parts := strings.SplitN(token, ".", 2)
	if len(parts) != 2 {
		return false
	}
	expiry, err := strconv.ParseInt(parts[0], 10, 64)
	if err != nil || time.Now().Unix() > expiry {
		return false
	}
	return hmac.Equal([]byte(parts[1]), []byte(signContent(myid, content, expiry)))
}

// uneditedCopies returns which of these admins are copies of a suggested ADMIN still exactly as
// suggested. Those were seen by whoever suggested them, so a local moderator may approve one
// without sending a test; any edit means a test is needed.
func uneditedCopies(db *gorm.DB, ids []uint64) map[uint64]bool {
	out := map[uint64]bool{}
	if len(ids) == 0 {
		return out
	}

	var rows []uint64
	db.Raw("SELECT c.id FROM admins c JOIN admins p ON p.id = c.parentid "+
		"WHERE c.id IN (?) AND c.subject = p.subject AND c.text = p.text "+
		"AND COALESCE(c.mjml, '') = COALESCE(p.mjml, '') "+
		"AND COALESCE(c.ctatext, '') = COALESCE(p.ctatext, '') "+
		"AND COALESCE(c.ctalink, '') = COALESCE(p.ctalink, '') "+
		"AND c.essential = p.essential AND COALESCE(c.template, '') = COALESCE(p.template, '')", ids).Scan(&rows)
	for _, id := range rows {
		out[id] = true
	}
	return out
}

// storedContent is the email content of an admin as saved.
func storedContent(db *gorm.DB, id uint64) emailContent {
	var row struct {
		Groupid   *uint64
		Subject   string
		Text      string
		Mjml      *string
		Ctatext   *string
		Ctalink   *string
		Essential bool
		Template  *string
	}
	db.Raw("SELECT groupid, subject, text, mjml, ctatext, ctalink, essential, template FROM admins WHERE id = ?", id).Scan(&row)

	deref := func(s *string) string {
		if s == nil {
			return ""
		}
		return *s
	}
	var groupid uint64
	if row.Groupid != nil {
		groupid = *row.Groupid
	}
	return emailContent{
		GroupID:   groupid,
		Subject:   row.Subject,
		Text:      row.Text,
		Mjml:      deref(row.Mjml),
		CTAText:   deref(row.Ctatext),
		CTALink:   deref(row.Ctalink),
		Essential: row.Essential,
		Template:  deref(row.Template),
	}
}
