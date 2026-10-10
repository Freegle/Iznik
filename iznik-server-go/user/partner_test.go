package user

// Internal test package so coverage is tracked for partner.go and
// systemrole.go directly. These tests exercise only the guard-clause paths
// that return before any *gorm.DB method is called, so a nil db is safe to
// pass — any path that reached a real DB call would panic on a nil pointer.

import (
	"testing"
)

func TestValidatePartnerKey_EmptyKey(t *testing.T) {
	id, name, domain, err := ValidatePartnerKey(nil, "")
	if err == nil {
		t.Fatal("expected error for empty key, got nil")
	}
	if id != 0 || name != "" || domain != "" {
		t.Errorf("expected zero values, got id=%d name=%q domain=%q", id, name, domain)
	}
}

func TestFindByTNIdOrEmail_NoInputs(t *testing.T) {
	if got := FindByTNIdOrEmail(nil, 0, ""); got != 0 {
		t.Errorf("expected 0 when both tnuserid and email are empty, got %d", got)
	}
}

func TestFindPartnerOwnerForMessage_EmptyDomain(t *testing.T) {
	if got := FindPartnerOwnerForMessage(nil, "", 5); got != 0 {
		t.Errorf("expected 0 for empty domain, got %d", got)
	}
}

func TestFindPartnerOwnerForMessage_ZeroMsgID(t *testing.T) {
	if got := FindPartnerOwnerForMessage(nil, "example.com", 0); got != 0 {
		t.Errorf("expected 0 for zero msgID, got %d", got)
	}
}

func TestFindPartnerOwnerForMessage_BothEmpty(t *testing.T) {
	if got := FindPartnerOwnerForMessage(nil, "", 0); got != 0 {
		t.Errorf("expected 0 when both domain and msgID are zero, got %d", got)
	}
}

func TestCreatePartnerUser_EmptyEmail(t *testing.T) {
	id, err := CreatePartnerUser(nil, 0, "")
	if err == nil {
		t.Fatal("expected error for empty email, got nil")
	}
	if id != 0 {
		t.Errorf("expected id 0, got %d", id)
	}
}

func TestSyncSystemRole_ZeroUserID(t *testing.T) {
	// Must not panic even with a nil db - the userid==0 guard returns first.
	SyncSystemRole(nil, 0)
}
