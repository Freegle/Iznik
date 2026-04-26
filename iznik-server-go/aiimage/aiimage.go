package aiimage

import (
	"bytes"
	"fmt"
	"image"
	"image/color"
	"image/jpeg"
	_ "image/png"
	"io"
	"math/rand"
	"net/http"
	"net/url"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
)

// AIImageReview is the data returned for each image needing regeneration.
type AIImageReview struct {
	ID                 uint64        `json:"id"`
	Name               string        `json:"name"`
	Externaluid        string        `json:"externaluid"`
	ImageURL           string        `json:"image_url"`
	Status             string        `json:"status"`
	RegenerationNotes  *string       `json:"regeneration_notes"`
	PendingExternaluid *string       `json:"pending_externaluid"`
	PendingImageURL    *string       `json:"pending_image_url"`
	Votes              []AIImageVote `json:"votes"`
	RejectCount        int           `json:"reject_count"`
	ApproveCount       int           `json:"approve_count"`
}

// AIImageVote is a single volunteer vote for an AI image review.
type AIImageVote struct {
	UserID        uint64    `json:"userid"`
	Displayname   string    `json:"displayname"`
	Result        string    `json:"result"`
	ContainsPeople *int     `json:"containspeople"`
	Timestamp     time.Time `json:"timestamp"`
}

// buildPollinationsURL constructs the Pollinations.ai image generation URL for an AI image name.
// Uses a random seed so repeated calls return different images.
func buildPollinationsURL(name string) string {
	prompt := "Product illustration: single isolated " + name + " centered on plain dark green background. " +
		"Style: friendly cartoon white line drawing, moderate shading, cute and quirky, UK audience. " +
		"The object sits alone on a simple surface or floats in space. " +
		"Simple illustration style, clean lines, single object only."

	seed := rand.New(rand.NewSource(time.Now().UnixNano())).Intn(999999) + 2

	imageURL := "https://image.pollinations.ai/prompt/" + url.QueryEscape(prompt) +
		fmt.Sprintf("?width=640&height=480&nologo=true&seed=%d", seed)

	if key := os.Getenv("POLLINATIONS_API_KEY"); key != "" {
		imageURL += "&key=" + url.QueryEscape(key)
	}

	return imageURL
}

// fetchAndUploadToTUS downloads an image from sourceURL, applies the Freegle duotone
// filter, and uploads it to the internal TUS server. Returns the new externaluid.
func fetchAndUploadToTUS(sourceURL string) (string, error) {
	// Download image from Pollinations.ai.
	client := &http.Client{Timeout: 120 * time.Second}
	resp, err := client.Get(sourceURL)
	if err != nil {
		return "", fmt.Errorf("failed to download image: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode == 429 {
		return "", fmt.Errorf("rate_limited")
	}
	if resp.StatusCode >= 400 {
		return "", fmt.Errorf("pollinations returned HTTP %d", resp.StatusCode)
	}

	imgData, err := io.ReadAll(resp.Body)
	if err != nil {
		return "", fmt.Errorf("failed to read image body: %w", err)
	}

	// Apply duotone filter.
	filteredData, err := applyDuotoneGreen(imgData)
	if err != nil {
		// If duotone fails, upload the raw image rather than blocking the flow.
		filteredData = imgData
	}

	// Upload to TUS.
	return uploadToTUS(filteredData)
}

// freegleDarkGreen is the Freegle brand dark green used as the duotone shadow colour.
var freegleDarkGreen = color.RGBA{R: 0x00, G: 0x70, B: 0x4A, A: 0xFF}

// applyDuotoneGreen converts image data to JPEG with a green/white duotone effect.
func applyDuotoneGreen(imgData []byte) ([]byte, error) {
	src, _, err := image.Decode(bytes.NewReader(imgData))
	if err != nil {
		return nil, err
	}

	bounds := src.Bounds()
	dst := image.NewRGBA(bounds)
	dark := freegleDarkGreen
	light := color.RGBA{R: 0xFF, G: 0xFF, B: 0xFF, A: 0xFF}

	for y := bounds.Min.Y; y < bounds.Max.Y; y++ {
		for x := bounds.Min.X; x < bounds.Max.X; x++ {
			r32, g32, b32, a32 := src.At(x, y).RGBA()
			// ITU-R BT.709 luma (values are 0-65535 from RGBA()).
			luma := (19595*r32 + 38470*g32 + 7471*b32) >> 16
			t := float64(luma) / 65535.0
			nr := uint8(float64(dark.R)*(1-t) + float64(light.R)*t)
			ng := uint8(float64(dark.G)*(1-t) + float64(light.G)*t)
			nb := uint8(float64(dark.B)*(1-t) + float64(light.B)*t)
			dst.SetRGBA(x, y, color.RGBA{R: nr, G: ng, B: nb, A: uint8(a32 >> 8)})
		}
	}

	var buf bytes.Buffer
	if err := jpeg.Encode(&buf, dst, &jpeg.Options{Quality: 90}); err != nil {
		return nil, err
	}
	return buf.Bytes(), nil
}

// uploadToTUS uploads image bytes to the internal TUS server and returns the externaluid.
func uploadToTUS(data []byte) (string, error) {
	tusURL := os.Getenv("TUS_UPLOADER")
	if tusURL == "" {
		tusURL = "http://tusd:8080/tus"
	}
	// Ensure the URL ends with /files/ (TUS creation endpoint).
	if !strings.HasSuffix(tusURL, "/") {
		tusURL += "/"
	}

	size := len(data)

	// Step 1: POST to create the upload.
	createReq, err := http.NewRequest("POST", tusURL, nil)
	if err != nil {
		return "", err
	}
	createReq.Header.Set("Tus-Resumable", "1.0.0")
	createReq.Header.Set("Upload-Length", strconv.Itoa(size))
	createReq.Header.Set("Upload-Metadata", "filename cmVnZW5lcmF0ZWQuanBn,filetype aW1hZ2UvanBlZw==")
	// Base64 of "regenerated.jpg" and "image/jpeg" respectively.

	client := &http.Client{Timeout: 60 * time.Second}
	createResp, err := client.Do(createReq)
	if err != nil {
		return "", fmt.Errorf("TUS create failed: %w", err)
	}
	defer createResp.Body.Close()

	if createResp.StatusCode != 201 {
		return "", fmt.Errorf("TUS create returned HTTP %d", createResp.StatusCode)
	}

	uploadURL := createResp.Header.Get("Location")
	if uploadURL == "" {
		return "", fmt.Errorf("TUS create returned no Location header")
	}

	// Step 2: PATCH to upload the data.
	patchReq, err := http.NewRequest("PATCH", uploadURL, bytes.NewReader(data))
	if err != nil {
		return "", err
	}
	patchReq.Header.Set("Tus-Resumable", "1.0.0")
	patchReq.Header.Set("Content-Type", "application/offset+octet-stream")
	patchReq.Header.Set("Upload-Offset", "0")
	patchReq.ContentLength = int64(size)

	patchResp, err := client.Do(patchReq)
	if err != nil {
		return "", fmt.Errorf("TUS patch failed: %w", err)
	}
	defer patchResp.Body.Close()

	if patchResp.StatusCode != 204 {
		return "", fmt.Errorf("TUS patch returned HTTP %d", patchResp.StatusCode)
	}

	// Extract the UUID from the upload URL (last path segment).
	parts := strings.Split(strings.TrimSuffix(uploadURL, "/"), "/")
	uuid := parts[len(parts)-1]
	return "freegletusd-" + uuid, nil
}

// getDeliveryURL constructs the image delivery URL for a given externaluid.
func getDeliveryURL(externaluid string) string {
	if externaluid == "" {
		return ""
	}
	delivery := os.Getenv("IMAGE_DELIVERY")
	if delivery == "" {
		delivery = "https://delivery.ilovefreegle.org"
	}
	uploads := os.Getenv("UPLOADS")
	if uploads == "" {
		uploads = "https://uploads.ilovefreegle.org:8080/"
	}
	delivery = strings.TrimSuffix(delivery, "?url=")
	uid := externaluid
	if len(uid) > 12 {
		uid = uid[12:]
	}
	return delivery + "?url=" + uploads + uid
}

// ListReview handles GET /api/admin/ai-images/review.
// Returns all AI images with status 'rejected' or 'regenerating', including their votes and voter names.
//
// @Summary List AI images needing regeneration
// @Tags ai-images
// @Produce json
// @Success 200 {array} AIImageReview
// @Router /api/admin/ai-images/review [get]
func ListReview(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}
	if !user.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Must be Support or Admin")
	}

	db := database.DBConn

	type aiImageRow struct {
		ID                 uint64  `gorm:"column:id"`
		Name               string  `gorm:"column:name"`
		Externaluid        string  `gorm:"column:externaluid"`
		Status             string  `gorm:"column:status"`
		RegenerationNotes  *string `gorm:"column:regeneration_notes"`
		PendingExternaluid *string `gorm:"column:pending_externaluid"`
	}

	var rows []aiImageRow
	db.Raw(`SELECT id, name, COALESCE(externaluid, '') AS externaluid, status,
		regeneration_notes, pending_externaluid
		FROM ai_images WHERE status IN ('rejected', 'regenerating')
		ORDER BY id DESC`).Scan(&rows)

	type voteRow struct {
		AIImageID      uint64    `gorm:"column:aiimageid"`
		UserID         uint64    `gorm:"column:userid"`
		Displayname    string    `gorm:"column:displayname"`
		Result         string    `gorm:"column:result"`
		ContainsPeople *int      `gorm:"column:containspeople"`
		Timestamp      time.Time `gorm:"column:timestamp"`
	}

	if len(rows) == 0 {
		return c.JSON([]AIImageReview{})
	}

	ids := make([]uint64, len(rows))
	for i, r := range rows {
		ids[i] = r.ID
	}

	var votes []voteRow
	db.Raw(`SELECT ma.aiimageid, ma.userid,
		CASE WHEN u.fullname IS NOT NULL THEN u.fullname ELSE CONCAT(u.firstname, ' ', u.lastname) END AS displayname,
		ma.result, ma.containspeople, ma.timestamp
		FROM microactions ma
		INNER JOIN users u ON u.id = ma.userid
		WHERE ma.aiimageid IN (?) AND ma.actiontype = 'AIImageReview'
		ORDER BY ma.timestamp ASC`, ids).Scan(&votes)

	// Group votes by image ID.
	votesByID := make(map[uint64][]AIImageVote)
	for _, v := range votes {
		votesByID[v.AIImageID] = append(votesByID[v.AIImageID], AIImageVote{
			UserID:         v.UserID,
			Displayname:    v.Displayname,
			Result:         v.Result,
			ContainsPeople: v.ContainsPeople,
			Timestamp:      v.Timestamp,
		})
	}

	result := make([]AIImageReview, len(rows))
	for i, r := range rows {
		imageVotes := votesByID[r.ID]
		if imageVotes == nil {
			imageVotes = []AIImageVote{}
		}
		rejectCount, approveCount := 0, 0
		for _, v := range imageVotes {
			if v.Result == "Reject" {
				rejectCount++
			} else {
				approveCount++
			}
		}
		var pendingURL *string
		if r.PendingExternaluid != nil && *r.PendingExternaluid != "" {
			u := getDeliveryURL(*r.PendingExternaluid)
			pendingURL = &u
		}
		result[i] = AIImageReview{
			ID:                 r.ID,
			Name:               r.Name,
			Externaluid:        r.Externaluid,
			ImageURL:           getDeliveryURL(r.Externaluid),
			Status:             r.Status,
			RegenerationNotes:  r.RegenerationNotes,
			PendingExternaluid: r.PendingExternaluid,
			PendingImageURL:    pendingURL,
			Votes:              imageVotes,
			RejectCount:        rejectCount,
			ApproveCount:       approveCount,
		}
	}

	return c.JSON(result)
}

type RegenerateRequest struct {
	Notes string `json:"notes"`
}

// Regenerate handles POST /api/admin/ai-images/:id/regenerate.
// Saves the admin's notes and returns a Pollinations.ai preview URL for the new image.
//
// @Summary Generate a preview for a rejected AI image
// @Tags ai-images
// @Accept json
// @Produce json
// @Param id path integer true "AI Image ID"
// @Success 200 {object} map[string]interface{}
// @Router /api/admin/ai-images/{id}/regenerate [post]
func Regenerate(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}
	if !user.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Must be Support or Admin")
	}

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil || id == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid image ID")
	}

	var req RegenerateRequest
	c.BodyParser(&req)

	db := database.DBConn

	// Verify image exists and is in a regenerable state.
	var name string
	db.Raw("SELECT name FROM ai_images WHERE id = ? AND status IN ('rejected', 'regenerating')", id).Scan(&name)
	if name == "" {
		return fiber.NewError(fiber.StatusNotFound, "AI image not found or not in rejected/regenerating status")
	}

	// Save notes.
	if req.Notes != "" {
		db.Exec("UPDATE ai_images SET regeneration_notes = ? WHERE id = ?", req.Notes, id)
	}

	// Return a Pollinations.ai URL for preview (no upload yet — the admin inspects it first).
	previewURL := buildPollinationsURL(name)

	return c.JSON(fiber.Map{
		"ret":         0,
		"preview_url": previewURL,
	})
}

type AcceptRequest struct {
	PendingExternaluid string `json:"pending_externaluid"`
}

// Accept handles POST /api/admin/ai-images/:id/accept.
// Accepts the new image: uploads it to TUS if given a Pollinations URL or uses the
// pending_externaluid already stored, updates ai_images, resets votes, and applies
// the new externaluid to all messages_attachments that reference the old one.
//
// @Summary Accept a regenerated AI image
// @Tags ai-images
// @Accept json
// @Produce json
// @Param id path integer true "AI Image ID"
// @Success 200 {object} map[string]interface{}
// @Router /api/admin/ai-images/{id}/accept [post]
func Accept(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}
	if !user.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Must be Support or Admin")
	}

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil || id == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid image ID")
	}

	var req AcceptRequest
	c.BodyParser(&req)

	db := database.DBConn

	type aiRow struct {
		Externaluid        string  `gorm:"column:externaluid"`
		PendingExternaluid *string `gorm:"column:pending_externaluid"`
	}
	var row aiRow
	db.Raw("SELECT COALESCE(externaluid, '') AS externaluid, pending_externaluid FROM ai_images WHERE id = ?", id).Scan(&row)
	if row.Externaluid == "" && (row.PendingExternaluid == nil || *row.PendingExternaluid == "") {
		return fiber.NewError(fiber.StatusNotFound, "AI image not found")
	}

	oldUID := row.Externaluid

	// Determine the new externaluid.
	newUID := req.PendingExternaluid
	if newUID == "" && row.PendingExternaluid != nil {
		newUID = *row.PendingExternaluid
	}
	if newUID == "" {
		return fiber.NewError(fiber.StatusBadRequest, "pending_externaluid is required")
	}

	// Apply the new image: update ai_images, clear pending state, reset to active.
	db.Exec(`UPDATE ai_images
		SET externaluid = ?, pending_externaluid = NULL, regeneration_notes = NULL, status = 'active'
		WHERE id = ?`, newUID, id)

	// Delete old votes so the new image can be reviewed fresh.
	db.Exec(`DELETE FROM microactions WHERE aiimageid = ? AND actiontype = 'AIImageReview'`, id)

	// Apply the new externaluid to all message attachments that had the old one.
	if oldUID != "" && oldUID != newUID {
		db.Exec(`UPDATE messages_attachments SET externaluid = ? WHERE externaluid = ?`, newUID, oldUID)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// Count returns the number of AI images currently needing regeneration (rejected or regenerating).
// Used by the ModTools nav badge.
//
// @Summary Count AI images needing regeneration
// @Tags ai-images
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /api/admin/ai-images/count [get]
func Count(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}
	if !user.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Must be Support or Admin")
	}

	db := database.DBConn
	var count int64
	db.Raw(`SELECT COUNT(*) FROM ai_images WHERE status IN ('rejected', 'regenerating')`).Scan(&count)

	return c.JSON(fiber.Map{"count": count})
}
