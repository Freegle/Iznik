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
		ctx.Request().Header.Set("X-Freegle-Case", header)
	}
	return caseSuffix(ctx)
}

func TestCaseSuffixKeepsOnlyDigits(t *testing.T) {
	assert.Equal(t, "", suffixFor("", false))
	assert.Equal(t, " case=42", suffixFor("42", true))
	assert.Equal(t, " case=12", suffixFor("12\nFAKE line", true))
	assert.Equal(t, "", suffixFor("abc", true))
	assert.Equal(t, 20+len(" case="), len(suffixFor("123456789012345678901234567890", true)))
}
