package modconfig

import (
	"testing"
)

func TestModConfigTableName(t *testing.T) {
	if got := (ModConfig{}).TableName(); got != "mod_configs" {
		t.Errorf("ModConfig.TableName() = %q, want \"mod_configs\"", got)
	}
}

func TestStdMsgTableName(t *testing.T) {
	if got := (StdMsg{}).TableName(); got != "mod_stdmsgs" {
		t.Errorf("StdMsg.TableName() = %q, want \"mod_stdmsgs\"", got)
	}
}
