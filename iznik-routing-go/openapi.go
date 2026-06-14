package main

import (
	_ "embed"

	"github.com/gofiber/fiber/v2"
)

// openapiSpec is the OpenAPI 3.0 definition for this service, embedded at build
// time so it is served without depending on a filesystem path.
//
//go:embed openapi.yaml
var openapiSpec []byte

// swaggerHTML renders the embedded spec with Swagger UI (loaded from a CDN),
// mirroring how the v2 Go API exposes its docs at /swagger/.
const swaggerHTML = `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Freegle Routing & Isochrone API</title>
  <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css"/>
</head>
<body>
  <div id="swagger-ui"></div>
  <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
  <script>
    window.onload = function () {
      window.ui = SwaggerUIBundle({ url: '/openapi.yaml', dom_id: '#swagger-ui' });
    };
  </script>
</body>
</html>`

// registerDocs wires the public OpenAPI spec and Swagger UI onto a Fiber app.
// These routes are registered outside the authenticated /v1 group so the docs
// are reachable without a JWT on both the internal and external listeners.
func registerDocs(app *fiber.App) {
	app.Get("/openapi.yaml", func(c *fiber.Ctx) error {
		c.Set("Content-Type", "application/yaml")
		return c.Send(openapiSpec)
	})
	app.Get("/swagger", func(c *fiber.Ctx) error {
		return c.Redirect("/swagger/", fiber.StatusFound)
	})
	app.Get("/swagger/", func(c *fiber.Ctx) error {
		c.Set("Content-Type", "text/html; charset=utf-8")
		return c.SendString(swaggerHTML)
	})
}
