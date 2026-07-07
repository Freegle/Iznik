package session

import (
	"fmt"
	"io"
	stdlog "log"
	"net/http"
	"os"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/golang-jwt/jwt/v4"
)

// getAppleJWKSURL returns Apple's public key endpoint.
// Overridable via env var for testing.
func getAppleJWKSURL() string {
	if u := os.Getenv("APPLE_JWKS_URL"); u != "" {
		return u
	}
	return "https://appleid.apple.com/auth/keys"
}

// handleAppleLogin verifies an Apple identity token (signed JWT) and logs the user in.
//
// Apple Sign In with Apple returns a JWT (identityToken) signed with Apple's RSA private key.
// We verify the signature using Apple's public JWKS, then extract sub (Apple user ID) and email.
// No secret is required — verification uses Apple's public keys only.
//
// Parity with PHP Apple.php: verifies signature and expiry; does not check the aud claim
// (PHP's ASDecoder library is not configured with a client_id in the existing codebase).
func handleAppleLogin(c *fiber.Ctx, identityToken, appleUserID, email, firstName, lastName string) error {
	if identityToken == "" {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{
			"ret":    2,
			"status": "Missing Apple identity token",
		})
	}

	// Fetch Apple's JWKS.
	client := &http.Client{Timeout: 5 * time.Second}
	resp, err := client.Get(getAppleJWKSURL())
	if err != nil {
		stdlog.Printf("Apple JWKS fetch failed: %v", err)
		return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{
			"ret":    2,
			"status": "Failed to fetch Apple public keys",
		})
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{
			"ret":    2,
			"status": "Failed to read Apple JWKS",
		})
	}

	keys, err := parseJWKS(body)
	if err != nil {
		stdlog.Printf("Apple JWKS parse failed: %v", err)
		return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{
			"ret":    2,
			"status": "Failed to parse Apple public keys",
		})
	}

	// Verify the identity token JWT.
	token, err := jwt.Parse(identityToken, func(token *jwt.Token) (interface{}, error) {
		if _, ok := token.Method.(*jwt.SigningMethodRSA); !ok {
			return nil, fmt.Errorf("unexpected signing method: %v", token.Header["alg"])
		}

		kid, ok := token.Header["kid"].(string)
		if !ok {
			return nil, fmt.Errorf("missing kid in JWT header")
		}

		key, exists := keys[kid]
		if !exists {
			return nil, fmt.Errorf("unknown kid: %s", kid)
		}

		return key, nil
	})

	if err != nil {
		stdlog.Printf("Apple identity token verification failed: %v", err)
		return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{
			"ret":    2,
			"status": "Invalid Apple identity token",
		})
	}

	claims, ok := token.Claims.(jwt.MapClaims)
	if !ok || !token.Valid {
		return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{
			"ret":    2,
			"status": "Invalid Apple identity token claims",
		})
	}

	// Extract sub (stable Apple user ID) — prefer from JWT, fall back to client-supplied value.
	sub, _ := claims["sub"].(string)
	if sub == "" {
		sub = appleUserID
	}
	if sub == "" {
		return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{
			"ret":    2,
			"status": "Apple identity token missing subject",
		})
	}

	// Email from JWT claims; Apple only includes it on the first sign-in.
	// If the JWT has no email, fall back to the value the client sent (also only first sign-in).
	tokenEmail, _ := claims["email"].(string)
	if tokenEmail != "" {
		email = tokenEmail
	}

	fullName := firstName + " " + lastName
	if firstName == "" {
		fullName = lastName
	}
	if lastName == "" {
		fullName = firstName
	}

	userID, err := socialMatchOrCreate(
		utils.LOGIN_TYPE_APPLE,
		sub,
		email,
		firstName,
		lastName,
		fullName,
	)
	if err != nil {
		stdlog.Printf("Apple login socialMatchOrCreate failed: %v", err)
		return c.Status(fiber.StatusForbidden).JSON(fiber.Map{
			"ret":    3,
			"status": fmt.Sprintf("Login failed: %v", err),
		})
	}

	// Apple does not provide profile pictures — no saveProfileImage call needed.

	persistent, jwtString, err := auth.CreateSessionAndJWT(userID)
	if err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create session")
	}

	return c.JSON(fiber.Map{
		"ret":        0,
		"status":     "Success",
		"persistent": persistent,
		"jwt":        jwtString,
	})
}
