# Yesterday System - Security Architecture

This document explains the security measures protecting the Yesterday backup management system.

## Overview

The Yesterday system provides access to historical Freegle production backups for data recovery, analysis, and testing. It contains sensitive production data and requires strong security controls.

## Security Model

**Threat Model:** The system is designed to protect against:
- Unauthorized public access
- Automated attacks and bots
- Credential brute-forcing
- Network-level attacks
- Man-in-the-middle (MITM) attacks
- Accidental exposure of internal services

**Out of Scope:** The system assumes:
- Server infrastructure is properly secured (OS hardening, SSH keys, firewall rules)
- Legitimate users are trustworthy (insider threat mitigation)
- GCP project access is restricted to authorized personnel

## Security Layers

### 1. HTTP Basic Authentication (Optional First Layer)

**Implementation:** Standard HTTP Basic Auth (RFC 7617)
- Optional additional security layer before 2FA
- Configured via `BACKUP_BASIC_AUTH` environment variable
- Format: `username:password`

**Location:** `yesterday/2fa-gateway/server.js` (lines 17-43)

**How it works:**
1. If `BACKUP_BASIC_AUTH` is set, user must provide valid credentials
2. Browser prompts for username/password before any page loads
3. Credentials are checked before 2FA authentication
4. Browser typically remembers credentials for convenience

**Benefits:**
- Adds defense-in-depth (two independent auth layers)
- Reduces exposure to automated attacks scanning for vulnerabilities
- Simple to share with team (one set of credentials for basic auth, individual 2FA per user)
- Can be disabled for development by leaving environment variable blank

**Admin Configuration:**
```bash
# In .env file
BACKUP_BASIC_AUTH=admin:secretpassword

# Share credentials securely with authorized team members
# They will need BOTH basic auth credentials AND their individual 2FA setup
```

### 2. Two-Factor Authentication (2FA)

**Implementation:** TOTP-based authentication using industry-standard algorithms
- Compatible with Google Authenticator, Microsoft Authenticator, Authy
- 6-digit codes that change every 30 seconds
- Time-window tolerance of ±60 seconds (2 steps) for clock drift

**Location:** `yesterday/2fa-gateway/server.js`

**How it works:**
1. User enters username and current TOTP code
2. System verifies code using speakeasy library
3. On success, user's IP address is whitelisted for 24 hours
4. Subsequent requests from same IP skip 2FA (session management via IP whitelist)

**Admin Management:**
```bash
./yesterday/scripts/2fa-admin.sh add username    # Create new user
./yesterday/scripts/2fa-admin.sh list            # List users
./yesterday/scripts/2fa-admin.sh delete username # Remove user
```

**User secrets stored:** `/var/www/FreegleDocker/yesterday/data/2fa/2fa-users.json`

### 3. Network Isolation

**Architecture:**
```
Internet → Traefik (443) → 2FA Gateway (8084) → Backend Services
                              ↓
                         [HTTP Basic Auth] (optional)
                              ↓
                         [2FA Required]
                              ↓
                         [IP Whitelist Check]
                              ↓
                    ┌─────────┴──────────┐
                    ↓                    ↓
            yesterday-api (8082)   yesterday-index (80)
```

**Key Security Features:**

**Services use `expose` not `ports`:**
```yaml
yesterday-api:
  expose:
    - "8082"  # Internal only - not accessible from internet

yesterday-index:
  expose:
    - "80"    # Internal only - not accessible from internet
```

This means:
- API and index page are NOT directly accessible from the internet
- All traffic MUST go through the 2FA gateway
- Cannot bypass authentication by accessing ports directly

**Comparison with insecure configuration:**
```yaml
# INSECURE (NOT USED):
yesterday-api:
  ports:
    - "8082:8082"  # Would expose API directly to internet!
```

### 4. HTTPS with Automatic Certificate Management

**Implementation:** Traefik reverse proxy with Let's Encrypt

**Configuration:** `yesterday/traefik.yml`

**Features:**
- Automatic HTTPS certificate issuance via ACME protocol
- HTTP automatically redirects to HTTPS (port 80 → 443)
- Certificates automatically renewed every 90 days
- Modern TLS configuration

**Domain:** `yesterday.ilovefreegle.org`

**Certificate storage:** `/var/www/FreegleDocker/yesterday/data/letsencrypt/acme.json`

### 5. IP-Based Session Management

**After successful 2FA authentication:**
- User's IP address is whitelisted for 24 hours
- Stored in: `/var/www/FreegleDocker/yesterday/data/2fa/ip-whitelist.json`
- Automatic cleanup of expired entries (runs hourly)

**Benefits:**
- Reduces authentication friction (don't need 2FA every request)
- Maintains security (session expires after 24 hours)
- Works across multiple tabs/windows

**IP Detection:**
```javascript
function getClientIP(req) {
    return req.headers['x-forwarded-for']?.split(',')[0].trim() ||
           req.headers['x-real-ip'] ||
           req.connection.remoteAddress;
}
```

Traefik sets `X-Forwarded-For` header with real client IP.

### 6. Read-Only Production Data Access

**Database backups are read-only:**
- Restored from `gs://freegle_backup_uk` (cross-project read-only IAM permissions)
- No write access to production backups
- Changes made in Yesterday environment don't affect production

**Image storage:**
- Uses production image delivery service (read-only)
- Configured via: `yesterday/docker-compose.override.yml`
- Images served from: `https://images.ilovefreegle.org`
- Uploads served from: `https://tus.ilovefreegle.org`

### 7. Email Isolation

**All outbound email captured:**
- Mailhog intercepts all SMTP traffic
- No external email delivery
- Prevents accidental email to real users from restored data

**Access:** `http://localhost:8025` (when SSH'd into server)

### 8. Infrastructure Isolation

**Separate GCP Project:**
- Project: `freegle-yesterday`
- Isolated from production: `freegle-1139`
- Separate billing, IAM permissions, networking
- Limits blast radius if compromised

**VM Configuration:**
- Preemptible instance (cost optimization)
- Firewall rules: Only ports 80/443 accessible
- SSH access via GCP IAM and SSH keys

## Security Best Practices

### For Administrators

**Protect admin key:**
```bash
# Set in .env file (never commit to git)
YESTERDAY_ADMIN_KEY=generate_random_key_here

# Use header-based authentication only:
curl -H "X-Admin-Key: $YESTERDAY_ADMIN_KEY" \
     https://yesterday.ilovefreegle.org/admin/users
```

**Never:**
- Share admin key via email/chat
- Put admin key in URLs (query parameters)
- Commit `.env` file to git (it's in `.gitignore`)
- Run admin commands from shared/monitored systems

**User management:**
- Only create accounts for authorized personnel
- Delete accounts immediately when access no longer needed
- Periodically review user list: `./2fa-admin.sh list`

### For Users

**Protect your credentials:**
- Keep your phone secure (PIN/biometric lock)
- Back up authenticator app (Authy/Microsoft Authenticator support cloud backup)
- If phone lost/stolen, contact admin immediately
- **Basic Auth credentials** (if enabled): Store securely in password manager, never share publicly

**Access patterns:**
- Log out when using shared computers
- Don't share your authenticator codes or basic auth credentials
- Don't screenshot authenticator codes and send via messaging
- If basic auth is enabled, your browser may remember those credentials

**See also:** `yesterday/2FA-USER-GUIDE.md` for detailed user instructions

## Known Limitations

### OAuth Logins Don't Work

**Why:** OAuth providers (Yahoo, Google, Facebook) are configured for production domains only
- `www.ilovefreegle.org`
- `modtools.org`

**Not configured for:**
- `localhost`
- `yesterday.ilovefreegle.org`

**Workaround:** Use email/password authentication to access restored systems

### Data Freshness

**Backups are point-in-time snapshots:**
- Taken nightly at ~4:30 AM UTC
- Restored data is historical (not real-time)
- Changes made in Yesterday don't affect production

## Monitoring and Logging

**System status:** Visible at `https://yesterday.ilovefreegle.org` after authentication
- Shows health of: Database, Redis, API v2, Freegle, ModTools
- Auto-refreshes every 30 seconds

**Logs available:**
```bash
# 2FA gateway logs
docker logs yesterday-2fa

# API logs
docker logs yesterday-api

# Restoration logs
tail -f /var/log/yesterday-restore-YYYYMMDD.log

# Traefik logs (HTTPS/certificate issues)
docker logs yesterday-traefik
```

**Failed login attempts logged:**
```
Login attempt for unknown user: baduser from 1.2.3.4
Failed login attempt: alice from 5.6.7.8
Successful login: bob from 9.10.11.12
```

## Incident Response

**If you suspect unauthorized access:**

1. **Immediately disable access:**
   ```bash
   # Stop the 2FA gateway (blocks all access)
   docker stop yesterday-2fa
   ```

2. **Review logs:**
   ```bash
   # Check recent logins
   docker logs yesterday-2fa | grep -E "(Successful|Failed) login"

   # Check whitelisted IPs
   cat /var/www/FreegleDocker/yesterday/data/2fa/ip-whitelist.json
   ```

3. **Reset all access:**
   ```bash
   # Remove all whitelisted IPs
   rm /var/www/FreegleDocker/yesterday/data/2fa/ip-whitelist.json

   # Optionally: Remove all users and recreate
   rm /var/www/FreegleDocker/yesterday/data/2fa/2fa-users.json

   # Change admin key
   # Edit .env file: YESTERDAY_ADMIN_KEY=new_random_key

   # Restart gateway
   docker compose -f yesterday/docker-compose.yesterday-services.yml down
   docker compose -f yesterday/docker-compose.yesterday-services.yml up -d
   ```

4. **Recreate user accounts with new TOTP secrets**

**If data breach suspected:**
- Remember: Yesterday contains production data backups
- Follow Freegle incident response procedures
- Consider whether production database needs attention

## Security Updates

**Keep system updated:**
```bash
# Update Yesterday code
cd /var/www/FreegleDocker
git pull

# Rebuild containers (picks up base image updates)
docker compose -f yesterday/docker-compose.yesterday-services.yml build --pull
docker compose -f yesterday/docker-compose.yesterday-services.yml up -d
```

**Update Node.js packages:**
```bash
# In API directory
cd yesterday/api
npm audit
npm audit fix

# In 2FA gateway directory
cd yesterday/2fa-gateway
npm audit
npm audit fix
```

**Security advisories:**
- Monitor Docker Hub for base image updates (node:22-alpine, traefik:v2.10, nginx:alpine)
- Monitor npm security advisories for dependencies (express, speakeasy)
- Monitor Traefik security announcements

## Architecture Decisions

**Why optional basic auth before 2FA?**
- Defense-in-depth: Two independent authentication layers
- Reduces attack surface from automated scanners
- Simple credential sharing (basic auth) + individual accountability (unique 2FA per user)
- Optional: Can be disabled for development/testing environments
- Doesn't replace 2FA, adds an additional layer

**Why 2FA instead of just passwords?**
- Passwords can be phished, leaked in breaches, or guessed
- TOTP provides strong second factor
- Even if username/password compromised, attacker needs physical access to user's phone

**Why IP whitelisting for 24 hours?**
- Balance between security and usability
- Frequent 2FA prompts reduce productivity
- 24 hours matches typical work session duration
- Can always clear whitelist if needed

**Why Traefik instead of nginx/apache?**
- Automatic Let's Encrypt integration
- Dynamic configuration via Docker labels
- Automatic certificate renewal
- Simple configuration for reverse proxy

**Why separate GCP project?**
- Blast radius containment
- Separate billing/quotas
- IAM isolation (different permissions)
- Network isolation

## References

- Main Documentation: `yesterday/README.md`
- User Guide: `yesterday/2FA-USER-GUIDE.md`
- Planning Document: `/plans/Yesterday.md`
- 2FA Gateway Code: `yesterday/2fa-gateway/server.js`
- API Code: `yesterday/api/server.js`
- Traefik Config: `yesterday/traefik.yml`

## Contact

For security concerns or questions:
- Email: geeks@ilovefreegle.org
- Only discuss security issues via secure channels (not public forums/chat)
