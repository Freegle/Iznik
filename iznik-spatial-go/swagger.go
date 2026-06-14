package main

import "github.com/gofiber/fiber/v2"

// swaggerHTML renders the served /openapi.yaml with Swagger UI (loaded from a
// CDN), mirroring how the v2 Go API exposes its docs at /swagger/. The spec
// itself is served by the /openapi.yaml route in main.go.
const swaggerHTML = `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Freegle Spatial (KNN) API</title>
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

// registerSwaggerUI adds a browsable Swagger UI at /swagger/ for the public API.
func registerSwaggerUI(api *fiber.App) {
	api.Get("/swagger", func(c *fiber.Ctx) error {
		return c.Redirect("/swagger/", fiber.StatusFound)
	})
	api.Get("/swagger/", func(c *fiber.Ctx) error {
		c.Set("Content-Type", "text/html; charset=utf-8")
		return c.SendString(swaggerHTML)
	})
}
