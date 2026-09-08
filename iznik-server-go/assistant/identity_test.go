package assistant

import "testing"

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
