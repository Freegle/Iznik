-- Idempotent production SQL for 2026_09_27_000001_drop_dead_keyword_search_tables.php
--
-- Drops the tables behind the retired keyword search: words, words_cache,
-- items_index and messages_index. No code reads or writes them; search is served
-- from vector embeddings. Frees about 1.6 GB per node.
--
-- The two index tables hold foreign keys into words, so they go first. Safe to run
-- more than once. Run on production AFTER deploying the iznik-server-go code that
-- no longer deletes from messages_index when a message is edited.

DROP TABLE IF EXISTS messages_index;
DROP TABLE IF EXISTS items_index;
DROP TABLE IF EXISTS words_cache;
DROP TABLE IF EXISTS words;
