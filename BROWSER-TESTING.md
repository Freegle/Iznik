# Browser Testing with Chrome DevTools MCP

## The Approach

Claude Code can control a browser to verify design changes visually. This creates a powerful iterative workflow:

1. **Make a change** to Vue/CSS code
2. **View the result** in the browser via MCP tools
3. **Spot issues** like overlaps, alignment problems, or unexpected visual changes
4. **Iterate** until the design looks right

This is particularly useful for layout modernization work where changes to one component can have unintended effects elsewhere.

## What Browser Testing Can Spot

Visual browser testing catches issues that code review alone misses:

- **Overlapping elements** - buttons covering text, modals clipping content
- **Alignment problems** - elements not vertically centered, inconsistent spacing
- **Responsive breakage** - layouts that work at one breakpoint but break at another
- **Missing styles** - elements that lost styling after refactoring
- **Unintended side effects** - changes to shared components affecting other pages
- **Text overflow** - long content breaking layouts
- **Z-index issues** - elements appearing behind others unexpectedly

## Watching Claude Work

When Claude uses browser tools, you can watch the browser window in real-time. This lets you:

- **Intervene early** if something looks wrong before Claude continues
- **Provide guidance** like "the button is too close to the edge" based on what you see
- **Spot issues Claude might miss** - you know the design intent better

The browser window stays visible throughout the session, so you're always in the loop.

## Two-Tab Comparison Technique

A useful pattern is to open two browser tabs side by side:

1. **Tab 1**: The page you're modifying (freegle-dev-live)
2. **Tab 2**: The production version or a reference page

This helps spot unexpected changes - if something looks different between tabs that shouldn't, you've found a regression.

Claude can switch between tabs using `list_pages` and `select_page` to compare before/after states.

## Example: Layout Modernization

When modernizing a page layout from Bootstrap grid to modern CSS:

1. Navigate to the page and take a screenshot
2. Make CSS changes (e.g., switch from `b-row`/`b-col` to flex/max-width)
3. Take another screenshot to compare
4. Check different viewport sizes for responsive issues
5. Look for elements that shifted unexpectedly
6. Iterate until the layout matches the design intent

---

## Setup and Technical Details

The following sections contain technical details for setting up browser testing. Click to expand.

<details>
<summary><strong>Prerequisites</strong></summary>

### Chrome with Remote Debugging

Start Chrome with remote debugging enabled. **Use WSL** (not Windows) so Chrome runs in the same environment as the MCP server:

```bash
google-chrome --remote-debugging-port=9222
```

Note: Running Chrome from Windows won't work because the MCP server runs in WSL and needs to connect to localhost:9222 within the WSL network.

### MCP Server Configuration

Add to `~/.claude.json` under the project's mcpServers:

```json
"chrome-devtools": {
  "type": "stdio",
  "command": "npx",
  "args": ["-y", "chrome-devtools-mcp@latest", "--browserUrl", "http://127.0.0.1:9222"]
}
```

Restart Claude Code after config changes.

### Development Container

Ensure freegle-dev-live is running:
```bash
docker-compose up -d freegle-dev-live
```

</details>

<details>
<summary><strong>Target URL and Test Credentials</strong></summary>

### Which URL to Use

Use **freegle-dev-live.localhost** for visual testing:
- Connects to production APIs (real data)
- Runs in development mode (hot reload)
- Test user credentials work here

Avoid freegle-dev-local for visual testing - it uses the local test database which may lack sample data.

### Test Credentials

Stored in `.env` (not committed to git):
- `TEST_USER_EMAIL` - Test account email
- `TEST_USER_PASSWORD` - Test account password

</details>

<details>
<summary><strong>Core MCP Tools</strong></summary>

| Tool | Purpose |
|------|---------|
| `list_pages` | Show all open browser tabs |
| `select_page` | Switch to a specific tab |
| `navigate_page` | Go to URL or back/forward/reload |
| `take_snapshot` | Get accessibility tree (text representation with element UIDs) |
| `take_screenshot` | Capture visual screenshot |
| `click` | Click an element by UID |
| `fill` | Type into input/textarea |
| `hover` | Hover over element |
| `press_key` | Press keyboard keys |
| `wait_for` | Wait for text to appear |
| `evaluate_script` | Run JavaScript in the page |
| `resize_page` | Change viewport dimensions |

</details>

<details>
<summary><strong>Login Flow</strong></summary>

For testing logged-in features, Claude can log in using the test credentials:

1. Navigate to `http://freegle-dev-live.localhost/browse`
2. Take a snapshot to get element UIDs
3. Click "Log in" link (switches from Join to Login form)
4. Fill email and password fields using `fill` or `evaluate_script`
5. Click the "Log in" button
6. Wait for page content with `wait_for`

Using `evaluate_script` to set form values is more reliable than the `fill` tool for reactive Vue forms.

</details>

<details>
<summary><strong>Debugging Styles</strong></summary>

### Check Computed Styles

Use `evaluate_script` to examine actual CSS values:

```javascript
() => {
  const el = document.querySelector('.your-selector');
  const style = getComputedStyle(el);
  return { display: style.display, height: style.height };
}
```

### Inject Test CSS

Quickly test CSS fixes without waiting for hot reload:

```javascript
() => {
  const style = document.createElement('style');
  style.textContent = '.my-class { height: 100% !important; }';
  document.head.appendChild(style);
  return 'CSS injected';
}
```

</details>

<details>
<summary><strong>Viewport Sizes</strong></summary>

Use `resize_page` to test different Bootstrap breakpoints:

| Breakpoint | Size | Description |
|------------|------|-------------|
| xs | 375x667 | Mobile |
| sm | 576x800 | Large mobile |
| md | 768x1024 | Tablet portrait |
| md-lg | 820x1180 | iPad Air (good for 2-column testing) |
| lg | 992x768 | Tablet landscape |
| xl | 1200x900 | Desktop |

</details>

<details>
<summary><strong>Troubleshooting</strong></summary>

### MCP not connecting
1. Verify Chrome is running with `--remote-debugging-port=9222`
2. Test connection: `curl http://localhost:9222/json/version`
3. Restart Claude Code after config changes

### Page not loading
1. Check container is running: `docker-compose ps`
2. Verify URL resolves: `curl -I http://freegle-dev-live.localhost/`

### Viewport resize not working
Some browser configurations restrict `resize_page`. Alternatives:
- Manually resize the Chrome window
- Use Chrome DevTools Device Toolbar (F12 > device icon)
- Use `evaluate_script` to open a new window with specific dimensions

</details>

---

## Code Conventions

When making design changes, follow these conventions to avoid common issues.

<details>
<summary><strong>Vue Component Conventions</strong></summary>

### Icons
Use `v-icon` with Font Awesome icons:
```vue
<v-icon icon="info-circle" />
```

### SCSS Imports
```scss
@import 'assets/css/_color-vars.scss';
```

### SCSS Comments
Never use `//` comments - use `/* */` instead (Vite compilation errors).

### Link Whitespace
No trailing/leading whitespace inside link tags:
```vue
<!-- Good -->
<ExternalLink href="...">Police</ExternalLink>

<!-- Bad - visible space -->
<ExternalLink href="...">
  Police </ExternalLink>
```

### After Changes
Always run eslint:
```bash
npx eslint --fix pages/your-file.vue
```

</details>

<details>
<summary><strong>Accessibility</strong></summary>

### Text Contrast Requirements
- **4.5:1** minimum for normal text
- **3:1** minimum for large text (18pt+ or 14pt bold)

### Common Contrast Problems
- White text on light green backgrounds
- Light gray text on white backgrounds
- Using `opacity` on text

Use browser DevTools accessibility panel to verify contrast.

</details>

<details>
<summary><strong>House Style</strong></summary>

- Put full stops at the end of sentences.
- Use consistent capitalization.
- Check for duplicate titles (navbar + page h1 showing same text).

</details>
