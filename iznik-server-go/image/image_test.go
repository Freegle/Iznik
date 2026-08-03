package image

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

func TestIsTruthyNil(t *testing.T) {
	assert.False(t, isTruthy(nil))
}

func TestIsTruthyBool(t *testing.T) {
	assert.True(t, isTruthy(true))
	assert.False(t, isTruthy(false))
}

func TestIsTruthyFloat64(t *testing.T) {
	// JSON numbers decode to float64 — non-zero is truthy.
	assert.True(t, isTruthy(float64(1)))
	assert.True(t, isTruthy(float64(-1)))
	assert.False(t, isTruthy(float64(0)))
}

func TestIsTruthyString(t *testing.T) {
	assert.True(t, isTruthy("yes"))
	assert.True(t, isTruthy("true"))
	assert.False(t, isTruthy(""))
	assert.False(t, isTruthy("0"))
	assert.False(t, isTruthy("false"))
}

func TestIsTruthyUnknownType(t *testing.T) {
	// Unsupported types fall through to the default false — an int (not float64)
	// exercises this path.
	assert.False(t, isTruthy(42))
	assert.False(t, isTruthy([]string{"a"}))
}

func TestToUint64Nil(t *testing.T) {
	assert.Equal(t, uint64(0), toUint64(nil))
}

func TestToUint64Float(t *testing.T) {
	// JSON numbers arrive as float64 — round-trip via uint64 cast.
	assert.Equal(t, uint64(12345), toUint64(float64(12345)))
	assert.Equal(t, uint64(0), toUint64(float64(0)))
}

func TestToUint64BoolReturnsZero(t *testing.T) {
	// A bool flag is not a parent ID — should always resolve to 0.
	assert.Equal(t, uint64(0), toUint64(true))
	assert.Equal(t, uint64(0), toUint64(false))
}

func TestToUint64UnknownType(t *testing.T) {
	assert.Equal(t, uint64(0), toUint64("123"))
	assert.Equal(t, uint64(0), toUint64([]int{1}))
}

func TestResolveTypePrefersImgType(t *testing.T) {
	// Explicit ImgType wins over everything else.
	req := &PostRequest{ImgType: "Group", CommunityEvent: true}
	assert.Equal(t, "Group", req.resolveType())
}

func TestResolveTypeFallsBackToType(t *testing.T) {
	// When ImgType is empty, the alternative `type` field is used.
	req := &PostRequest{Type: "Newsletter"}
	assert.Equal(t, "Newsletter", req.resolveType())
}

func TestResolveTypeCommunityEventFlag(t *testing.T) {
	// Rotation calls set boolean type flags rather than ImgType/Type.
	req := &PostRequest{CommunityEvent: true}
	assert.Equal(t, "CommunityEvent", req.resolveType())
}

func TestResolveTypeVolunteeringFlag(t *testing.T) {
	req := &PostRequest{Volunteering: true}
	assert.Equal(t, "Volunteering", req.resolveType())
}

func TestResolveTypeUserFlag(t *testing.T) {
	req := &PostRequest{UserID: true}
	assert.Equal(t, "User", req.resolveType())
}

func TestResolveTypeStoryFlag(t *testing.T) {
	req := &PostRequest{Story: true}
	assert.Equal(t, "Story", req.resolveType())
}

func TestResolveTypeNoticeboardFlag(t *testing.T) {
	req := &PostRequest{Noticeboard: true}
	assert.Equal(t, "Noticeboard", req.resolveType())
}

func TestResolveTypeDefaultIsMessage(t *testing.T) {
	// No ImgType/Type/flags → default "Message".
	req := &PostRequest{}
	assert.Equal(t, "Message", req.resolveType())
}

func TestResolveParentIDMessage(t *testing.T) {
	req := &PostRequest{MsgID: 42}
	assert.Equal(t, uint64(42), req.resolveParentID())
}

func TestResolveParentIDGroup(t *testing.T) {
	req := &PostRequest{ImgType: "Group", GroupID: 7}
	assert.Equal(t, uint64(7), req.resolveParentID())
}

func TestResolveParentIDNewsletter(t *testing.T) {
	req := &PostRequest{ImgType: "Newsletter", Newsletter: 99}
	assert.Equal(t, uint64(99), req.resolveParentID())
}

func TestResolveParentIDCommunityEventFromNumber(t *testing.T) {
	// When CommunityEvent is a parent ID (number), toUint64 extracts it.
	req := &PostRequest{ImgType: "CommunityEvent", CommunityEvent: float64(33)}
	assert.Equal(t, uint64(33), req.resolveParentID())
}

func TestResolveParentIDVolunteeringFromNumber(t *testing.T) {
	req := &PostRequest{ImgType: "Volunteering", Volunteering: float64(44)}
	assert.Equal(t, uint64(44), req.resolveParentID())
}

func TestResolveParentIDChatMessage(t *testing.T) {
	req := &PostRequest{ImgType: "ChatMessage", ChatMessage: 55}
	assert.Equal(t, uint64(55), req.resolveParentID())
}

func TestResolveParentIDUserFromNumber(t *testing.T) {
	req := &PostRequest{ImgType: "User", UserID: float64(66)}
	assert.Equal(t, uint64(66), req.resolveParentID())
}

func TestResolveParentIDNewsfeed(t *testing.T) {
	req := &PostRequest{ImgType: "Newsfeed", Newsfeed: 77}
	assert.Equal(t, uint64(77), req.resolveParentID())
}

func TestResolveParentIDStoryFromNumber(t *testing.T) {
	req := &PostRequest{ImgType: "Story", Story: float64(88)}
	assert.Equal(t, uint64(88), req.resolveParentID())
}

func TestResolveParentIDNoticeboardFromNumber(t *testing.T) {
	req := &PostRequest{ImgType: "Noticeboard", Noticeboard: float64(111)}
	assert.Equal(t, uint64(111), req.resolveParentID())
}

func TestResolveParentIDUnknownTypeFallsBackToMsgID(t *testing.T) {
	// Unknown imgtype strings hit the default branch and return MsgID.
	req := &PostRequest{ImgType: "Unknown-Type-XYZ", MsgID: 1234}
	assert.Equal(t, uint64(1234), req.resolveParentID())
}

func TestResolveParentIDBoolFlagGivesZero(t *testing.T) {
	// Rotation with a bool type flag means the parent ID isn't in the payload —
	// resolveParentID must return 0 (not panic).
	req := &PostRequest{Story: true}
	assert.Equal(t, uint64(0), req.resolveParentID())
}

func TestTypeConfigsContainsKnownTypes(t *testing.T) {
	// Regression guard: each imgtype string used by callers must have a config.
	expected := []string{"Message", "Group", "Newsletter", "CommunityEvent",
		"Volunteering", "ChatMessage", "User", "Newsfeed", "Story", "Noticeboard"}
	for _, name := range expected {
		_, ok := typeConfigs[name]
		assert.True(t, ok, "typeConfigs missing entry for %s", name)
	}
}

func TestTypeConfigsMessageHasNoContentType(t *testing.T) {
	// messages_attachments is the only table without a contenttype column —
	// the INSERT path in doCreate branches on this flag.
	cfg := typeConfigs["Message"]
	assert.Equal(t, "messages_attachments", cfg.Table)
	assert.Equal(t, "msgid", cfg.IDColumn)
	assert.False(t, cfg.HasContentType)
}

func TestTypeConfigsOtherTablesHaveContentType(t *testing.T) {
	// All tables except messages_attachments must set HasContentType=true so the
	// INSERT statement includes the NOT NULL contenttype column.
	for name, cfg := range typeConfigs {
		if name == "Message" {
			continue
		}
		assert.True(t, cfg.HasContentType, "%s should have HasContentType=true", name)
	}
}
