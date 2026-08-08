package session

import (
	"crypto/rand"
	"crypto/rsa"
	"encoding/base64"
	"encoding/json"
	"testing"

	"github.com/stretchr/testify/assert"
)

// ---------------------------------------------------------------------------
// Endpoint getters — pure env-var-with-default lookups, no network involved.
// ---------------------------------------------------------------------------

func TestGetAppleJWKSURL(t *testing.T) {
	t.Run("default when unset", func(t *testing.T) {
		t.Setenv("APPLE_JWKS_URL", "")
		assert.Equal(t, "https://appleid.apple.com/auth/keys", getAppleJWKSURL())
	})
	t.Run("env override", func(t *testing.T) {
		t.Setenv("APPLE_JWKS_URL", "https://example.test/apple-jwks")
		assert.Equal(t, "https://example.test/apple-jwks", getAppleJWKSURL())
	})
}

func TestGetFacebookGraphURL(t *testing.T) {
	t.Run("default when unset", func(t *testing.T) {
		t.Setenv("FACEBOOK_GRAPH_URL", "")
		assert.Equal(t, "https://graph.facebook.com", getFacebookGraphURL())
	})
	t.Run("env override", func(t *testing.T) {
		t.Setenv("FACEBOOK_GRAPH_URL", "https://example.test/graph")
		assert.Equal(t, "https://example.test/graph", getFacebookGraphURL())
	})
}

func TestGetFacebookJWKSURL(t *testing.T) {
	t.Run("default when unset", func(t *testing.T) {
		t.Setenv("FACEBOOK_JWKS_URL", "")
		assert.Equal(t, "https://limited.facebook.com/.well-known/oauth/openid/jwks/", getFacebookJWKSURL())
	})
	t.Run("env override", func(t *testing.T) {
		t.Setenv("FACEBOOK_JWKS_URL", "https://example.test/fb-jwks")
		assert.Equal(t, "https://example.test/fb-jwks", getFacebookJWKSURL())
	})
}

func TestGetGoogleTokenInfoURL(t *testing.T) {
	t.Run("default when both unset", func(t *testing.T) {
		t.Setenv("GOOGLE_TOKENINFO_URL", "")
		saved := googleTokenInfoURL
		googleTokenInfoURL = ""
		defer func() { googleTokenInfoURL = saved }()
		assert.Equal(t, "https://oauth2.googleapis.com/tokeninfo", getGoogleTokenInfoURL())
	})
	t.Run("package var used when env unset", func(t *testing.T) {
		t.Setenv("GOOGLE_TOKENINFO_URL", "")
		saved := googleTokenInfoURL
		googleTokenInfoURL = "https://example.test/pkgvar"
		defer func() { googleTokenInfoURL = saved }()
		assert.Equal(t, "https://example.test/pkgvar", getGoogleTokenInfoURL())
	})
	t.Run("env takes priority over package var", func(t *testing.T) {
		t.Setenv("GOOGLE_TOKENINFO_URL", "https://example.test/envvar")
		saved := googleTokenInfoURL
		googleTokenInfoURL = "https://example.test/pkgvar"
		defer func() { googleTokenInfoURL = saved }()
		assert.Equal(t, "https://example.test/envvar", getGoogleTokenInfoURL())
	})
}

// ---------------------------------------------------------------------------
// parseJWKS — pure JSON/base64/RSA-assembly parsing, no network.
// ---------------------------------------------------------------------------

func makeJWK(t *testing.T, kid string) jwksKey {
	t.Helper()
	key, err := rsa.GenerateKey(rand.Reader, 512) // small key: fast, fine for parse-only tests
	assert.NoError(t, err)
	nBytes := key.PublicKey.N.Bytes()
	eBytes := []byte{byte(key.PublicKey.E >> 16), byte(key.PublicKey.E >> 8), byte(key.PublicKey.E)}
	// Trim leading zero bytes from the exponent encoding, mirroring real JWKS output.
	for len(eBytes) > 1 && eBytes[0] == 0 {
		eBytes = eBytes[1:]
	}
	return jwksKey{
		Kty: "RSA",
		Kid: kid,
		Use: "sig",
		N:   base64.RawURLEncoding.EncodeToString(nBytes),
		E:   base64.RawURLEncoding.EncodeToString(eBytes),
		Alg: "RS256",
	}
}

func TestParseJWKS(t *testing.T) {
	t.Run("single valid RSA key", func(t *testing.T) {
		k := makeJWK(t, "key-1")
		body, err := json.Marshal(jwksResponse{Keys: []jwksKey{k}})
		assert.NoError(t, err)

		keys, err := parseJWKS(body)
		assert.NoError(t, err)
		assert.Len(t, keys, 1)
		assert.Contains(t, keys, "key-1")
		assert.NotNil(t, keys["key-1"])
	})

	t.Run("multiple valid RSA keys", func(t *testing.T) {
		k1 := makeJWK(t, "key-1")
		k2 := makeJWK(t, "key-2")
		body, err := json.Marshal(jwksResponse{Keys: []jwksKey{k1, k2}})
		assert.NoError(t, err)

		keys, err := parseJWKS(body)
		assert.NoError(t, err)
		assert.Len(t, keys, 2)
		assert.Contains(t, keys, "key-1")
		assert.Contains(t, keys, "key-2")
	})

	t.Run("non-RSA key type is skipped", func(t *testing.T) {
		rsaKey := makeJWK(t, "rsa-key")
		ecKey := jwksKey{Kty: "EC", Kid: "ec-key", N: "irrelevant", E: "irrelevant"}
		body, err := json.Marshal(jwksResponse{Keys: []jwksKey{ecKey, rsaKey}})
		assert.NoError(t, err)

		keys, err := parseJWKS(body)
		assert.NoError(t, err)
		assert.Len(t, keys, 1)
		assert.Contains(t, keys, "rsa-key")
		assert.NotContains(t, keys, "ec-key")
	})

	t.Run("malformed JSON returns error", func(t *testing.T) {
		keys, err := parseJWKS([]byte("not json"))
		assert.Error(t, err)
		assert.Nil(t, keys)
	})

	t.Run("empty keys array returns no RSA keys error", func(t *testing.T) {
		body, err := json.Marshal(jwksResponse{Keys: []jwksKey{}})
		assert.NoError(t, err)

		keys, err := parseJWKS(body)
		assert.Error(t, err)
		assert.Nil(t, keys)
	})

	t.Run("only non-RSA keys returns no RSA keys error", func(t *testing.T) {
		ecKey := jwksKey{Kty: "EC", Kid: "ec-key", N: "irrelevant", E: "irrelevant"}
		body, err := json.Marshal(jwksResponse{Keys: []jwksKey{ecKey}})
		assert.NoError(t, err)

		keys, err := parseJWKS(body)
		assert.Error(t, err)
		assert.Nil(t, keys)
	})

	t.Run("invalid base64 modulus returns error", func(t *testing.T) {
		k := jwksKey{Kty: "RSA", Kid: "bad-n", N: "not-valid-base64!!!", E: "AQAB"}
		body, err := json.Marshal(jwksResponse{Keys: []jwksKey{k}})
		assert.NoError(t, err)

		keys, err := parseJWKS(body)
		assert.Error(t, err)
		assert.Nil(t, keys)
	})

	t.Run("invalid base64 exponent returns error", func(t *testing.T) {
		k := jwksKey{Kty: "RSA", Kid: "bad-e", N: base64.RawURLEncoding.EncodeToString([]byte{1, 2, 3}), E: "not-valid-base64!!!"}
		body, err := json.Marshal(jwksResponse{Keys: []jwksKey{k}})
		assert.NoError(t, err)

		keys, err := parseJWKS(body)
		assert.Error(t, err)
		assert.Nil(t, keys)
	})

	t.Run("empty body returns error", func(t *testing.T) {
		keys, err := parseJWKS([]byte(""))
		assert.Error(t, err)
		assert.Nil(t, keys)
	})
}
