-- Production idempotent SQL: email_tracking.replied_via gains 'tn_api', for a chat reply that came
-- from TrashNothing's chat-messages feed. Appending to the end of an enum is a metadata-only
-- change; ALGORITHM=INSTANT makes MySQL refuse rather than rebuild the table if it is not.
-- Re-running it is harmless. Run it before the deploy that turns FREEGLE_TN_INGEST_CHAT_VIA_API on.
ALTER TABLE email_tracking MODIFY replied_via ENUM('amp','website','email','tn_api') NULL, ALGORITHM=INSTANT;
