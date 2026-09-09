package assistant

import (
	"testing"
	"time"
)

func TestAsstAnonTokens(t *testing.T) {
	secret := []byte("s3cret")
	tok := mintAnonWith(secret)
	if verifyAnonWith(tok, secret) == "" {
		t.Fatal("token should verify")
	}
	tampered := tok[:len(tok)-1] + "X"
	if tampered == tok {
		tampered = tok[:len(tok)-1] + "Y"
	}
	if verifyAnonWith(tampered, secret) != "" {
		t.Fatal("tampered token verified")
	}
	if verifyAnonWith("made.up", secret) != "" || verifyAnonWith("", secret) != "" {
		t.Fatal("garbage verified")
	}
	if verifyAnonWith(tok, []byte("other")) != "" {
		t.Fatal("wrong secret verified")
	}
}

func TestAsstAnonTokenExpires(t *testing.T) {
	secret := []byte("s")
	now := time.Now()
	fresh := mintAnonAt(secret, now)
	if verifyAnonAt(fresh, secret, now) == "" {
		t.Fatal("a fresh token verifies")
	}
	if verifyAnonAt(fresh, secret, now.Add(29*24*time.Hour)) == "" {
		t.Fatal("a token under a month old still verifies")
	}
	if verifyAnonAt(fresh, secret, now.Add(31*24*time.Hour)) != "" {
		t.Fatal("a month-old token lapses")
	}
	if verifyAnonAt(fresh, secret, now.Add(-2*time.Hour)) != "" {
		t.Fatal("a token from the future is not ours")
	}
}
