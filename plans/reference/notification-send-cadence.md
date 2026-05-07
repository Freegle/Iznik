# Notification Send Cadence Reference

Extracted from the V1 PHP cron system. Reflects what the production batch jobs do/should replicate.

## Transactional (immediate-ish)

| Job | Interval | Notes |
|-----|----------|-------|
| `chat_notifyemail_user2user` | Every 1 min | Active hours 0–3, 5–23 |
| `chat_notifyemail_user2mod` | Every 1 min | Active hours 0–3, 5–23 |
| `notification_chaseup` | Every 5 min | |
| `donations_thank` | Every 5 min | |

## Digest intervals (`digest.php -i <N>`)

| Flag | Interval | Cron |
|------|----------|------|
| `-i -1` | Immediate | Every 1 min |
| `-i 1` | 1 hour | Every 5 min |
| `-i 2` | 2 hours | Every 5 min |
| `-i 4` | 4 hours | Every 5 min |
| `-i 8` | 8 hours | Every 5 min |
| `-i 24` | 24 hours | Every 5 min (two parallel workers: `-m 2 -v 0` and `-m 2 -v 1`) |

## Scheduled campaigns

| Job | Schedule |
|-----|----------|
| `birthday.php` | Daily noon |
| `user_askdonation.php` | Daily 5pm |
| `newsfeed_digest.php` | Daily 3:30pm |
| `mod_active.php` | Weekly Monday 3pm |
| `events.php` | Weekly Thursday 11pm |
| `volunteering.php` | Weekly Monday 11pm |
| `mod_notifs.php` | Every hour |
| `donations_email.php` | Hourly 6am–10pm |

## Moderator notification gaps (user settings)

```php
$activeminage = $u->getSetting('modnotifs', 4);        // Default 4 hours between mod emails
$backupminage = $u->getSetting('backupmodnotifs', 12); // Default 12 hours for backup mods
```

## Key facts
- 20 spool processors run every 5 minutes (10 member + 10 admin spools)
- Active moderators can receive 6+ emails in a single day (mod_notifs + chat + digest + campaigns)
- No cross-campaign coordination in V1 — each cron runs independently
- Peak collision hours: 3pm, 5pm, 11pm
