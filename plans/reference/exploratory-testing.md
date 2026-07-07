# Exploratory Testing Plan

This document outlines a process for AI-driven exploratory testing of the Freegle site. The goal is to systematically browse the site, take screenshots, and identify layout issues, UX confusion, and visual bugs.

## Quick Start

Ask Claude: "Do exploratory testing on /browse" (or any route).

Claude will use the skill at `~/.claude/skills/exploratory-testing.md` to:
1. Navigate to the route using Chrome DevTools MCP
2. Take screenshots and analyze them visually
3. Record observations about what's actually visible
4. Interact with elements and document findings
5. Generate a video with thinking sidebar

## Overview

Exploratory testing differs from automated E2E tests - it's about discovering unexpected issues by browsing like a real user would, not verifying specific flows work.

### Tools
- **Chrome DevTools MCP** - For browser control and screenshots
- **Claude's visual analysis** - For analyzing screenshots and identifying issues
- **exploratory-recorder.js** - For generating videos with thinking sidebar
- **TodoWrite** - For tracking test progress

### Target URLs
- `http://freegle-dev-local.localhost/` - Development site (fast, for iteration)
- `http://freegle-prod-local.localhost/` - Production build (for final verification)

## How to Run Exploratory Testing

Use the skill at `~/.claude/skills/exploratory-testing.md` which guides Claude through:

1. **Setup** - Create output directory, initialize thinking log
2. **Interactive Loop**:
   - Take screenshot with Chrome DevTools MCP
   - View screenshot with Read tool and analyze visually
   - Record real observations (what you actually see, not pre-scripted)
   - Decide and take next action (click, navigate, etc.)
   - Repeat
3. **Generate Video** - Run `node scripts/exploratory-recorder.js generate-video <dir>`

### Key Principle: Real Observations

Claude must actually look at each screenshot and respond to what's visible:
- If there's a login modal blocking content, say so
- If elements are broken or missing, document it
- Never use placeholder text like "Checking X" without saying if X works

## Test Matrix

### Auth States
1. **Logged Out** - Anonymous visitor experience
2. **Logged In** - Authenticated user experience

### Viewports
- **Mobile**: 375x667 (iPhone SE size)
- **Tablet**: 768x1024 (iPad)
- **Desktop**: 1280x800

### Priority Pages
| Page | Logged Out | Logged In |
|------|------------|-----------|
| Homepage | High | High |
| Browse | High | High |
| Message View | High | High |
| Give Flow | N/A | High |
| My Messages | N/A | High |

## Video Output

The `scripts/exploratory-recorder.js` script generates videos with:
- **Left panel**: Browser screenshots (scaled to fit 1280x720)
- **Right sidebar**: Thinking observations with timestamps

### Output Structure
```
/tmp/exploratory-<timestamp>/
├── screenshots/           # Numbered PNG files (frame_000000.png, etc.)
├── thoughts.json          # Array of {timestamp, text} observations
├── thinking.log           # Human-readable log
└── exploratory-test.mp4   # Generated video
```

### Generating Video
```bash
node scripts/exploratory-recorder.js generate-video /tmp/exploratory-<timestamp>
```

## Issue Categories

When issues are found, categorize them:

1. **Blocker** - Cannot proceed (e.g., login modal with no close button)
2. **Critical** - Site broken, can't complete basic tasks
3. **Major** - Significant UX confusion or layout problems
4. **Minor** - Small visual issues, inconsistencies

## Example Session

```
[0s]   Navigating to /browse
[2s]   BLOCKER: Login modal appears, blocking browse content
[4s]   Modal shows Facebook, Google, email signup options
[6s]   No visible close button - must authenticate to continue
[8s]   Finding: /browse requires login, no guest browsing available
```

## Notes

- Always use test accounts for authenticated testing
- Compare mobile and desktop for each key page
- Check both logged-in and logged-out states
- Pay attention to edge cases (long text, missing data, etc.)
- Document findings immediately when observed
