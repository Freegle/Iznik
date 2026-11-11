package test

import (
	"encoding/json"
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// A moderator can read a post that has been taken down, with its deleted time, so ModTools can
// show it and restore it. Everyone else gets not found.
func TestTakenDownPostIsReadableByAModeratorOnly(t *testing.T) {
	prefix := uniquePrefix("tdread")
	db := database.DBConn
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	_, modToken := CreateTestSession(t, modID)
	posterID := CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	otherID := CreateTestUser(t, prefix+"_other", utils.SYSTEMROLE_USER)
	_, otherToken := CreateTestSession(t, otherID)
	msgID := CreateTestMessage(t, posterID, prefix+" taken down", 52.2, -0.1)

	db.Exec("UPDATE messages SET deleted = NOW(), collection = ? WHERE id = ?", utils.COLLECTION_REJECTED, msgID)

	resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d?jwt=%s", msgID, modToken), nil))
	assert.NoError(t, err)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode, "a moderator can read a taken-down post")
	var msg map[string]interface{}
	assert.NoError(t, json.NewDecoder(resp.Body).Decode(&msg))
	assert.NotNil(t, msg["deleted"], "the take-down time travels with it")
	assert.Empty(t, msg["message"], "the raw source of a post that is down is not returned")

	resp, err = getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d?jwt=%s", msgID, otherToken), nil))
	assert.NoError(t, err)
	assert.Equal(t, fiber.StatusNotFound, resp.StatusCode, "another member cannot")
}

// A post that is deleted but not taken down, such as one the poster withdrew while pending, is
// not readable by a moderator.
func TestDeletedPostThatWasNotTakenDownIsNotReadableByAModerator(t *testing.T) {
	prefix := uniquePrefix("tdnot")
	db := database.DBConn
	modID := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	_, modToken := CreateTestSession(t, modID)
	posterID := CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	msgID := CreateTestMessage(t, posterID, prefix+" withdrawn", 52.2, -0.1)

	db.Exec("UPDATE messages SET deleted = NOW(), collection = ? WHERE id = ?", utils.COLLECTION_PENDING, msgID)

	resp, err := getApp().Test(httptest.NewRequest("GET", fmt.Sprintf("/api/message/%d?jwt=%s", msgID, modToken), nil))
	assert.NoError(t, err)
	assert.Equal(t, fiber.StatusNotFound, resp.StatusCode)
}
