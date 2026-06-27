package message

import (
	"errors"
	"fmt"
	"os"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
	"github.com/golang-jwt/jwt/v4"
)

const helperTokenPurpose = "helper_delegate"

// HelperTokenClaims are the JWT claims carried by a concierge helper delegate token.
type HelperTokenClaims struct {
	Purpose     string `json:"purpose"`
	OfferUserid uint64 `json:"offeruserid"`
	Helpermsgid uint64 `json:"helpermsgid"`
}

// MintHelperToken mints a short-lived JWT that lets the Freegle Helper (concierge)
// act on behalf of the offerer (offerUserid) for a single bulk-offer message
// (helpermsgid). The token is signed with JWT_SECRET, carries purpose=helper_delegate,
// and expires after ttl (use 24*time.Hour for typical concierge sessions).
//
// The returned string is a standard Bearer JWT suitable for the Authorization header.
func MintHelperToken(offerUserid uint64, helpermsgid uint64, ttl time.Duration) (string, error) {
	secret := os.Getenv("JWT_SECRET")
	if secret == "" {
		return "", errors.New("JWT_SECRET not set")
	}

	tok := jwt.NewWithClaims(jwt.SigningMethodHS256, jwt.MapClaims{
		"purpose":     helperTokenPurpose,
		"offeruserid": fmt.Sprintf("%d", offerUserid),
		"helpermsgid": fmt.Sprintf("%d", helpermsgid),
		"exp":         time.Now().Add(ttl).Unix(),
	})

	return tok.SignedString([]byte(secret))
}

// ParseHelperToken parses and validates a helper-delegate JWT, returning the
// embedded claims. Returns an error if the token is malformed, expired, or
// carries a different purpose.
func ParseHelperToken(tokenString string) (*HelperTokenClaims, error) {
	secret := os.Getenv("JWT_SECRET")
	if secret == "" {
		return nil, errors.New("JWT_SECRET not set")
	}

	tok, err := jwt.Parse(tokenString, func(t *jwt.Token) (interface{}, error) {
		if _, ok := t.Method.(*jwt.SigningMethodHMAC); !ok {
			return nil, fmt.Errorf("unexpected signing method: %v", t.Header["alg"])
		}
		return []byte(secret), nil
	})
	if err != nil {
		return nil, err
	}
	if !tok.Valid {
		return nil, errors.New("invalid helper token")
	}

	claims, ok := tok.Claims.(jwt.MapClaims)
	if !ok {
		return nil, errors.New("invalid helper token claims")
	}

	purpose, _ := claims["purpose"].(string)
	if purpose != helperTokenPurpose {
		return nil, errors.New("token is not a helper_delegate token")
	}

	offerUseridStr, _ := claims["offeruserid"].(string)
	helpermsgidStr, _ := claims["helpermsgid"].(string)

	var offerUserid, helpermsgid uint64
	fmt.Sscanf(offerUseridStr, "%d", &offerUserid)
	fmt.Sscanf(helpermsgidStr, "%d", &helpermsgid)

	if offerUserid == 0 || helpermsgid == 0 {
		return nil, errors.New("helper token missing offeruserid or helpermsgid")
	}

	return &HelperTokenClaims{
		Purpose:     purpose,
		OfferUserid: offerUserid,
		Helpermsgid: helpermsgid,
	}, nil
}

// ValidateHelperTokenForMsg checks that the bearer token string is a valid
// helper-delegate token for the specified msgid, and returns the offerer's
// user ID so the handler can act on their behalf.
//
// Usage in a handler:
//
//	offerUserid, err := message.ValidateHelperTokenForMsg(tokenStr, msgid)
//	if err != nil {
//	    return fiber.NewError(fiber.StatusForbidden, err.Error())
//	}
//
// The handler should call this when myid == 0 (no regular JWT) and a helper
// bearer is present — the caller decides whether to extract the token from
// the Authorization header or a separate header.
//
// TODO: wire this into the auth middleware so handlers that accept a helper
// token do not need per-handler extraction; for now it is an explicit
// per-handler check to keep auth middleware changes scoped and safe.
func ValidateHelperTokenForMsg(tokenString string, msgid uint64) (offerUserid uint64, err error) {
	claims, err := ParseHelperToken(tokenString)
	if err != nil {
		return 0, fmt.Errorf("invalid helper token: %w", err)
	}
	if claims.Helpermsgid != msgid {
		return 0, fmt.Errorf("helper token is scoped to message %d, not %d", claims.Helpermsgid, msgid)
	}
	return claims.OfferUserid, nil
}

// MintHelperTokenForMessage is the HTTP handler for POST /message/:id/helpertoken.
// Only the message owner may call this. Returns a short-lived (24 h) helper-delegate
// JWT that the Freegle Helper (AI concierge) can use to act on behalf of the offerer
// for bulk-offer state changes on this message only.
//
// @Summary Mint a concierge helper delegate token
// @Description The offerer calls this to delegate state-change authority to the Freegle Helper for a single bulk-offer message. The returned token is scoped to this message and expires after 24 hours.
// @Tags message
// @Produce json
// @Security BearerAuth
// @Param id path integer true "Message ID"
// @Success 200 {object} map[string]interface{}
// @Failure 401 {object} fiber.Error
// @Failure 403 {object} fiber.Error
// @Failure 404 {object} fiber.Error
// @Router /message/{id}/helpertoken [post]
func MintHelperTokenForMessage(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	msgid, err := parseUint64Param(c, "id")
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid message id")
	}

	db := database.DBConn
	var fromuser uint64
	db.Raw("SELECT fromuser FROM messages WHERE id = ? AND deleted IS NULL", msgid).Scan(&fromuser)
	if fromuser == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}
	if fromuser != myid {
		return fiber.NewError(fiber.StatusForbidden, "Not your message")
	}

	tok, err := MintHelperToken(myid, msgid, 24*time.Hour)
	if err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Could not mint token")
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "token": tok})
}

// parseUint64Param extracts a named uint64 path parameter.
func parseUint64Param(c *fiber.Ctx, name string) (uint64, error) {
	s := c.Params(name)
	if s == "" {
		return 0, fmt.Errorf("missing param %s", name)
	}
	var v uint64
	_, err := fmt.Sscanf(s, "%d", &v)
	return v, err
}
