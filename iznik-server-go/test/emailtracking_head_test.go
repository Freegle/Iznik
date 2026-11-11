package test

import (
	"encoding/base64"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/emailtracking"
	"github.com/stretchr/testify/assert"
)

// A HEAD request is a link checker or a mail scanner probing the link, never a person reading the
// email. Fiber answers HEAD on every GET route, so without a guard the tracker counted each probe as
// an open and a click: 12% of tracker hits in a six hour sample on 16 Oct 2026 were HEADs.
func TestEmailTrackingHeadClickNotRecorded(t *testing.T) {
	tracking := createTestTrackingRecord(t)
	defer cleanupTestTracking(t, tracking.TrackingID)

	destinationURL := getTestUserSite() + "/give"
	encodedURL := base64.StdEncoding.EncodeToString([]byte(destinationURL))

	req := httptest.NewRequest("HEAD", "/e/d/r/"+tracking.TrackingID+"?url="+encodedURL+"&p=cta_button&a=cta", nil)
	resp, err := getApp().Test(req, -1)

	assert.NoError(t, err)
	// The probe still learns where the link goes.
	assert.Equal(t, http.StatusFound, resp.StatusCode)
	assert.Equal(t, destinationURL, resp.Header.Get("Location"))

	// Nothing was recorded against the email.
	db := database.DBConn
	var updated emailtracking.EmailTracking
	db.Where("tracking_id = ?", tracking.TrackingID).First(&updated)
	assert.Nil(t, updated.OpenedAt)
	assert.Nil(t, updated.ClickedAt)
	assert.Equal(t, uint16(0), updated.LinksClicked)

	var clicks []emailtracking.EmailTrackingClick
	db.Where("email_tracking_id = ?", updated.ID).Find(&clicks)
	assert.Equal(t, 0, len(clicks))
}

func TestEmailTrackingHeadPixelAnswersWithoutRecording(t *testing.T) {
	tracking := createTestTrackingRecord(t)
	defer cleanupTestTracking(t, tracking.TrackingID)

	req := httptest.NewRequest("HEAD", "/e/d/p/"+tracking.TrackingID, nil)
	resp, err := getApp().Test(req, -1)

	assert.NoError(t, err)
	assert.Equal(t, http.StatusOK, resp.StatusCode)
	assert.Equal(t, "image/gif", resp.Header.Get("Content-Type"))
}
