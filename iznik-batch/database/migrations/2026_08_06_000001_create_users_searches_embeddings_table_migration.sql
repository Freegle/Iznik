-- Production idempotent SQL: users_searches_embeddings.
--
-- A saved search term stored as a vector, so the search signal can be held to the
-- same bar as the matched-posts email (MinMatchedPostScore, 0.85) instead of being
-- matched with SQL LIKE, which has no score with which to say how badly something
-- matched.
--
-- The term is embedded as a DOCUMENT, not a query: a term ("pine bookcase") is the
-- same kind of text as a post subject once preprocessSubject has stripped "OFFER:"
-- and the trailing location, so this sits on the same document-vs-document cosine
-- scale as messages_embeddings.subject_embedding and 0.85 means the same thing on
-- both. Embedding it as a query would put it on the search scale, where the
-- equivalent bar is a different number.
--
-- BLOB rather than a VECTOR column: Percona 8.0 has no vector type. model_version
-- so a model change can be found and re-embedded rather than silently mixing
-- scales.
CREATE TABLE IF NOT EXISTS users_searches_embeddings (
    searchid BIGINT UNSIGNED NOT NULL,
    term_embedding BLOB NOT NULL,
    model_version VARCHAR(50) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (searchid),
    KEY users_searches_embeddings_model (model_version),
    CONSTRAINT users_searches_embeddings_searchid_foreign
        FOREIGN KEY (searchid) REFERENCES users_searches (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
