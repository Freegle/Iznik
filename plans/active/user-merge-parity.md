# User Merge V1→V2 Parity Plan

## Problem
The Go API `POST /user` merge handler (`user_write.go`) only merges 5 tables (messages, chat_rooms, chat_messages, users_emails, memberships). V1 PHP merges 25+ tables in a transaction with role merging, attribute preservation, and logging.

## V1 Merge Operations (from User.php:2858-3235)
1. BEGIN TRANSACTION
2. Merge memberships (take max role, preserve non-NULL attributes: configid, settings, heldby)
3. Merge emails (handle primary preference conflicts)
4. Merge 25+ reference tables: locations_excluded, chat_roster, sessions, spam_users, users_addresses, comments, donations, images, invitations, logins, nearby, notifications, nudges, push_notifications, requests, searches, newsfeed, messages_reneged, stories, stories_likes, stories_requested, thanks, modnotifs, teams_members, aboutme, ratings, replytime, promises, messages_by, trysts, isochrones, microactions
5. Handle bans (DELETE memberships for banned groups)
6. Merge chat rooms (consolidate duplicates, merge messages between rooms)
7. Merge top-level user attributes (fullname, firstname, lastname, yahooid — take non-NULL from id2)
8. Merge logs (UPDATE user and byuser references)
9. Merge messages_history and memberships_history
10. Merge systemrole (take maximum)
11. Merge added date (take earlier)
12. Merge tnuserid (if id2 has it and id1 doesn't)
13. Merge giftaid (keep best period)
14. Log merge event (2 entries)
15. COMMIT or ROLLBACK
16. Delete id2

## Approach
- Wrap in DB transaction
- Iterate through tables systematically
- Add tests for each merge aspect
- Use TDD: write test for each table merge, verify it fails, implement, verify it passes

## Status: Not started — dedicated session needed
