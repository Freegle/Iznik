package main

import (
	"errors"
	"os"
	"strconv"

	"github.com/golang-jwt/jwt/v4"
	"github.com/gofiber/fiber/v2"
)

// jwtAuthMiddleware validates the JWT from ?jwt= or Authorization header.
// Checks the session exists in MySQL. Returns 401 on failure.
func jwtAuthMiddleware() fiber.Handler {
	return func(c *fiber.Ctx) error {
		tokenString := c.Query("jwt")
		if tokenString == "" {
			tokenString = c.Get("Authorization")
		}
		if tokenString == "" {
			return fiber.NewError(fiber.StatusUnauthorized, "authentication required")
		}
		// Strip surrounding quotes if present.
		if len(tokenString) > 1 && tokenString[0] == '"' {
			tokenString = tokenString[1:]
		}
		if len(tokenString) > 0 && tokenString[len(tokenString)-1] == '"' {
			tokenString = tokenString[:len(tokenString)-1]
		}

		secret := os.Getenv("JWT_SECRET")
		token, err := jwt.Parse(tokenString, func(t *jwt.Token) (interface{}, error) {
			if _, ok := t.Method.(*jwt.SigningMethodHMAC); !ok {
				return nil, errors.New("unexpected signing method")
			}
			return []byte(secret), nil
		})
		if err != nil || !token.Valid {
			return fiber.NewError(fiber.StatusUnauthorized, "invalid token")
		}

		claims, ok := token.Claims.(jwt.MapClaims)
		if !ok {
			return fiber.NewError(fiber.StatusUnauthorized, "invalid claims")
		}

		idStr, _ := claims["id"].(string)
		sessionIDStr, _ := claims["sessionid"].(string)
		userID, _ := strconv.ParseUint(idStr, 10, 64)
		sessionID, _ := strconv.ParseUint(sessionIDStr, 10, 64)
		if userID == 0 || sessionID == 0 {
			return fiber.NewError(fiber.StatusUnauthorized, "invalid claims")
		}

		if groupsDB != nil {
			var found int
			row := groupsDB.QueryRow(
				"SELECT 1 FROM sessions INNER JOIN users ON users.id = sessions.userid WHERE sessions.id = ? AND users.id = ? LIMIT 1",
				sessionID, userID,
			)
			if err := row.Scan(&found); err != nil || found != 1 {
				return fiber.NewError(fiber.StatusUnauthorized, "session not found")
			}
		}

		c.Locals("userID", userID)
		return c.Next()
	}
}
