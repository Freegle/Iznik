-- Gives the PostgreSQL test databases the implicit defaults of the MySQL test databases.
--
-- The MySQL test databases are built without strict mode (scripts/setup-test-database.sh), so an
-- INSERT that leaves out a NOT NULL column with no default stores the type's zero value: '' for
-- text, 0 for numbers. Test fixtures and a good deal of application test setup rely on that. PostgreSQL
-- refuses such an insert, so each of those columns gets the same zero value as an explicit default.
--
-- Loaded by scripts/setup-pg-database.sh after the baseline and the functions, into the test databases
-- only. Production schemas never see it. It reads the catalog, so a new column is covered without
-- an edit here, and it can be loaded again.
DO $relax$
DECLARE
  c record;
  zero text;
BEGIN
  FOR c IN
    SELECT a.attrelid::regclass AS tbl, a.attname AS col, format_type(a.atttypid, a.atttypmod) AS typ, t.typcategory AS cat
      FROM pg_attribute a
      JOIN pg_class r ON r.oid = a.attrelid AND r.relkind = 'r' AND r.relnamespace = current_schema()::regnamespace
      JOIN pg_type t ON t.oid = a.atttypid
      LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
     WHERE a.attnum > 0 AND NOT a.attisdropped AND a.attnotnull
       AND d.adrelid IS NULL AND a.attidentity = '' AND a.attgenerated = ''
  LOOP
    zero := CASE
      WHEN c.cat = 'S' THEN quote_literal('')
      WHEN c.cat = 'N' THEN '0'
      WHEN c.cat = 'D' THEN quote_literal('1970-01-01 00:00:00+00')
      WHEN c.typ = 'bytea' THEN quote_literal('') || '::bytea'
      ELSE NULL
    END;
    IF zero IS NOT NULL THEN
      EXECUTE format('ALTER TABLE %s ALTER COLUMN %I SET DEFAULT %s', c.tbl, c.col, zero);
    END IF;
  END LOOP;
END
$relax$;
