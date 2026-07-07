# AI Support Helper

This document explains the architecture of the AI Support Helper feature in ModTools.

## Overview

The AI Support Helper allows support staff to investigate user issues by asking questions in natural language. It uses Claude with codebase access to understand how Freegle works, while preserving user privacy by keeping PII in the browser.

## Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│                     ModTools Browser                              │
│  ┌────────────────────────────────────────────────────────────┐  │
│  │ Chat interface shows transparent dialogue:                  │  │
│  │                                                             │  │
│  │ Container asks: "count_errors(userid=12345, timerange=1h)" │  │
│  │ Browser returns: 7                                          │  │
│  │                                                             │  │
│  │ Container asks: "has_recent_activity(userid=12345)"        │  │
│  │ Browser returns: true                                       │  │
│  │                                                             │  │
│  │ Answer: "User 12345 has 7 errors in the last hour..."      │  │
│  └────────────────────────────────────────────────────────────┘  │
│                              │                                    │
│  Has user's JWT auth         │ HTTP polling                       │
│  Executes real API calls     │ (fact queries only)                │
│  Returns ONLY sanitized data ▼                                    │
└──────────────────────────────┼────────────────────────────────────┘
                               │
┌──────────────────────────────▼────────────────────────────────────┐
│                Docker Container: freegle-claude-agent             │
│  ┌─────────────────────────────────────────────────────────────┐  │
│  │ Claude Agent SDK                                             │  │
│  │ - Has /app/codebase (checkout of iznik-nuxt3, iznik-server) │  │
│  │ - Can READ code to understand how Freegle works             │  │
│  │ - Requests fact queries via HTTP                            │  │
│  │ - Receives ONLY counts/booleans/summaries (no PII)         │  │
│  └─────────────────────────────────────────────────────────────┘  │
│                               │                                    │
│  Uses Claude Code CLI auth    │ Calls Anthropic API               │
│  (~/.claude mounted from host)│ (only sees facts + codebase)      │
└───────────────────────────────┼────────────────────────────────────┘
                                ▼
                        Anthropic Cloud
                   (sees codebase context +
                    sanitized facts only,
                    NEVER sees user PII)
```

## Privacy Design

### What Anthropic Sees

- Codebase context (public on GitHub anyway)
- User's support question
- Sanitized facts: counts, yes/no answers, error codes, group names

### What Anthropic Never Sees

- Email addresses
- Chat message content
- User names
- IP addresses
- Any personally identifiable information (PII)

## How It Works

1. **User opens AI Support Helper tab** in ModTools support page.
2. **Browser connects** to Docker container via HTTP polling (`http://ai-support-helper.localhost`).
3. **User asks question** (e.g., "Why is user 12345 having problems?").
4. **Container receives question**, uses Claude Agent SDK with codebase context.
5. **Claude reads code** to understand how Freegle works.
6. **Claude requests fact queries** (displayed transparently in UI).
7. **Browser executes** API calls with user's JWT, returns only sanitized answers.
8. **UI shows each exchange** - what container asked, what browser returned.
9. **Claude analyzes** and provides answer.
10. **User can continue the conversation** with follow-up questions.

## Available Fact Queries

The container can only request these predefined queries:

| Query | Parameters | Returns | Description |
|-------|------------|---------|-------------|
| `count_api_calls` | userid, timerange | number | Count API calls for a user |
| `count_errors` | userid, timerange | number | Count errors for a user |
| `count_logins` | userid, timerange | number | Count successful logins |
| `has_recent_activity` | userid, timerange | boolean | Check if user has recent activity |
| `has_errors` | userid, timerange | boolean | Check if user has errors |
| `get_error_summary` | userid, timerange | array | Summary of errors by status code |
| `get_user_role` | userid | string | User's system role |
| `find_user_by_email` | email | object | Find user ID by email (no PII returned) |
| `get_group_info` | groupid | object | Public group information |
| `search_groups` | search | array | Search groups by name |

### Timerange Format

- `1h` - last 1 hour
- `24h` - last 24 hours
- `7d` - last 7 days
- `30d` - last 30 days

## Suggesting New Queries

If Claude needs information that isn't available through existing fact queries, it will suggest new query types. These suggestions appear in the UI and help iteratively improve the available queries.

Suggested queries include:
- A clear name for the proposed query
- What parameters it would need
- What it would return (counts, booleans, or sanitized summaries only)
- Why it would be useful for support investigations

## Authentication

### Caller authentication (who may use it)

`POST /api/log-analysis` requires the caller to be an authenticated Freegle
**moderator, support or admin**. ModTools forwards the logged-in user's JWT as
`Authorization: Bearer <jwt>`; the container validates it against the Go API
(`GET ${FREEGLE_API_URL}/api/session?jwt=…`) and rejects (403) anyone whose
`systemrole` is not Moderator/Support/Admin. Having `ANTHROPIC_API_KEY` set on
the server is **not** authorisation — the endpoint analyses production logs and
incurs Anthropic API cost, so it must not be open to unauthenticated callers.
Set `FREEGLE_API_URL` (default `http://apiv2.localhost:8192`) per environment.

### Claude/Anthropic authentication (how it calls Claude)

The container uses Claude Code CLI authentication:

1. **On host machine**: Run `claude` command once to authenticate.
2. **Session persists** in `~/.claude/` directory (typically weeks).
3. **Container mounts** `~/.claude:/root/.claude:ro` to share auth.
4. **If session expires**: Container returns auth error, UI shows friendly message.

This uses the Claude Max subscription - no additional API costs.

### Re-authenticating

When the authentication expires, the UI displays:

> Claude authentication has expired. Please ask an administrator to run 'claude' on the server to re-authenticate.

To re-authenticate:
```bash
claude
```
Follow the browser authentication flow.

## Files

### Docker Container

- `claude-agent-sdk/Dockerfile` - Container setup
- `claude-agent-sdk/package.json` - Dependencies
- `claude-agent-sdk/server.js` - HTTP polling server
- `claude-agent-sdk/agent.js` - Claude Agent SDK wrapper

### ModTools Component

- `modtools/components/ModSupportAIAssistant.vue` - Chat interface

### Docker Compose

The ai-support-helper service is defined in `docker-compose.yml`:

```yaml
ai-support-helper:
  build:
    context: ./claude-agent-sdk
  container_name: freegle-ai-support-helper
  ports:
    - "8083:3000"
  volumes:
    - ~/.claude:/root/.claude:ro
  labels:
    - "traefik.enable=true"
    - "traefik.http.routers.ai-support-helper.rule=Host(`ai-support-helper.localhost`)"
```

The container clones its own copy of the Freegle codebase at build time (from GitHub) and updates it every 30 minutes via `git pull`. This keeps the codebase isolated from the host system.

## Security Summary

1. **Access Control**: Only Support/Admin users see AI Support Helper tab.
2. **Data Isolation**: PII stays in browser, only sanitized facts go to container.
3. **Transparency**: All exchanges visible in chat UI.
4. **User Control**: Stop button to halt at any time.
5. **Auth Sharing**: Host's Claude CLI auth mounted read-only.
6. **Codebase Access**: Read-only for system knowledge only.

## Example Questions

- "Why is user 12345 having problems?"
- "What errors is this user seeing?"
- "How many logins did user 67890 have today?"
- "Is this user a mod or admin?"
- "What groups match 'Cambridge'?"
