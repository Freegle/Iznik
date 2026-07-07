# Pseudonymized Log Analysis MCP Server

## Overview

Design for a privacy-preserving AI log analysis system that allows Claude to query Loki logs without exposing PII, using cryptographic pseudonymization with defense-in-depth container isolation.

## Background Research

### GDPR/ICO Position on Pseudonymization

From [ICO Pseudonymisation Guidance](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/data-sharing/anonymisation/pseudonymisation/):

> "Pseudonymised data in the hands of an organisation that does not have access to the key – or any other means of identifying those individuals – **may be considered anonymous data**."

Key points:
- Data is **personal data** for the key holder
- Data is **potentially anonymous** for parties without the key
- Under [EDPB Guidelines 01/2025](https://www.edpb.europa.eu/system/files/2025-01/edpb_guidelines_202501_pseudonymisation_en.pdf), key separation is a core requirement

### The "Motivated Intruder" Test

The [ICO's motivated intruder test](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/data-sharing/anonymisation/how-do-we-ensure-anonymisation-is-effective/) asks: could a reasonably competent person with standard resources re-identify the data?

For cryptographically random tokens without the key:
- **Brute force**: Not feasible (UUID = 2^122 possibilities)
- **Rainbow tables**: Not applicable (tokens are random, not hashed)
- **Linkage attacks**: Minimal (random tokens have no correlation to external data)
- **Inference**: Possible for behavioral patterns, but not identity

**Conclusion**: Properly implemented cryptographic tokenization meets ICO's "reasonably unlikely to re-identify" standard for parties without the key.

### Previous Attempt: ModTools AI Support Helper

The existing AI support tool in ModTools (`iznik-nuxt3-modtools/components/ModSupportAIAssistant.vue`) used a **fact query** approach:
- Browser executes predefined queries with user's JWT
- Returns only aggregated data (counts, booleans, error codes)
- Claude never sees raw log data

**Why it didn't work well:**
- Too restrictive - couldn't explore logs naturally
- Required predicting all useful queries in advance
- Lost the ability for Claude to notice patterns in raw data

### Security Limitations of Simple Approaches

| Approach | Vulnerability |
|----------|---------------|
| Hashing user IDs | Domain too small - rainbow table attack |
| Sequential tokens (USER_001) | Reveals ordering, frequency analysis |
| Single container with key | Sandbox escape exposes key |
| Instruction-based ("don't read key") | No enforcement |

## Threat Model

### What we're protecting against

**Primary threat**: PII appearing in Anthropic's logs/training data
- Emails, user IDs, IP addresses, locations
- Should be cryptographically impossible to reverse without our key

**Secondary threat**: Claude attempting to bypass pseudonymization
- Using curl/wget to query Loki directly
- Reading configuration files to obtain the key
- Social engineering via tool misuse

### What we're NOT protecting against

- Attacker who has compromised our infrastructure and obtained the key
  - At that point they can access the database directly anyway
- Nation-state level attacks
- Zero-day container escapes combined with network pivoting

## Architecture: Three-Tier Container Isolation

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Loki Server Host                                                            │
│                                                                              │
│  ┌────────────────────┐   ┌────────────────────┐   ┌────────────────────┐  │
│  │   Container 1      │   │   Container 2      │   │   Container 3      │  │
│  │   CLAUDE CODE      │   │   MCP INTERFACE    │   │   PSEUDONYMIZER    │  │
│  │                    │   │                    │   │                    │  │
│  │ - Sandboxed        │──▶│ - Stateless proxy  │──▶│ - Has the key      │  │
│  │ - No Bash          │   │ - NO key           │   │ - Queries Loki     │  │
│  │ - No network tools │   │ - Validates format │   │ - Pseudonymizes    │  │
│  │ - Read-only FS     │   │ - Forwards only    │   │ - Returns to C2    │  │
│  │                    │   │                    │   │                    │  │
│  │ network: isolated  │   │ network: middle    │   │ network: backend   │  │
│  │ can reach: C2 only │   │ can reach: C3 only │   │ can reach: Loki    │  │
│  └────────────────────┘   └────────────────────┘   └────────────────────┘  │
│           │                        │                        │              │
│           │                        │                        ▼              │
│           │                        │               ┌────────────────┐      │
│           │                        │               │     LOKI       │      │
│           │                        │               │  (port 3100)   │      │
│           │                        │               └────────────────┘      │
│           │                        │                                       │
│           ▼                        │                                       │
│  ┌────────────────────┐           │                                       │
│  │   External API     │◀──────────┘                                       │
│  │   (questions in,   │                                                   │
│  │   answers out)     │                                                   │
│  └────────────────────┘                                                   │
│           │                                                                │
└───────────┼────────────────────────────────────────────────────────────────┘
            │
            ▼
     ┌──────────────┐
     │   Browser    │
     │  (ModTools)  │
     └──────────────┘
```

### Container 1: Claude Code (Sandboxed)

**Purpose**: Run Claude Code with MCP tools only

**Security measures**:
```yaml
# docker-compose.yml
claude-sandbox:
  image: anthropic/claude-code:latest  # or custom image
  read_only: true
  security_opt:
    - no-new-privileges:true
    - seccomp:seccomp-claude.json
  cap_drop:
    - ALL
  networks:
    - claude-isolated
  environment:
    - CLAUDE_ALLOWED_TOOLS=mcp_loki_query  # Only MCP tools
    - CLAUDE_DISALLOWED_TOOLS=Bash,Read,Write,Glob,Grep,WebFetch,WebSearch
  tmpfs:
    - /tmp:noexec,nosuid,size=100m
```

**Network isolation**:
```yaml
networks:
  claude-isolated:
    internal: true  # No external access
    driver_opts:
      com.docker.network.bridge.enable_ip_masquerade: "false"
```

**What Claude CAN do**:
- Use MCP tools to query logs (pseudonymized)
- Think and respond

**What Claude CANNOT do**:
- Run bash commands
- Read/write files
- Make network requests (curl, wget, etc.)
- Access the internet
- Reach any container except Container 2

### Container 2: MCP Interface (No Key)

**Purpose**: Stateless proxy that validates and forwards requests

**Security measures**:
- No pseudonymization key
- No direct Loki access
- Only accepts well-formed MCP requests
- Only forwards to Container 3

```python
# mcp_interface/server.py
from fastapi import FastAPI, HTTPException
import httpx

app = FastAPI()

PSEUDONYMIZER_URL = "http://pseudonymizer:8080"

@app.post("/mcp/loki/query")
async def query_loki(request: LokiQueryRequest):
    """
    Validate request format and forward to pseudonymizer.
    We have NO key here - can't reverse anything.
    """
    # Validate request structure
    if not is_valid_logql(request.query):
        raise HTTPException(400, "Invalid LogQL query")

    if request.limit > 1000:
        raise HTTPException(400, "Limit too high")

    # Forward to pseudonymizer (which has the key and Loki access)
    async with httpx.AsyncClient() as client:
        response = await client.post(
            f"{PSEUDONYMIZER_URL}/query",
            json=request.dict(),
            timeout=30.0
        )

    # Return already-pseudonymized results
    return response.json()
```

**Network**:
```yaml
networks:
  claude-isolated:  # Receives from Claude
  middle-tier:      # Sends to pseudonymizer
```

### Container 3: Pseudonymizer (Has Key)

**Purpose**: Query Loki, pseudonymize results, return safe data

**Security measures**:
- Only accepts requests from Container 2 (network isolation)
- Key injected via environment variable (not in filesystem)
- Stateless - mapping table in memory or encrypted SQLite

```python
# pseudonymizer/server.py
import os
import uuid
import sqlite3
from cryptography.fernet import Fernet
from fastapi import FastAPI
import httpx

app = FastAPI()

# Key from environment - not in any file Claude could read
PSEUDO_KEY = os.environ["PSEUDONYMIZATION_KEY"]
fernet = Fernet(PSEUDO_KEY)

LOKI_URL = "http://loki:3100"

# In-memory mapping (or encrypted SQLite)
mapping_db = sqlite3.connect(":memory:")
mapping_db.execute("""
    CREATE TABLE mappings (
        original_hash TEXT PRIMARY KEY,
        token TEXT,
        field_type TEXT
    )
""")

def get_or_create_token(value: str, field_type: str) -> str:
    """
    Get existing token or create new one.
    Tokens are random UUIDs - no mathematical relationship to original.
    """
    # Hash original to use as lookup key (we don't store plaintext)
    value_hash = hashlib.sha256(
        (value + PSEUDO_KEY).encode()  # Keyed hash
    ).hexdigest()

    cursor = mapping_db.execute(
        "SELECT token FROM mappings WHERE original_hash = ?",
        (value_hash,)
    )
    row = cursor.fetchone()

    if row:
        return row[0]

    # Create new random token
    token = f"{field_type}_{uuid.uuid4().hex[:12]}"
    mapping_db.execute(
        "INSERT INTO mappings VALUES (?, ?, ?)",
        (value_hash, token, field_type)
    )
    return token

def pseudonymize_log_entry(entry: dict) -> dict:
    """Pseudonymize known PII fields."""
    result = entry.copy()

    # Email addresses
    if "email" in result:
        result["email"] = get_or_create_token(result["email"], "EMAIL")

    # User IDs
    if "userid" in result:
        result["userid"] = get_or_create_token(str(result["userid"]), "USER")

    # IP addresses
    if "ip" in result:
        result["ip"] = get_or_create_token(result["ip"], "IP")

    # Coordinates - round to ~1km
    if "lat" in result:
        result["lat"] = round(float(result["lat"]), 2)
    if "lng" in result:
        result["lng"] = round(float(result["lng"]), 2)

    # Scan text fields for emails/IPs
    for key, value in result.items():
        if isinstance(value, str):
            result[key] = pseudonymize_text(value)

    return result

@app.post("/query")
async def query_and_pseudonymize(request: LokiQueryRequest):
    """Query Loki and pseudonymize results."""

    # Query Loki
    async with httpx.AsyncClient() as client:
        response = await client.get(
            f"{LOKI_URL}/loki/api/v1/query_range",
            params={
                "query": request.query,
                "start": request.start,
                "end": request.end,
                "limit": request.limit
            }
        )

    data = response.json()

    # Pseudonymize each log entry
    if "data" in data and "result" in data["data"]:
        for stream in data["data"]["result"]:
            stream["values"] = [
                [ts, pseudonymize_log_entry(parse_log_line(line))]
                for ts, line in stream["values"]
            ]

    return data
```

**Network**:
```yaml
networks:
  middle-tier:  # Receives from MCP interface
  backend:      # Sends to Loki
```

## Escape Analysis

### If Claude escapes Container 1

**Can reach**: Container 2 (MCP Interface)
**Cannot reach**: Container 3, Loki, Internet

**Container 2 has**:
- No key
- No Loki access
- No useful credentials

**Result**: Dead end. Can only send queries that return pseudonymized data.

### If Claude compromises Container 2

**Can reach**: Container 3 API (but only via HTTP)
**Cannot reach**: Container 3 filesystem, Loki directly

**Container 3 validates**:
- Request format
- Query structure
- Returns only pseudonymized data

**Result**: Still can only get pseudonymized data. Key is in Container 3's environment, not filesystem, and not accessible via API.

### If Claude compromises Container 3

This requires:
1. Escaping Container 1 sandbox
2. Pivoting through Container 2
3. Exploiting Container 3

At this point, the attacker has demonstrated sophisticated capabilities beyond our threat model. However:
- Key is in environment variable, not file
- Key rotates regularly
- Audit logs would show the breach

## MCP Tool Definition

```typescript
// MCP tool exposed to Claude
{
  name: "loki_query",
  description: "Query application logs from Loki. Returns pseudonymized results - emails, user IDs, and IPs are replaced with consistent tokens (e.g., EMAIL_a8f3c2, USER_7b2e91). Coordinates are rounded to ~1km.",
  inputSchema: {
    type: "object",
    properties: {
      query: {
        type: "string",
        description: "LogQL query. Example: {app=\"freegle\"} |= \"error\""
      },
      start: {
        type: "string",
        description: "Start time (ISO 8601 or relative like '1h')"
      },
      end: {
        type: "string",
        description: "End time (ISO 8601 or 'now')"
      },
      limit: {
        type: "integer",
        description: "Max results (default 100, max 1000)"
      }
    },
    required: ["query"]
  }
}
```

## Key Management

### Key Generation

```bash
# Generate a Fernet key (256-bit)
python3 -c "from cryptography.fernet import Fernet; print(Fernet.generate_key().decode())"
```

### Key Storage

- **NOT in any file** in Container 3
- Injected via environment variable at container start
- Stored in Docker secrets or HashiCorp Vault
- Rotated monthly (old mappings archived)

### Key Rotation

When rotating keys:
1. New key for new queries
2. Old mappings remain valid (stored with key version)
3. Gradual migration over 30 days
4. Old key destroyed after migration

## Browser Integration

The browser talks to Container 1's exposed API (or Container 2 directly for questions):

```typescript
// ModTools integration
async function askLogQuestion(question: string): Promise<string> {
  const response = await fetch('https://log-analyzer.freegle.org/ask', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ question })
  });

  const result = await response.json();
  // Result contains pseudonymized data only
  // e.g., "USER_7b2e91 had 15 errors in the last hour from IP_3c8d2a"
  return result.answer;
}
```

## Docker Compose Configuration

```yaml
version: '3.8'

services:
  # Container 1: Claude Code (Sandboxed)
  claude-sandbox:
    build: ./claude-sandbox
    read_only: true
    security_opt:
      - no-new-privileges:true
    cap_drop:
      - ALL
    networks:
      - claude-to-mcp
    environment:
      - MCP_SERVER_URL=http://mcp-interface:8080
    depends_on:
      - mcp-interface

  # Container 2: MCP Interface (No Key)
  mcp-interface:
    build: ./mcp-interface
    networks:
      - claude-to-mcp
      - mcp-to-pseudo
    environment:
      - PSEUDONYMIZER_URL=http://pseudonymizer:8080
    depends_on:
      - pseudonymizer

  # Container 3: Pseudonymizer (Has Key)
  pseudonymizer:
    build: ./pseudonymizer
    networks:
      - mcp-to-pseudo
      - pseudo-to-loki
    environment:
      - PSEUDONYMIZATION_KEY=${PSEUDO_KEY}  # From .env or secrets
      - LOKI_URL=http://loki:3100
    depends_on:
      - loki

  # Loki
  loki:
    image: grafana/loki:2.9.0
    networks:
      - pseudo-to-loki
    volumes:
      - loki-data:/loki

networks:
  claude-to-mcp:
    internal: true
  mcp-to-pseudo:
    internal: true
  pseudo-to-loki:
    internal: true

volumes:
  loki-data:
```

## Comparison with Previous Approach

| Aspect | ModTools AI Support | This Design |
|--------|---------------------|-------------|
| Data access | Predefined fact queries only | Full LogQL queries |
| Pseudonymization | Browser-side, session-scoped | Server-side, cryptographic |
| Key security | N/A (no reversible mapping) | Three-tier isolation |
| Flexibility | Low (77 fixed queries) | High (any LogQL) |
| Claude sandbox | N/A (API-based) | Container + network isolation |
| Escape resistance | Browser trust model | Defense in depth |

## Implementation Steps

1. **Phase 1: Pseudonymizer service**
   - Implement Container 3 with Loki access
   - Test pseudonymization logic
   - Key injection via environment

2. **Phase 2: MCP Interface**
   - Implement Container 2 as stateless proxy
   - Request validation
   - Network isolation from Loki

3. **Phase 3: Claude Sandbox**
   - Configure Claude Code with MCP-only tools
   - Test sandbox restrictions
   - Network isolation testing

4. **Phase 4: Integration**
   - Connect all containers
   - End-to-end testing
   - Escape attempt testing

5. **Phase 5: Browser Integration**
   - Add UI to ModTools
   - Authentication flow
   - Result display

## Open Questions

1. **Session vs Global Mapping**: Should tokens be consistent across sessions (better for correlation) or session-scoped (better for privacy)?

2. **Reverse Lookup UI**: Should ModTools have a (authenticated) way to look up "who is USER_7b2e91"?

3. **Query Restrictions**: Should certain LogQL patterns be blocked (e.g., queries that return too much data)?

4. **Audit Logging**: How much of Claude's activity should we log?

## References

- [ICO Pseudonymisation Guidance](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/data-sharing/anonymisation/pseudonymisation/)
- [ICO Motivated Intruder Test](https://ico.org.uk/for-organisations/uk-gdpr-guidance-and-resources/data-sharing/anonymisation/how-do-we-ensure-anonymisation-is-effective/)
- [EDPB Guidelines 01/2025 on Pseudonymisation](https://www.edpb.europa.eu/system/files/2025-01/edpb_guidelines_202501_pseudonymisation_en.pdf)
- [NIST Tokenization Standards](https://www.cryptomathic.com/blog/emv-tokenization-and-fips-considerations)
- [Existing ModTools AI Support Implementation](../iznik-nuxt3-modtools/components/ModSupportAIAssistant.vue)
