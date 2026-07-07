---
name: launch-chrome
description: "Use when asked to use the Chrome MCP, start browser testing, or launch Chrome. Starts Chrome with GPU disabled, no first-run screens, and verifies the MCP connection."
---

# Launch Chrome for Browser Testing

## Step 1: Kill any existing Chrome instance

```bash
pkill -f "google-chrome.*remote-debugging-port=9222" 2>/dev/null || true
sleep 1
```

## Step 2: Launch Chrome

```bash
DISPLAY=:0 google-chrome \
  --disable-gpu \
  --remote-debugging-port=9222 \
  --user-data-dir=/tmp/chrome-debug \
  --no-first-run \
  --no-default-browser-check \
  --disable-sync \
  --disable-extensions \
  &
sleep 3
```

The flags:
- `--disable-gpu` — required in WSL to avoid GPU/graphics errors
- `--no-first-run` — skips "make Chrome the default browser" screen
- `--no-default-browser-check` — suppresses default browser prompt
- `--disable-sync` — skips Google sign-in screen
- `--disable-extensions` — faster startup, no extension prompts

## Step 3: Verify connection

```bash
curl -s http://localhost:9222/json/version | grep '"Browser"'
```

Should return a line with the Chrome version. If it fails, wait 2s and retry once.

## Step 4: Open first page with MCP

Use `new_page` (not `navigate_page` — there's no selected page yet):

```
mcp__chrome-devtools__new_page url="http://freegle-dev-live.localhost" timeout=30000
```

If the page times out on first load, use `list_pages` to check if the tab opened, then `select_page` and `navigate_page` with `reload`.

## Notes

- Always use `freegle-dev-live.localhost` for visual testing (live data, hot reload)
- Test credentials are in `.env` as `TEST_USER_EMAIL` / `TEST_USER_PASSWORD`
- Use `take_snapshot` (not `take_screenshot`) for inspecting elements — faster and searchable
