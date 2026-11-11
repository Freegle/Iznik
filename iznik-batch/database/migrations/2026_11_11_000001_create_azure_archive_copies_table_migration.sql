-- Production idempotent SQL: azure_archive_copies, the ledger of the copy of V1's archived images
-- from Azure to our bucket. A new, empty table; nothing alters an existing table. Safe to run
-- before the deploy.
CREATE TABLE IF NOT EXISTS azure_archive_copies (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source VARCHAR(32) NOT NULL,
  sourceid BIGINT UNSIGNED NOT NULL,
  blobname VARCHAR(40) NOT NULL,
  status ENUM('Fetching','Copied','Missing','Failed','Superseded') NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  azurebytes INT UNSIGNED NULL,
  azuremd5 VARCHAR(24) NULL,
  storedbytes INT UNSIGNED NULL,
  optimised TINYINT(1) NOT NULL DEFAULT 0,
  error VARCHAR(255) NULL,
  fetchedat TIMESTAMP NULL,
  copiedat TIMESTAMP NULL,
  created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY azure_archive_copies_blobname (blobname),
  UNIQUE KEY azure_archive_copies_source (source, sourceid),
  KEY azure_archive_copies_fetchedat (fetchedat),
  KEY azure_archive_copies_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
