-- Fires every ON UPDATE CURRENT_TIMESTAMP trigger the baseline created, on a scratch row, and
-- checks the MySQL behaviour: the column moves when another column changes, stays when nothing
-- changed, and keeps a value the statement set itself, its own value included. Run inside a transaction that is rolled
-- back. Foreign keys are switched off for the scratch rows; the converter's triggers are kept on.
--
-- Raises an exception that lists every table that misbehaved.

DO $check$
DECLARE
  t record;
  col record;
  st record;
  cols text;
  vals text;
  chg_col text;
  chg_old text;
  chg_new text;
  ts_cols text[];
  tsc text;
  bad text := '';
  checked int := 0;
  skipped text := '';
  allowed text;
  v text;
  before_ts timestamptz;
  after_ts timestamptz;
BEGIN
  SET LOCAL session_replication_role = replica;

  FOR t IN
    SELECT cl.oid, cl.relname, tg.tgname
    FROM pg_trigger tg JOIN pg_class cl ON cl.oid = tg.tgrelid
    WHERE NOT tg.tgisinternal AND tg.tgname LIKE '%\_on\_update' AND cl.relnamespace = current_schema()::regnamespace
    ORDER BY cl.relname
  LOOP
    EXECUTE format('ALTER TABLE %I ENABLE ALWAYS TRIGGER %I', t.relname, t.tgname);
    -- The statement triggers that note which columns an UPDATE names, and clear the note after it.
    FOR st IN
      SELECT s.tgname FROM pg_trigger s JOIN pg_proc p ON p.oid = s.tgfoid
      WHERE s.tgrelid = t.oid AND p.proname IN ('iznik_on_update_named', 'iznik_on_update_done')
    LOOP
      EXECUTE format('ALTER TABLE %I ENABLE ALWAYS TRIGGER %I', t.relname, st.tgname);
    END LOOP;
    cols := '';
    vals := '';
    chg_col := NULL;
    ts_cols := '{}';

    FOR col IN
      SELECT a.attname, a.attnum, format_type(a.atttypid, a.atttypmod) AS typ, a.attidentity, a.attgenerated,
             (SELECT pg_get_constraintdef(k.oid) FROM pg_constraint k
               WHERE k.conrelid = t.oid AND k.contype = 'c' AND k.conkey = ARRAY[a.attnum]::int2[] LIMIT 1) AS chk
      FROM pg_attribute a
      WHERE a.attrelid = t.oid AND a.attnum > 0 AND NOT a.attisdropped
      ORDER BY a.attnum
    LOOP
      CONTINUE WHEN col.attidentity <> '' OR col.attgenerated <> '';
      allowed := NULL;
      IF col.chk IS NOT NULL THEN
        allowed := coalesce(substring(col.chk FROM $re$ARRAY\['((?:[^']|'')*)'::text$re$), substring(col.chk FROM $re$= '((?:[^']|'')*)'::text$re$));
      END IF;

      IF col.typ LIKE 'timestamp%' THEN
        v := quote_literal('2000-01-01 00:00:00+00');
        -- A timestamp column that the trigger maintains is the one under test.
        IF EXISTS (SELECT 1 FROM pg_proc p WHERE p.proname = 'iznik_on_update_' || t.relname AND p.prosrc ~ ('NEW\."?' || col.attname || '"? IS NOT DISTINCT')) THEN
          ts_cols := ts_cols || col.attname::text;
        END IF;
      ELSIF allowed IS NOT NULL THEN
        v := quote_literal(replace(allowed, '''''', ''''));
      ELSIF col.typ IN ('smallint', 'integer', 'bigint') OR col.typ LIKE 'numeric%' OR col.typ IN ('real', 'double precision') THEN
        v := '0';
        IF chg_col IS NULL AND col.typ IN ('smallint', 'integer', 'bigint') THEN chg_col := col.attname; chg_old := '0'; chg_new := '1'; END IF;
      ELSIF col.typ LIKE 'character%' OR col.typ = 'text' OR col.typ = 'citext' THEN
        v := quote_literal('x');
        IF chg_col IS NULL AND (col.typ NOT LIKE 'character(%' ) THEN chg_col := col.attname; chg_old := quote_literal('x'); chg_new := quote_literal('y'); END IF;
      ELSIF col.typ = 'jsonb' THEN
        v := quote_literal('{}');
      ELSIF col.typ = 'date' THEN
        v := quote_literal('2000-01-01');
      ELSIF col.typ LIKE 'time%' THEN
        v := quote_literal('00:00:00');
      ELSIF col.typ = 'bytea' THEN
        v := quote_literal('\x00') || '::bytea';
      ELSIF col.typ LIKE 'geometry(Polygon%' THEN
        v := $g$ST_GeomFromText('POLYGON((0 0,1 0,1 1,0 0))',4326)$g$;
      ELSIF col.typ LIKE 'geometry%' THEN
        v := $g$ST_GeomFromText('POINT(0 0)',4326)$g$;
      ELSIF col.typ = 'bit(1)' THEN
        v := $b$B'0'$b$;
      ELSE
        bad := bad || format(' %s.%s has type %s the check does not know;', t.relname, col.attname, col.typ);
        v := 'NULL';
      END IF;
      cols := cols || CASE WHEN cols = '' THEN '' ELSE ', ' END || quote_ident(col.attname);
      vals := vals || CASE WHEN vals = '' THEN '' ELSE ', ' END || v;
    END LOOP;

    IF cols = '' THEN
      EXECUTE format('INSERT INTO %I DEFAULT VALUES', t.relname);
    ELSE
      EXECUTE format('INSERT INTO %I (%s) VALUES (%s)', t.relname, cols, vals);
    END IF;

    IF chg_col IS NULL OR array_length(ts_cols, 1) IS NULL THEN
      skipped := skipped || ' ' || t.relname;
      EXECUTE format('DELETE FROM %I', t.relname);
      CONTINUE;
    END IF;

    FOREACH tsc IN ARRAY ts_cols LOOP
      -- 1. A change to another column moves the timestamp.
      EXECUTE format('UPDATE %I SET %I = %s', t.relname, chg_col, chg_new);
      EXECUTE format('SELECT %I FROM %I', tsc, t.relname) INTO after_ts;
      IF after_ts IS NULL OR after_ts <= timestamptz '2000-01-02' THEN
        bad := bad || format(' %s.%s did not move on a change;', t.relname, tsc);
      END IF;
      -- 2. An update that changes nothing leaves it alone.
      EXECUTE format('UPDATE %I SET %I = %L::timestamptz', t.relname, tsc, '2001-01-01 00:00:00+00');
      EXECUTE format('UPDATE %I SET %I = %I', t.relname, chg_col, chg_col);
      EXECUTE format('SELECT %I FROM %I', tsc, t.relname) INTO after_ts;
      IF after_ts <> timestamptz '2001-01-01 00:00:00+00' THEN
        bad := bad || format(' %s.%s moved on a no-op update;', t.relname, tsc);
      END IF;
      -- 3. A value the statement sets itself is kept, even when other columns change too.
      EXECUTE format('UPDATE %I SET %I = %s, %I = %L::timestamptz', t.relname, chg_col, chg_old, tsc, '2002-02-02 00:00:00+00');
      EXECUTE format('SELECT %I FROM %I', tsc, t.relname) INTO after_ts;
      IF after_ts <> timestamptz '2002-02-02 00:00:00+00' THEN
        bad := bad || format(' %s.%s overwrote an explicit value;', t.relname, tsc);
      END IF;
      -- 4. So is its own value, when the statement names the column (SET ts = ts).
      EXECUTE format('UPDATE %I SET %I = %s, %I = %I', t.relname, chg_col, chg_new, tsc, tsc);
      EXECUTE format('SELECT %I FROM %I', tsc, t.relname) INTO after_ts;
      IF after_ts <> timestamptz '2002-02-02 00:00:00+00' THEN
        bad := bad || format(' %s.%s moved although the statement set it to itself;', t.relname, tsc);
      END IF;
      -- 5. The next statement, which does not name it, moves it again.
      EXECUTE format('UPDATE %I SET %I = %s', t.relname, chg_col, chg_old);
      EXECUTE format('SELECT %I FROM %I', tsc, t.relname) INTO after_ts;
      IF after_ts IS NULL OR after_ts <= timestamptz '2002-02-03' THEN
        bad := bad || format(' %s.%s did not move after a statement that had named it;', t.relname, tsc);
      END IF;
      EXECUTE format('UPDATE %I SET %I = %L::timestamptz', t.relname, tsc, '2000-01-01 00:00:00+00');
    END LOOP;
    EXECUTE format('DELETE FROM %I', t.relname);
    checked := checked + 1;
  END LOOP;

  RAISE NOTICE 'triggers checked on % tables; skipped (no changeable column):%', checked, skipped;
  IF bad <> '' THEN
    RAISE EXCEPTION 'trigger check failed:%', bad;
  END IF;
END
$check$;
