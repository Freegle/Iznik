package test

import (
	"crypto/rsa"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"math/big"
	"net/http"
	"net/http/httptest"
)

func newAppleJWKSServer(privateKey *rsa.PrivateKey, kid string) *httptest.Server {
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		nBytes := privateKey.PublicKey.N.Bytes()
		eBytes := big.NewInt(int64(privateKey.PublicKey.E)).Bytes()
		jwks := map[string]interface{}{
			"keys": []map[string]string{
				{
					"kty": "RSA",
					"kid": kid,
					"use": "sig",
					"alg": "RS256",
					"n":   base64.RawURLEncoding.EncodeToString(nBytes),
					"e":   base64.RawURLEncoding.EncodeToString(eBytes),
				},
			},
		}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(jwks)
	}))
}

// postAppleSession sends an Apple login request to the test session endpoint.
func postAppleSession(identityToken, appleUserID, email, givenName, familyName string) *http.Response {
	creds := map[string]string{
		"identityToken":     identityToken,
		"user":              appleUserID,
		"email":             email,
		"givenName":         givenName,
		"familyName":        familyName,
		"authorizationCode": "fake-auth-code",
	}
	credsJSON, _ := json.Marshal(creds)
	body := fmt.Sprintf(`{"applelogin":true,"applecredentials":%s}`, string(credsJSON))
	return postSession(body)
}
