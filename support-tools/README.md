# Pseudonymized Log Analysis MCP

Privacy-preserving AI log analysis system for Freegle support staff and volunteers.

## Overview

This system allows support volunteers to query logs using natural language while ensuring no PII (Personally Identifiable Information) is ever sent to Anthropic/Claude. The architecture uses pseudonymization tokens that are meaningless without the key, which is kept securely within Freegle's infrastructure.

## Architecture

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Frontend (Browser)                                                          │
│  - User selects a Freegler to investigate                                   │
│  - User enters natural language query                                       │
│  - Receives response with real PII (de-tokenized)                           │
└────────────────────────────────┬────────────────────────────────────────────┘
                                 │
                                 ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  Container 0: Query Sanitizer (port 8084)                                   │
│  - Extracts PII from user query                                             │
│  - Creates consistent tokens (EMAIL_a8f3c2)                                 │
│  - Sends mapping to Pseudonymizer                                           │
│  - Returns pseudonymized query + mapping to frontend                        │
└────────────────────────────────┬────────────────────────────────────────────┘
                                 │
          ┌──────────────────────┼──────────────────────┐
          │                      │                      │
          ▼                      ▼                      ▼
┌─────────────────┐   ┌───────────────────┐   ┌────────────────────────┐
│ Claude (API)    │   │ Pseudonymized     │   │ Mapping to             │
│                 │   │ query to Claude   │   │ Pseudonymizer (direct) │
│ Only sees tokens│   │                   │   │                        │
│ Never real PII  │   │                   │   │                        │
└────────┬────────┘   └───────────────────┘   └────────────────────────┘
         │
         ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  Container 2: MCP Interface (internal only)                                 │
│  - Stateless proxy (NO key, NO mapping)                                     │
│  - Forwards tool calls to Pseudonymizer                                     │
│  - If compromised: can only issue pseudonymized queries                     │
└────────────────────────────────┬────────────────────────────────────────────┘
                                 │
                                 ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  Container 3: Pseudonymizer + Loki Access                                   │
│  - HAS the key (token → real value mapping)                                 │
│  - Translates tokens to real values for Loki queries                        │
│  - Pseudonymizes Loki results before returning                              │
│  - Writes audit log of all queries                                          │
└────────────────────────────────┬────────────────────────────────────────────┘
                                 │
                                 ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│  Loki                                                                        │
│  - Existing log aggregation service                                          │
│  - Contains real PII in logs                                                 │
└─────────────────────────────────────────────────────────────────────────────┘
```

## Container Security Summary

| Container | Has Key? | Has Mapping? | Can Reach | If Compromised |
|-----------|----------|--------------|-----------|----------------|
| 0: Query Sanitizer | No | Creates it | C3 | Can see user queries (trusted staff only) |
| 2: MCP Interface | No | No | C3 only | Can only issue pseudonymized queries |
| 3: Pseudonymizer | Yes | Yes | Loki only | Full access - requires multiple escapes |

## MCP Tools

### loki_query

Query application logs from Loki. Returns pseudonymized log entries.

**Input Schema:**
```json
{
  "type": "object",
  "properties": {
    "sessionId": {
      "type": "string",
      "description": "Session ID from query sanitization. Required."
    },
    "query": {
      "type": "string",
      "description": "LogQL query (e.g., {app=\"freegle\"} |= \"error\")"
    },
    "start": {
      "type": "string",
      "description": "Start time - relative (1h, 24h, 7d) or ISO 8601. Default: 1h"
    },
    "end": {
      "type": "string",
      "description": "End time. Default: now"
    },
    "limit": {
      "type": "integer",
      "description": "Maximum results. Default: 100"
    }
  },
  "required": ["sessionId", "query"]
}
```

## API Endpoints

### Query Sanitizer (Container 0)

**POST /sanitize**
Sanitize a user query by extracting and tokenizing PII.

Request:
```json
{
  "query": "Why is john.smith@gmail.com getting errors?",
  "knownPii": {
    "email": "john.smith@gmail.com",
    "displayname": "John Smith",
    "userid": 12345
  },
  "userId": 12345
}
```

Response:
```json
{
  "pseudonymizedQuery": "Why is EMAIL_a8f3c2 getting errors?",
  "sessionId": "sess_7f3d2a",
  "localMapping": {
    "EMAIL_a8f3c2": "john.smith@gmail.com"
  },
  "detectedPii": null
}
```

**POST /scan**
Scan query for PII without sanitizing (for preview/warning).

### MCP Interface (Container 2)

**GET /tools**
List available MCP tools.

**POST /tools/loki_query**
Execute a Loki query (with pseudonymization).

### Pseudonymizer (Container 3)

**POST /register-mapping**
Register token mappings for a session (called by Container 0).

**POST /query**
Execute a Loki query with token translation and result pseudonymization.

**GET /mapping/:sessionId**
Get the reverse mapping for frontend de-tokenization.

## Token Persistence

Tokens are persistent across sessions to enable correlation:

```
Session 1 (Monday):
  "john.smith@gmail.com" → EMAIL_a8f3c2

Session 2 (Tuesday):
  "john.smith@gmail.com" → EMAIL_a8f3c2 (same token!)

Claude can correlate across sessions because the token is consistent.
```

## GDPR/ICO Compliance

From the [ICO Pseudonymisation Guidance](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/data-sharing/anonymisation/pseudonymisation/):

> "Pseudonymised data in the hands of an organisation that does not have access to the key – or any other means of identifying those individuals – may be considered anonymous data."

With cryptographically random tokens and proper key separation:
- **Freegle** (has key): Data is personal data, full GDPR applies
- **Anthropic** (no key): Data is effectively anonymous - tokens are meaningless

## Audit Logging

All MCP queries are logged to `/var/log/mcp-audit/YYYY-MM-DD.jsonl`:

```json
{
  "timestamp": "2024-01-15T10:30:00Z",
  "sessionId": "sess_7f3d2a",
  "operation": "loki_query",
  "request": {
    "query": "{app=\"freegle\"} | json | email=\"EMAIL_a8f3c2\"",
    "start": "1h",
    "limit": 100
  },
  "lokiQuery": {
    "query": "{app=\"freegle\"} | json | email=\"john.smith@gmail.com\"",
    "resultCount": 5
  },
  "response": {
    "pseudonymizedEntries": 5,
    "tokensUsed": ["EMAIL_a8f3c2", "IP_7d3f2a"]
  },
  "durationMs": 245
}
```

## Docker Compose

Services are defined in `docker-compose.yml`:

- `mcp-query-sanitizer` (port 8084, `mcp-sanitizer.localhost`)
- `mcp-interface` (internal only)
- `mcp-pseudonymizer` (internal only)

Isolated networks prevent direct container-to-container access except through defined paths.
