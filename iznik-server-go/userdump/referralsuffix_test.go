package userdump

import (
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
	"github.com/valyala/fasthttp"
)

func suffixFor(header string, set bool) string {
	app := fiber.New()
	ctx := app.AcquireCtx(&fasthttp.RequestCtx{})
	defer app.ReleaseCtx(ctx)
	if set {
		ctx.Request().Header.Set("X-Freegle-Referral", header)
	}
	return referralSuffix(ctx)
}

func TestCaseSuffixKeepsOnlyDigits(t *testing.T) {
	assert.Equal(t, "", suffixFor("", false))
	assert.Equal(t, " referral=42", suffixFor("42", true))
	assert.Equal(t, " referral=12", suffixFor("12\nFAKE line", true))
	assert.Equal(t, "", suffixFor("abc", true))
	assert.Equal(t, 20+len(" referral="), len(suffixFor("123456789012345678901234567890", true)))
}
