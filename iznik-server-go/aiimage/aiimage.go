package aiimage

import (
	"bytes"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"image"
	"image/color"
	"image/jpeg"
	_ "image/png" // register PNG decoder
	"io"
	"net/http"
	"os"
	"path"
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
	UserID         uint64    `json:"userid"`
	Displayname    string    `json:"displayname"`
	Result         string    `json:"result"`
	ContainsPeople *int      `json:"containspeople"`
	Timestamp      time.Time `json:"timestamp"`
}

// ImageGenerator generates an AI image for the given item name and returns JPEG bytes.
// Replaced in tests to avoid real HTTP calls.
var ImageGenerator = generateImageWithCloudflare

// ImageUploader uploads image bytes to TUS and returns the externaluid.
// Replaced in tests to avoid real HTTP calls.
var ImageUploader = uploadToTUS

// buildImagePrompt constructs the AI image generation prompt for a given item name.
// Dark background + white line drawing is essential for the Freegle duotone effect:
// duotone maps dark→#0D3311 and white→white, so a dark background becomes Freegle green
// and white lines remain white — matching the PHP Pollinations prompt exactly.
func buildImagePrompt(name string) string {
	return "Product illustration: single isolated " + name + " centered on plain dark green background. " +
		"Style: friendly cartoon white line drawing, moderate shading, cute and quirky, UK audience. " +
		"The object sits alone on a simple surface or floats in space. " +
		"Simple illustration style, clean lines, single object only."
}

// CloudflareAPIBase is the base URL for the Cloudflare API. Overridable in tests.
var CloudflareAPIBase = "https://api.cloudflare.com"

// generateImageWithCloudflare calls the Cloudflare Workers AI API (Flux Schnell) to generate
// an image for the given item name and returns the raw PNG bytes.
func generateImageWithCloudflare(name string) ([]byte, error) {
	accountID := os.Getenv("CLOUDFLARE_ACCOUNT_ID")
	aiToken := os.Getenv("CLOUDFLARE_AI_TOKEN")

	if accountID == "" || aiToken == "" {
		return nil, fmt.Errorf("CLOUDFLARE_ACCOUNT_ID and CLOUDFLARE_AI_TOKEN must be set")
	}

	prompt := buildImagePrompt(name)

	reqBody, _ := json.Marshal(map[string]interface{}{
		"prompt":    prompt,
		"num_steps": 8,
		"width":     1024,
		"height":    1024,
	})

	apiURL := fmt.Sprintf(
		"%s/client/v4/accounts/%s/ai/run/@cf/black-forest-labs/flux-1-schnell",
		CloudflareAPIBase,
		accountID,
	)

	req, err := http.NewRequest("POST", apiURL, bytes.NewReader(reqBody))
	if err != nil {
		return nil, fmt.Errorf("failed to build Cloudflare AI request: %w", err)
	}
	req.Header.Set("Authorization", "Bearer "+aiToken)
	req.Header.Set("Content-Type", "application/json")

	client := &http.Client{Timeout: 90 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return nil, fmt.Errorf("Cloudflare AI request failed: %w", err)
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, fmt.Errorf("failed to read Cloudflare AI response: %w", err)
	}

	if resp.StatusCode != 200 {
		return nil, fmt.Errorf("Cloudflare AI returned status %d: %s", resp.StatusCode, string(body))
	}

	// Cloudflare Workers AI returns a JSON envelope with a base64-encoded image.
	var envelope struct {
		Result struct {
			Image string `json:"image"`
		} `json:"result"`
		Success bool     `json:"success"`
		Errors  []string `json:"errors"`
	}
	if err := json.Unmarshal(body, &envelope); err != nil {
		// If not JSON, assume raw binary image data.
		return body, nil
	}

	if !envelope.Success || envelope.Result.Image == "" {
		return nil, fmt.Errorf("Cloudflare AI returned no image: %v", envelope.Errors)
	}

	imageBytes, err := base64.StdEncoding.DecodeString(envelope.Result.Image)
	if err != nil {
		return nil, fmt.Errorf("failed to decode base64 image from Cloudflare AI: %w", err)
	}

	return imageBytes, nil
}

// applyDuotoneGreen decodes the image data (any format), applies the Freegle duotone
// effect (dark green #0D3311 to white), and returns JPEG-encoded bytes at quality 90.
func applyDuotoneGreen(data []byte) ([]byte, error) {
	img, _, err := image.Decode(bytes.NewReader(data))
	if err != nil {
		return nil, fmt.Errorf("failed to decode image for duotone: %w", err)
	}

	bounds := img.Bounds()
	dst := image.NewRGBA(bounds)

	// Freegle brand duotone: dark green (#0D3311) → white (#FFFFFF)
	const darkR, darkG, darkB = 13, 51, 17

	for y := bounds.Min.Y; y < bounds.Max.Y; y++ {
		for x := bounds.Min.X; x < bounds.Max.X; x++ {
			r, g, b, a := img.At(x, y).RGBA()
			// Convert to 8-bit.
			r8, g8, b8 := uint8(r>>8), uint8(g>>8), uint8(b>>8)
			// Luminance (grayscale).
			gray := 0.299*float64(r8) + 0.587*float64(g8) + 0.114*float64(b8)
			t := gray / 255.0
			nr := uint8(float64(darkR) + t*float64(255-darkR))
			ng := uint8(float64(darkG) + t*float64(255-darkG))
			nb := uint8(float64(darkB) + t*float64(255-darkB))
			dst.Set(x, y, color.RGBA{R: nr, G: ng, B: nb, A: uint8(a >> 8)})
		}
	}

	var buf bytes.Buffer
	if err := jpeg.Encode(&buf, dst, &jpeg.Options{Quality: 90}); err != nil {
		return nil, fmt.Errorf("failed to JPEG-encode duotone image: %w", err)
	}
	return buf.Bytes(), nil
}

// uploadToTUS uploads image bytes to the TUS server and returns the externaluid.
// The externaluid format is "freegletusd-{fileID}", matching the PHP Tus::upload() convention.
func uploadToTUS(data []byte, mime string) (string, error) {
	tusURL := os.Getenv("TUS_UPLOADER")
	if tusURL == "" {
		tusURL = "https://uploads.ilovefreegle.org:8080"
	}
	// Ensure trailing slash.
	if !strings.HasSuffix(tusURL, "/") {
		tusURL += "/"
	}

	fileLen := len(data)

	// Metadata values (base64 encoded per TUS spec).
	b64 := func(s string) string { return base64.StdEncoding.EncodeToString([]byte(s)) }
	metadata := fmt.Sprintf("relativePath %s,name %s,type %s,filetype %s,filename %s",
		"bnVsbA==", // base64("null")
		b64(mime),
		b64("image/jpeg"),
		b64("image/jpeg"),
		b64("image.jpg"),
	)

	// Step 1: Create the upload.
	createReq, err := http.NewRequest("POST", tusURL, nil)
	if err != nil {
		return "", fmt.Errorf("failed to build TUS create request: %w", err)
	}
	createReq.Header.Set("Tus-Resumable", "1.0.0")
	createReq.Header.Set("Content-Type", "application/offset+octet-stream")
	createReq.Header.Set("Upload-Length", strconv.Itoa(fileLen))
	createReq.Header.Set("Upload-Metadata", metadata)

	client := &http.Client{Timeout: 30 * time.Second}
	createResp, err := client.Do(createReq)
	if err != nil {
		return "", fmt.Errorf("TUS create request failed: %w", err)
	}
	createResp.Body.Close()

	if createResp.StatusCode != 201 {
		return "", fmt.Errorf("TUS create returned status %d", createResp.StatusCode)
	}

	location := createResp.Header.Get("Location")
	if location == "" {
		return "", fmt.Errorf("TUS create returned no Location header")
	}

	// Step 2: Upload the data.
	patchReq, err := http.NewRequest("PATCH", location, bytes.NewReader(data))
	if err != nil {
		return "", fmt.Errorf("failed to build TUS PATCH request: %w", err)
	}
	patchReq.Header.Set("Content-Type", "application/offset+octet-stream")
	patchReq.Header.Set("Tus-Resumable", "1.0.0")
	patchReq.Header.Set("Upload-Offset", "0")

	patchResp, err := client.Do(patchReq)
	if err != nil {
		return "", fmt.Errorf("TUS PATCH request failed: %w", err)
	}
	patchResp.Body.Close()

	if patchResp.StatusCode != 200 && patchResp.StatusCode != 204 {
		return "", fmt.Errorf("TUS PATCH returned status %d", patchResp.StatusCode)
	}

	// Derive externaluid: "freegletusd-{fileID}"
	fileID := path.Base(location)
	return "freegletusd-" + fileID, nil
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

// RegenerateRequest is the request body for the Regenerate endpoint.
type RegenerateRequest struct {
	Notes string `json:"notes"`
}

// Regenerate handles POST /api/admin/ai-images/:id/regenerate.
// Generates a new image using Cloudflare Workers AI (Flux Schnell), applies the Freegle
// duotone effect, uploads it to TUS, and returns the delivery URL as a preview.
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

	// Mark as regenerating while we generate.
	db.Exec("UPDATE ai_images SET status = 'regenerating' WHERE id = ?", id)

	// Generate image via Cloudflare Workers AI.
	imageData, err := ImageGenerator(name)
	if err != nil {
		db.Exec("UPDATE ai_images SET status = 'rejected' WHERE id = ?", id)
		return fiber.NewError(fiber.StatusServiceUnavailable, "Image generation failed: "+err.Error())
	}

	// Apply Freegle duotone (dark green to white).
	jpegData, err := applyDuotoneGreen(imageData)
	if err != nil {
		db.Exec("UPDATE ai_images SET status = 'rejected' WHERE id = ?", id)
		return fiber.NewError(fiber.StatusInternalServerError, "Image processing failed: "+err.Error())
	}

	// Upload to TUS to get a real externaluid.
	externaluid, err := ImageUploader(jpegData, "image/jpeg")
	if err != nil {
		db.Exec("UPDATE ai_images SET status = 'rejected' WHERE id = ?", id)
		return fiber.NewError(fiber.StatusInternalServerError, "Image upload failed: "+err.Error())
	}

	// Store the pending externaluid — not applied until admin clicks Accept.
	db.Exec("UPDATE ai_images SET pending_externaluid = ?, status = 'regenerating' WHERE id = ?", externaluid, id)

	return c.JSON(fiber.Map{
		"ret":         0,
		"preview_url": getDeliveryURL(externaluid),
	})
}

// AcceptRequest is the request body for the Accept endpoint.
type AcceptRequest struct {
	PendingExternaluid string `json:"pending_externaluid"`
}

// Accept handles POST /api/admin/ai-images/:id/accept.
// Accepts the pending_externaluid already stored from a prior Regenerate call,
// updates ai_images, resets votes, and applies the new externaluid to all
// messages_attachments that reference the old one.
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
		return fiber.NewError(fiber.StatusBadRequest, "No pending image to accept — regenerate first")
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
