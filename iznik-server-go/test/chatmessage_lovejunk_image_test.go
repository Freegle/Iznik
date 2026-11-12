package test

import (
	"bytes"
	json2 "encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/chat"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// loveJunkFixture makes a post for a LoveJunk user to reply to and the payload for the reply.
func loveJunkFixture(t *testing.T, name string) (posterID uint64, groupID uint64, msgID uint64, payload chat.ChatMessageLovejunk) {
	t.Helper()
	partnerKey := os.Getenv("LOVEJUNK_PARTNER_KEY")
	require.NotEmpty(t, partnerKey, "the test setup provides the LoveJunk partner key")

	prefix := uniquePrefix(name)
	groupID = CreateTestGroup(t, prefix)
	posterID = CreateTestUser(t, prefix+"_poster", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, posterID, groupID, utils.ROLE_MEMBER)
	msgID = CreateTestMessage(t, posterID, groupID, "Test Offer "+name, 55.9533, -3.1883)

	ljuserid := uint64(time.Now().UnixNano())
	firstname := "Love"
	lastname := "Junk"
	payload.Refmsgid = &msgID
	payload.Ljuserid = &ljuserid
	payload.Partnerkey = partnerKey
	payload.Firstname = &firstname
	payload.Lastname = &lastname
	payload.Message = "Is this still there?"

	return posterID, groupID, msgID, payload
}

func postLoveJunk(t *testing.T, payload chat.ChatMessageLovejunk) *http.Response {
	t.Helper()
	s, _ := json2.Marshal(payload)
	request := httptest.NewRequest("POST", "/api/chat/lovejunk", bytes.NewBuffer(s))
	request.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(request, 5000)
	require.NoError(t, err)

	return resp
}

// A LoveJunk reply with a photo is shown with the photo, as an in-app reply is: the message row carries the image id,
// which is what every read of a chat looks at. Linking the image to the message alone leaves the photo hidden.
func TestCreateChatMessageLoveJunkImageIsOnTheMessage(t *testing.T) {
	db := database.DBConn
	_, _, _, payload := loveJunkFixture(t, "ljimgrow")

	db.Exec("INSERT INTO chat_images (externaluid) VALUES (?)", "test-lj-image-row")
	var imageID uint64
	db.Raw("SELECT id FROM chat_images WHERE externaluid = 'test-lj-image-row' ORDER BY id DESC LIMIT 1").Scan(&imageID)
	require.NotZero(t, imageID)
	payload.Imageid = &imageID

	resp := postLoveJunk(t, payload)
	require.Equal(t, fiber.StatusOK, resp.StatusCode)
	var ret chat.ChatMessageLovejunkResponse
	json2.Unmarshal(rsp(resp), &ret)
	require.NotZero(t, ret.Id)

	var onMessage *uint64
	db.Raw("SELECT imageid FROM chat_messages WHERE id = ?", ret.Id).Scan(&onMessage)
	if assert.NotNil(t, onMessage, "the message carries its image id") {
		assert.Equal(t, imageID, *onMessage)
	}
}
