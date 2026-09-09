package assistant

import (
	"crypto/hmac"
	"crypto/rand"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/base64"
	"os"
	"strconv"
	"strings"
	"time"
)

// anonMaxAge is how long an anonymous token stays good. A stale one gets a fresh identity.
const anonMaxAge = 30 * 24 * time.Hour

// Anonymous visitors get a signed token minted here and echoed back by the browser in
// the X-Assistant-Anon header. Nothing the browser invents is trusted: the token has to
// verify against our secret.

func anonSecret() []byte {
	s := os.Getenv("ASSISTANT_ANON_SECRET")
	if s == "" {
		s = os.Getenv("JWT_SECRET")
	}
	return []byte(s)
}

func signAnon(id string, secret []byte) string {
	m := hmac.New(sha256.New, secret)
	m.Write([]byte(id))
	return base64.RawURLEncoding.EncodeToString(m.Sum(nil))
}

// MintAnon returns a new signed anonymous token.
func MintAnon() string {
	return mintAnonWith(anonSecret())
}

func mintAnonWith(secret []byte) string {
	return mintAnonAt(secret, time.Now())
}

// The id carries when it was issued (base 36 seconds) so that expiry needs no lookup.
func mintAnonAt(secret []byte, at time.Time) string {
	buf := make([]byte, 12)
	_, _ = rand.Read(buf)
	id := base64.RawURLEncoding.EncodeToString(buf) + "-" + strconv.FormatInt(at.Unix(), 36)
	return id + "." + signAnon(id, secret)
}

// VerifyAnon returns the anonymous id if the token was signed by us, else "".
func VerifyAnon(token string) string {
	return verifyAnonWith(token, anonSecret())
}

func verifyAnonWith(token string, secret []byte) string {
	return verifyAnonAt(token, secret, time.Now())
}

func verifyAnonAt(token string, secret []byte, now time.Time) string {
	i := strings.IndexByte(token, '.')
	if i <= 0 || i == len(token)-1 {
		return ""
	}
	id, sig := token[:i], token[i+1:]
	want := signAnon(id, secret)
	if len(sig) != len(want) || subtle.ConstantTimeCompare([]byte(sig), []byte(want)) != 1 {
		return ""
	}
	j := strings.LastIndexByte(id, '-')
	if j < 0 {
		return ""
	}
	issued, err := strconv.ParseInt(id[j+1:], 36, 64)
	if err != nil {
		return ""
	}
	age := now.Sub(time.Unix(issued, 0))
	if age > anonMaxAge || age < -time.Hour {
		return ""
	}
	return id
}
