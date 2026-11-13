-- Lists the foreign keys that loaded rows break, and how many rows break each.
--
-- The fixtures load with foreign keys unchecked, as they do on MySQL, so a row can point at a row
-- that is not there. MySQL never said so. This asks, for every foreign key in the schema, how many
-- rows have no parent. It changes nothing and prints one line per key that has orphans:
--
--   psql -X -q -At -d iznik -f tools/fixtures/orphans.pg.sql
--
-- A key with a NULL in any column is not counted, as PostgreSQL treats it.
DO $$
DECLARE
  r record;
  n bigint;
  total bigint := 0;
BEGIN
  FOR r IN
    SELECT c.conname,
           c.conrelid::regclass AS child,
           c.confrelid::regclass AS parent,
           (SELECT string_agg(format('c.%I = p.%I', ca.attname, pa.attname), ' AND ' ORDER BY k.ord)
              FROM unnest(c.conkey, c.confkey) WITH ORDINALITY AS k(child_col, parent_col, ord)
              JOIN pg_attribute ca ON ca.attrelid = c.conrelid AND ca.attnum = k.child_col
              JOIN pg_attribute pa ON pa.attrelid = c.confrelid AND pa.attnum = k.parent_col) AS join_on,
           (SELECT string_agg(format('c.%I IS NOT NULL', ca.attname), ' AND ' ORDER BY k.ord)
              FROM unnest(c.conkey) WITH ORDINALITY AS k(child_col, ord)
              JOIN pg_attribute ca ON ca.attrelid = c.conrelid AND ca.attnum = k.child_col) AS not_null
    FROM pg_constraint c
    JOIN pg_namespace ns ON ns.oid = c.connamespace AND ns.nspname = current_schema()
    WHERE c.contype = 'f'
    ORDER BY c.conrelid::regclass::text, c.conname
  LOOP
    EXECUTE format('SELECT count(*) FROM %s c WHERE %s AND NOT EXISTS (SELECT 1 FROM %s p WHERE %s)',
                   r.child, r.not_null, r.parent, r.join_on)
      INTO n;
    IF n > 0 THEN
      RAISE INFO '% % -> %: % rows', r.child, r.conname, r.parent, n;
      total := total + n;
    END IF;
  END LOOP;
  RAISE INFO 'orphaned rows in total: %', total;
END
$$;
