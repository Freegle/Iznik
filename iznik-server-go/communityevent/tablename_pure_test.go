package communityevent

import (
	"testing"
)

func TestCommunityEventImageTableName(t *testing.T) {
	if got := (CommunityEventImage{}).TableName(); got != "communityevents_images" {
		t.Errorf("CommunityEventImage.TableName() = %q, want \"communityevents_images\"", got)
	}
}
