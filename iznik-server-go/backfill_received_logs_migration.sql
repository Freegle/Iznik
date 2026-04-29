-- Backfill missing Message/Received log entries for messages submitted via V2.
-- V1 wrote these in Message::submit(); V2's handleJoinAndPost was missing them.
-- This is idempotent — only inserts where no Received log exists for the message.
--
-- V1 parity:
--   - byuser is NULL (Received is a system event, not a mod action)
--   - text is the RFC822 Message-Id header (messages.messageid)
--   - `user` is backticked (MySQL reserved word)
--   - Only non-soft-deleted messages_groups rows count

INSERT INTO logs (timestamp, type, subtype, groupid, `user`, byuser, msgid, text)
SELECT
    mg.arrival AS timestamp,
    'Message' AS type,
    'Received' AS subtype,
    mg.groupid,
    m.fromuser AS `user`,
    NULL AS byuser,
    m.id AS msgid,
    COALESCE(m.messageid, '') AS text
FROM messages m
INNER JOIN messages_groups mg ON mg.msgid = m.id
WHERE m.source = 'Platform'
  AND m.arrival >= '2026-01-01'
  AND mg.deleted = 0
  AND NOT EXISTS (
    SELECT 1 FROM logs l
    WHERE l.type = 'Message'
      AND l.subtype = 'Received'
      AND l.msgid = m.id
      AND l.groupid = mg.groupid
  );
