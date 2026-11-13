-- Stored functions for PostgreSQL. Hand written, not generated: the converter skips routines
-- (tools/pg-schema/convert.mjs) and names them in its report, and this file is where they live.
--
-- The MySQL originals are in database/migrations/2026_02_20_000002_create_stored_functions.php.
-- Each function here gives the same answer as the MySQL one for the same input, which
-- tools/pg-schema/functions.test.mjs checks against iznik-batch/tests/fixtures/stored-functions/cases.json
-- (the answers MySQL gave). docs/developers/reference/database-engines.md lists the differences.
--
-- Everything is CREATE OR REPLACE, so the file can be loaded again over a database that already has
-- the functions. scripts/setup-pg-database.sh loads it after the baseline.
--
-- Names are not quoted, so PostgreSQL folds GetMaxDimension to getmaxdimension. Callers may keep
-- writing them as they do for MySQL.

CREATE EXTENSION IF NOT EXISTS postgis;
CREATE EXTENSION IF NOT EXISTS fuzzystrmatch;
CREATE EXTENSION IF NOT EXISTS unaccent;

-- GetMaxDimension(g): for an area, the diagonal of the square inscribed in the circle of the same
-- area, in the units of the coordinates (the data is degrees); 0 for anything that is not an area,
-- and for NULL. ST_Area is the planar area of a geometry, as in MySQL. A geography argument would give
-- square metres, so the parameter is geometry.
--
-- MySQL refuses a GEOMETRYCOLLECTION that contains a polygon (ST_Area rejects the type); here
-- ST_Area adds up the polygons in it. No stored geometry is a collection.
CREATE OR REPLACE FUNCTION GetMaxDimension(g geometry) RETURNS double precision
LANGUAGE plpgsql IMMUTABLE PARALLEL SAFE AS $$
DECLARE
  area double precision;
  radius double precision;
  diag double precision;
BEGIN
  IF ST_Dimension(g) > 1 THEN
    area := ST_Area(g);
    radius := sqrt(area / pi());
    diag := sqrt(radius * radius * 2);
    RETURN diag;
  END IF;
  RETURN 0;
END
$$;

-- GetMaxDimensionT is the same function. MySQL keeps a second copy that is not declared
-- DETERMINISTIC; PostgreSQL has no such distinction, so this one just calls the first.
CREATE OR REPLACE FUNCTION GetMaxDimensionT(g geometry) RETURNS double precision
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT GetMaxDimension(g) $$;

-- haversine(lat1, lon1, lat2, lon2): 69 miles per degree of the angle between two points. Despite
-- the MySQL COMMENT saying "degrees" the result is miles, and it is 69 and not the 69.09 of a 3959
-- mile earth, so it runs about 0.15 per cent short. Existing callers (ORDER BY, and `< radius`
-- against a band of miles) depend on that, so it is kept. New code uses haversine_miles below.
--
-- It reproduces MySQL's arithmetic, including two things a caller can trip on:
--  * The arguments are FLOAT in MySQL, so they are rounded to single precision here too, and the
--    result is single precision (real).
--  * The inside of the ACOS can come out a hair over 1 for two identical points (a few in 100 of
--    them, depending on where the point is). MySQL's ACOS returns NULL outside -1..1, PostgreSQL's raises an error. This returns NULL,
--    as MySQL does, so a caller sees the same thing on both. The callers that compare or order on the
--    result treat NULL as "no distance"; they get it from MySQL already.
-- The parameters are double precision so that a numeric column, a real column and a literal all
-- resolve to it.
CREATE OR REPLACE FUNCTION haversine(lat1 double precision, lon1 double precision, lat2 double precision, lon2 double precision) RETURNS real
LANGUAGE plpgsql IMMUTABLE PARALLEL SAFE AS $$
DECLARE
  a1 double precision := lat1::real;
  o1 double precision := lon1::real;
  a2 double precision := lat2::real;
  o2 double precision := lon2::real;
  x double precision;
BEGIN
  x := cos(radians(a1)) * cos(radians(a2)) * cos(radians(o2) - radians(o1)) + sin(radians(a1)) * sin(radians(a2));
  IF x > 1 OR x < -1 THEN
    RETURN NULL;
  END IF;
  RETURN 69 * degrees(acos(x));
END
$$;

-- haversine_miles(lat1, lon1, lat2, lon2): the great-circle distance in miles on the 3959 mile earth
-- that the rest of the code uses (utils/reachcap.go, DistancePreferenceFilter, the Go and PHP
-- distance helpers), at full precision. The argument of ACOS is clamped to -1..1, as reachcap.go
-- does, so the same point twice gives 0 and never NULL or an error. There is no MySQL function of this
-- name: on MySQL the expression is written out where it is used.
CREATE OR REPLACE FUNCTION haversine_miles(lat1 double precision, lon1 double precision, lat2 double precision, lon2 double precision) RETURNS double precision
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
  SELECT 3959 * acos(greatest(-1.0::double precision, least(1.0::double precision,
    cos(radians(lat1)) * cos(radians(lat2)) * cos(radians(lon2) - radians(lon1))
    + sin(radians(lat1)) * sin(radians(lat2)))))
$$;

-- damlevlim(s1, s2, n): the edit distance between two strings that gives up once a whole row of the
-- working table has reached n. The result is
--   * the exact distance when that is below n, and
--   * otherwise the smallest value in the row where it gave up (or the exact distance if it never
--     gave up). That is at least n and means nothing else.
-- Its one caller is GET /domains, which keeps a domain when damlevlim(domain, typed, LENGTH(typed))
-- < 3: for a typed domain of 3 or more bytes that is "within 2 edits", and for 1 or 2 bytes the value
-- past the limit is below 3 and every domain matches. Both engines do that.
--
-- The exact distance below n comes from fuzzystrmatch's levenshtein_less_equal, which stops early and
-- is much faster than the loop. Past n the loop below is the MySQL routine statement by statement, so
-- the value is the same one.
--
-- MySQL compares the characters with the schema's collation (utf8mb4_0900_ai_ci), which ignores case
-- and accents. lower and unaccent give the same for text in Latin script. Both are applied to the
-- whole string first, so a letter that unaccent expands (ß to ss) changes the length here and does not
-- in MySQL. The strings are cut to 255 characters before and again after that, because 255 is the
-- size of MySQL's VARCHAR(255) parameters and the longest string levenshtein_less_equal takes, and
-- unaccent can make a string longer. NULL in gives NULL out; MySQL gives 0 or NULL
-- depending on which argument is NULL, and no caller passes one.
CREATE OR REPLACE FUNCTION damlevlim(s1 text, s2 text, n integer) RETURNS integer
LANGUAGE plpgsql STABLE PARALLEL SAFE AS $$
DECLARE
  a text;
  b text;
  l1 integer;
  l2 integer;
  i integer;
  j integer;
  c integer;
  c_min integer;
  c_temp integer;
  cost integer;
  d integer;
  ch text;
  cv0 integer[];
  cv1 integer[];
BEGIN
  IF s1 IS NULL OR s2 IS NULL THEN
    RETURN NULL;
  END IF;
  a := left(unaccent(lower(left(s1, 255))), 255);
  b := left(unaccent(lower(left(s2, 255))), 255);
  l1 := char_length(a);
  l2 := char_length(b);
  IF a = b THEN
    RETURN 0;
  ELSIF l1 = 0 THEN
    RETURN l2;
  ELSIF l2 = 0 THEN
    RETURN l1;
  END IF;

  -- The loop only runs while the row minimum is below n, so for n of 0 or less it never starts and
  -- the result stays 0.
  IF n IS NULL OR n <= 0 THEN
    RETURN 0;
  END IF;

  d := levenshtein_less_equal(a, b, n - 1);
  IF d < n THEN
    RETURN d;
  END IF;

  cv1 := ARRAY(SELECT generate_series(0, l2));
  i := 1;
  c := 0;
  c_min := 0;
  WHILE i <= l1 AND c_min < n LOOP
    ch := substr(a, i, 1);
    c := i;
    c_min := i;
    cv0 := ARRAY[i];
    j := 1;
    WHILE j <= l2 LOOP
      c := c + 1;
      IF ch = substr(b, j, 1) THEN
        cost := 0;
      ELSE
        cost := 1;
      END IF;
      c_temp := cv1[j] + cost;
      IF c > c_temp THEN
        c := c_temp;
      END IF;
      c_temp := cv1[j + 1] + 1;
      IF c > c_temp THEN
        c := c_temp;
      END IF;
      cv0 := cv0 || c;
      j := j + 1;
      IF c < c_min THEN
        c_min := c;
      END IF;
    END LOOP;
    cv1 := cv0;
    i := i + 1;
  END LOOP;
  IF i <= l1 THEN
    c := c_min;
  END IF;
  RETURN c;
END
$$;

-- MySQL function names that code written for MySQL calls. They are here so that one SQL text can serve both
-- engines (the Go API translates the syntax it cannot express this way: iznik-server-go/database/pgtranslate.go).
-- Each takes and returns what the MySQL function does for the inputs the code uses.

-- JSON_EXTRACT(doc, '$.a.b'): the value at the path as JSON, NULL when the path is missing. jsonpath
-- accepts the same path syntax for the forms in use.
CREATE OR REPLACE FUNCTION json_extract(j jsonb, path text) RETURNS jsonb
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT jsonb_path_query_first(j, path::jsonpath) $$;

CREATE OR REPLACE FUNCTION json_extract(j text, path text) RETURNS jsonb
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT jsonb_path_query_first(NULLIF(j, '')::jsonb, path::jsonpath) $$;

-- JSON_UNQUOTE(json): the text of a JSON string, or the JSON text of anything else.
CREATE OR REPLACE FUNCTION json_unquote(j jsonb) RETURNS text
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT j #>> '{}' $$;

CREATE OR REPLACE FUNCTION json_unquote(j text) RETURNS text
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT CASE WHEN j IS NULL THEN NULL ELSE (j::jsonb) #>> '{}' END $$;

-- JSON_SET(doc, '$.a.b', value): sets the value at a path made of object keys.
CREATE OR REPLACE FUNCTION json_set(j jsonb, path text, v anyelement) RETURNS jsonb
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
  SELECT jsonb_set(COALESCE(j, '{}'::jsonb), string_to_array(regexp_replace(path, '^\$\.?', ''), '.'), to_jsonb(v), true)
$$;

CREATE OR REPLACE FUNCTION json_set(j text, path text, v anyelement) RETURNS jsonb
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT json_set(NULLIF(j, '')::jsonb, path, v) $$;

-- JSON_MERGE_PATCH(a, b): RFC 7396 merge patch.
CREATE OR REPLACE FUNCTION json_merge_patch(a jsonb, b jsonb) RETURNS jsonb
LANGUAGE plpgsql IMMUTABLE PARALLEL SAFE AS $$
DECLARE
  k text;
  v jsonb;
  r jsonb;
BEGIN
  IF jsonb_typeof(b) IS DISTINCT FROM 'object' THEN
    RETURN b;
  END IF;
  r := CASE WHEN jsonb_typeof(a) = 'object' THEN a ELSE '{}'::jsonb END;
  FOR k, v IN SELECT * FROM jsonb_each(b) LOOP
    IF v = 'null'::jsonb THEN
      r := r - k;
    ELSE
      r := jsonb_set(r, ARRAY[k], json_merge_patch(r -> k, v), true);
    END IF;
  END LOOP;
  RETURN r;
END
$$;

CREATE OR REPLACE FUNCTION json_merge_patch(a text, b text) RETURNS jsonb
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT json_merge_patch(NULLIF(a, '')::jsonb, NULLIF(b, '')::jsonb) $$;

CREATE OR REPLACE FUNCTION json_merge_patch(a text, b json) RETURNS jsonb
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT json_merge_patch(NULLIF(a, '')::jsonb, b::jsonb) $$;

-- DATE_FORMAT(t, '%Y-%m-%d %H:%i:%s'): the specifiers the code uses; any other character is copied.
CREATE OR REPLACE FUNCTION date_format(t timestamp, fmt text) RETURNS text
LANGUAGE plpgsql IMMUTABLE PARALLEL SAFE AS $$
DECLARE
  out text := '';
  i int := 1;
  c text;
  p text;
BEGIN
  IF t IS NULL OR fmt IS NULL THEN
    RETURN NULL;
  END IF;
  WHILE i <= length(fmt) LOOP
    c := substr(fmt, i, 1);
    IF c = '%' AND i < length(fmt) THEN
      i := i + 1;
      c := substr(fmt, i, 1);
      p := CASE c
        WHEN 'Y' THEN 'YYYY' WHEN 'y' THEN 'YY' WHEN 'm' THEN 'MM' WHEN 'c' THEN 'FMMM'
        WHEN 'd' THEN 'DD' WHEN 'e' THEN 'FMDD' WHEN 'H' THEN 'HH24' WHEN 'k' THEN 'FMHH24'
        WHEN 'h' THEN 'HH12' WHEN 'I' THEN 'HH12' WHEN 'l' THEN 'FMHH12' WHEN 'i' THEN 'MI'
        WHEN 's' THEN 'SS' WHEN 'S' THEN 'SS' WHEN 'p' THEN 'AM' WHEN 'M' THEN 'FMMonth'
        WHEN 'b' THEN 'Mon' WHEN 'a' THEN 'Dy' WHEN 'W' THEN 'FMDay' WHEN 'j' THEN 'DDD'
        WHEN 'T' THEN 'HH24:MI:SS' WHEN 'f' THEN 'US' WHEN 'u' THEN 'IW' WHEN 'v' THEN 'IW'
        ELSE NULL END;
      IF p IS NOT NULL THEN
        out := out || to_char(t, p);
      ELSE
        out := out || c;
      END IF;
    ELSE
      out := out || c;
    END IF;
    i := i + 1;
  END LOOP;
  RETURN out;
END
$$;

CREATE OR REPLACE FUNCTION date_format(t timestamptz, fmt text) RETURNS text
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT date_format((t AT TIME ZONE 'UTC')::timestamp, fmt) $$;

CREATE OR REPLACE FUNCTION date_format(t date, fmt text) RETURNS text
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT date_format(t::timestamp, fmt) $$;

-- DATEDIFF(a, b): whole days from b to a, ignoring the time of day.
CREATE OR REPLACE FUNCTION datediff(a timestamptz, b timestamptz) RETURNS integer
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT ((a AT TIME ZONE 'UTC')::date - (b AT TIME ZONE 'UTC')::date) $$;

-- TIMESTAMPDIFF(unit, a, b): whole units from a to b, truncated toward zero.
CREATE OR REPLACE FUNCTION timestampdiff(unit text, a timestamptz, b timestamptz) RETURNS bigint
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
  SELECT CASE lower(unit)
    WHEN 'second' THEN trunc(extract(epoch FROM (b - a)))::bigint
    WHEN 'minute' THEN trunc(extract(epoch FROM (b - a)) / 60)::bigint
    WHEN 'hour' THEN trunc(extract(epoch FROM (b - a)) / 3600)::bigint
    WHEN 'day' THEN trunc(extract(epoch FROM (b - a)) / 86400)::bigint
    WHEN 'week' THEN trunc(extract(epoch FROM (b - a)) / 604800)::bigint
    WHEN 'month' THEN ((extract(year FROM age(b, a)) * 12 + extract(month FROM age(b, a))))::bigint
    WHEN 'year' THEN extract(year FROM age(b, a))::bigint
  END
$$;

CREATE OR REPLACE FUNCTION unix_timestamp(t timestamptz) RETURNS bigint
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT floor(extract(epoch FROM t))::bigint $$;

CREATE OR REPLACE FUNCTION unix_timestamp() RETURNS bigint
LANGUAGE sql STABLE PARALLEL SAFE AS $$ SELECT floor(extract(epoch FROM now()))::bigint $$;

CREATE OR REPLACE FUNCTION from_unixtime(s double precision) RETURNS timestamptz
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT to_timestamp(s) $$;

-- LOCATE(substr, str [, pos]): 1 based position, 0 when absent.
CREATE OR REPLACE FUNCTION locate(sub text, str text) RETURNS integer
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT strpos(str, sub) $$;

CREATE OR REPLACE FUNCTION locate(sub text, str text, pos integer) RETURNS integer
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
  SELECT CASE WHEN strpos(substr(str, pos), sub) = 0 THEN 0 ELSE strpos(substr(str, pos), sub) + pos - 1 END
$$;

CREATE OR REPLACE FUNCTION find_in_set(s text, list text) RETURNS integer
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
  SELECT COALESCE((SELECT ord::integer FROM unnest(string_to_array(list, ',')) WITH ORDINALITY u(v, ord) WHERE v = s LIMIT 1), 0)
$$;

CREATE OR REPLACE FUNCTION year(t timestamptz) RETURNS integer
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT extract(year FROM t AT TIME ZONE 'UTC')::integer $$;

CREATE OR REPLACE FUNCTION month(t timestamptz) RETURNS integer
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT extract(month FROM t AT TIME ZONE 'UTC')::integer $$;

CREATE OR REPLACE FUNCTION day(t timestamptz) RETURNS integer
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT extract(day FROM t AT TIME ZONE 'UTC')::integer $$;

CREATE OR REPLACE FUNCTION hour(t timestamptz) RETURNS integer
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT extract(hour FROM t AT TIME ZONE 'UTC')::integer $$;

-- DAYOFWEEK: 1 is Sunday, as in MySQL.
CREATE OR REPLACE FUNCTION dayofweek(t timestamptz) RETURNS integer
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT extract(dow FROM t AT TIME ZONE 'UTC')::integer + 1 $$;

-- JSON_VALID(text) and JSON_CONTAINS(doc, candidate).
CREATE OR REPLACE FUNCTION json_valid(j text) RETURNS boolean
LANGUAGE plpgsql IMMUTABLE PARALLEL SAFE AS $$
BEGIN
  IF j IS NULL THEN
    RETURN NULL;
  END IF;
  PERFORM j::jsonb;
  RETURN true;
EXCEPTION WHEN others THEN
  RETURN false;
END
$$;

CREATE OR REPLACE FUNCTION json_contains(doc jsonb, candidate jsonb) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT doc @> candidate $$;

CREATE OR REPLACE FUNCTION json_contains(doc jsonb, candidate json) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT doc @> candidate::jsonb $$;

-- LAST_INSERT_ID(): the last identity value this session took, 0 when there is none yet.
CREATE OR REPLACE FUNCTION last_insert_id() RETURNS bigint
LANGUAGE plpgsql VOLATILE AS $$
BEGIN
  RETURN lastval();
EXCEPTION WHEN others THEN
  RETURN 0;
END
$$;

-- SUM(condition) counts the rows where it holds, as in MySQL where a comparison is 0 or 1.
CREATE OR REPLACE FUNCTION iznik_bool_sum_step(state bigint, b boolean) RETURNS bigint
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT CASE WHEN b IS NULL THEN state WHEN b THEN COALESCE(state, 0) + 1 ELSE COALESCE(state, 0) END $$;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_aggregate a JOIN pg_proc p ON p.oid = a.aggfnoid
                 WHERE p.proname = 'sum' AND p.proargtypes[0] = 'boolean'::regtype) THEN
    CREATE AGGREGATE sum(boolean) (SFUNC = iznik_bool_sum_step, STYPE = bigint);
  END IF;
END
$$;

-- A JSON value compared with a number or text, as MySQL compares them: equal only when the JSON value is
-- of that kind and has that value, so JSON false is not equal to 0.
CREATE OR REPLACE FUNCTION iznik_json_eq_num(j jsonb, n numeric) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT CASE WHEN j IS NULL OR n IS NULL THEN NULL
  WHEN jsonb_typeof(j) = 'number' THEN (j #>> '{}')::numeric = n ELSE false END $$;

CREATE OR REPLACE FUNCTION iznik_json_ne_num(j jsonb, n numeric) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT NOT iznik_json_eq_num(j, n) $$;

CREATE OR REPLACE FUNCTION iznik_json_lt_num(j jsonb, n numeric) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT CASE WHEN j IS NULL OR n IS NULL THEN NULL
  WHEN jsonb_typeof(j) = 'number' THEN (j #>> '{}')::numeric < n ELSE false END $$;

CREATE OR REPLACE FUNCTION iznik_json_le_num(j jsonb, n numeric) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT CASE WHEN j IS NULL OR n IS NULL THEN NULL
  WHEN jsonb_typeof(j) = 'number' THEN (j #>> '{}')::numeric <= n ELSE false END $$;

CREATE OR REPLACE FUNCTION iznik_json_gt_num(j jsonb, n numeric) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT CASE WHEN j IS NULL OR n IS NULL THEN NULL
  WHEN jsonb_typeof(j) = 'number' THEN (j #>> '{}')::numeric > n ELSE false END $$;

CREATE OR REPLACE FUNCTION iznik_json_ge_num(j jsonb, n numeric) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT CASE WHEN j IS NULL OR n IS NULL THEN NULL
  WHEN jsonb_typeof(j) = 'number' THEN (j #>> '{}')::numeric >= n ELSE false END $$;

CREATE OR REPLACE FUNCTION iznik_json_eq_text(j jsonb, s text) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT CASE WHEN j IS NULL OR s IS NULL THEN NULL
  WHEN jsonb_typeof(j) = 'string' THEN (j #>> '{}') = s ELSE false END $$;

CREATE OR REPLACE FUNCTION iznik_json_ne_text(j jsonb, s text) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT NOT iznik_json_eq_text(j, s) $$;

CREATE OR REPLACE FUNCTION iznik_json_like(j jsonb, pattern text) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT (j #>> '{}') LIKE pattern $$;

CREATE OR REPLACE FUNCTION iznik_json_eq_integer(j jsonb, n integer) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT iznik_json_eq_num(j, n::numeric) $$;

CREATE OR REPLACE FUNCTION iznik_json_ne_integer(j jsonb, n integer) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT iznik_json_ne_num(j, n::numeric) $$;

CREATE OR REPLACE FUNCTION iznik_json_lt_integer(j jsonb, n integer) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT iznik_json_lt_num(j, n::numeric) $$;

CREATE OR REPLACE FUNCTION iznik_json_le_integer(j jsonb, n integer) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT iznik_json_le_num(j, n::numeric) $$;

CREATE OR REPLACE FUNCTION iznik_json_gt_integer(j jsonb, n integer) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT iznik_json_gt_num(j, n::numeric) $$;

CREATE OR REPLACE FUNCTION iznik_json_ge_integer(j jsonb, n integer) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT iznik_json_ge_num(j, n::numeric) $$;

CREATE OR REPLACE FUNCTION iznik_json_eq_bigint(j jsonb, n bigint) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT iznik_json_eq_num(j, n::numeric) $$;

CREATE OR REPLACE FUNCTION iznik_json_ne_bigint(j jsonb, n bigint) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT iznik_json_ne_num(j, n::numeric) $$;

CREATE OR REPLACE FUNCTION iznik_json_lt_bigint(j jsonb, n bigint) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT iznik_json_lt_num(j, n::numeric) $$;

CREATE OR REPLACE FUNCTION iznik_json_le_bigint(j jsonb, n bigint) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT iznik_json_le_num(j, n::numeric) $$;

CREATE OR REPLACE FUNCTION iznik_json_gt_bigint(j jsonb, n bigint) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT iznik_json_gt_num(j, n::numeric) $$;

CREATE OR REPLACE FUNCTION iznik_json_ge_bigint(j jsonb, n bigint) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT iznik_json_ge_num(j, n::numeric) $$;

-- The operators. A JSON value next to an integer, number or string in SQL written for MySQL.
DO $$
DECLARE
  o record;
BEGIN
  FOR o IN SELECT * FROM (VALUES
    ('=', 'integer', 'iznik_json_eq_integer'),
    ('<>', 'integer', 'iznik_json_ne_integer'),
    ('<', 'integer', 'iznik_json_lt_integer'),
    ('<=', 'integer', 'iznik_json_le_integer'),
    ('>', 'integer', 'iznik_json_gt_integer'),
    ('>=', 'integer', 'iznik_json_ge_integer'),
    ('=', 'bigint', 'iznik_json_eq_bigint'),
    ('<>', 'bigint', 'iznik_json_ne_bigint'),
    ('<', 'bigint', 'iznik_json_lt_bigint'),
    ('<=', 'bigint', 'iznik_json_le_bigint'),
    ('>', 'bigint', 'iznik_json_gt_bigint'),
    ('>=', 'bigint', 'iznik_json_ge_bigint'),
    ('=', 'numeric', 'iznik_json_eq_num'),
    ('<>', 'numeric', 'iznik_json_ne_num'),
    ('<', 'numeric', 'iznik_json_lt_num'),
    ('<=', 'numeric', 'iznik_json_le_num'),
    ('>', 'numeric', 'iznik_json_gt_num'),
    ('>=', 'numeric', 'iznik_json_ge_num'),
    ('=', 'text', 'iznik_json_eq_text'),
    ('<>', 'text', 'iznik_json_ne_text'),
    ('~~', 'text', 'iznik_json_like')
  ) v(sym, rtype, fn) LOOP
    IF NOT EXISTS (SELECT 1 FROM pg_operator p WHERE p.oprname = o.sym AND p.oprleft = 'jsonb'::regtype AND p.oprright = o.rtype::regtype) THEN
      EXECUTE format('CREATE OPERATOR %s (LEFTARG = jsonb, RIGHTARG = %s, FUNCTION = %s)', o.sym, o.rtype, o.fn);
    END IF;
  END LOOP;
END
$$;

-- MySQL, run non-strict as production is, gives a NOT NULL column that an INSERT leaves out its type's
-- implicit default: '' for text, 0 for numbers, the first member for an enum. PostgreSQL refuses the row.
-- Each such column gets that default here, so an INSERT that works on MySQL works on this schema too.
-- Timestamp, date, json and geometry columns have no such default and are left alone.
DO $$
DECLARE
  c record;
  def text;
BEGIN
  FOR c IN
    SELECT cl.relname AS tbl, a.attname AS col, t.typname AS typ, a.attidentity, a.attgenerated,
           (SELECT pg_get_constraintdef(k.oid) FROM pg_constraint k
             WHERE k.conrelid = cl.oid AND k.contype = 'c' AND k.conname = cl.relname || '_' || a.attname || '_check') AS chk
      FROM pg_attribute a
      JOIN pg_class cl ON cl.oid = a.attrelid AND cl.relkind = 'r' AND cl.relnamespace = 'public'::regnamespace
      JOIN pg_type t ON t.oid = a.atttypid
     WHERE a.attnum > 0 AND NOT a.attisdropped AND a.attnotnull AND a.attidentity = '' AND a.attgenerated = ''
       AND NOT EXISTS (SELECT 1 FROM pg_attrdef d WHERE d.adrelid = a.attrelid AND d.adnum = a.attnum)
  LOOP
    def := NULL;
    IF c.typ IN ('int2', 'int4', 'int8', 'numeric', 'float4', 'float8') THEN
      def := '0';
    ELSIF c.typ IN ('text', 'varchar', 'bpchar', 'citext') THEN
      IF c.chk IS NOT NULL AND c.chk ~ '''' THEN
        def := quote_literal((regexp_match(c.chk, '''([^'']*)'''))[1]);
      ELSE
        def := '''''';
      END IF;
    ELSIF c.typ = 'bytea' THEN
      def := '''''';
    END IF;
    IF def IS NOT NULL THEN
      EXECUTE format('ALTER TABLE %I ALTER COLUMN %I SET DEFAULT %s', c.tbl, c.col, def);
    END IF;
  END LOOP;
END
$$;

CREATE OR REPLACE FUNCTION json_merge_patch(a text, b jsonb) RETURNS jsonb
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT json_merge_patch(NULLIF(a, '')::jsonb, b) $$;

-- JSON_REMOVE(doc, '$.a.b'): removes the key at a path made of object keys.
CREATE OR REPLACE FUNCTION json_remove(j jsonb, path text) RETURNS jsonb
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT j #- string_to_array(regexp_replace(path, '^\$\.?', ''), '.') $$;

CREATE OR REPLACE FUNCTION json_remove(j text, path text) RETURNS jsonb
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT json_remove(NULLIF(j, '')::jsonb, path) $$;

-- JSON_LENGTH(doc [, path]): the number of elements of an array or keys of an object, 1 for a scalar.
CREATE OR REPLACE FUNCTION json_length(j jsonb) RETURNS integer
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
  SELECT CASE jsonb_typeof(j) WHEN 'array' THEN jsonb_array_length(j) WHEN 'object' THEN (SELECT count(*)::integer FROM jsonb_object_keys(j)) WHEN NULL THEN NULL ELSE 1 END
$$;

CREATE OR REPLACE FUNCTION json_length(j jsonb, path text) RETURNS integer
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT json_length(json_extract(j, path)) $$;

-- JSON_ARRAY_APPEND(doc, '$', value): appends to a top-level array, makes a one-element array of anything else.
CREATE OR REPLACE FUNCTION json_array_append(j jsonb, path text, v anyelement) RETURNS jsonb
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
  SELECT CASE WHEN j IS NULL THEN NULL
              WHEN jsonb_typeof(j) = 'array' THEN j || jsonb_build_array(v)
              ELSE jsonb_build_array(j, v) END
$$;

CREATE OR REPLACE FUNCTION json_array_append(j text, path text, v anyelement) RETURNS jsonb
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT json_array_append(NULLIF(j, '')::jsonb, path, v) $$;

-- JSON_TYPE(json): the kind of value, as MySQL names it.
CREATE OR REPLACE FUNCTION json_type(j jsonb) RETURNS text
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
  SELECT CASE jsonb_typeof(j) WHEN 'object' THEN 'OBJECT' WHEN 'array' THEN 'ARRAY' WHEN 'string' THEN 'STRING'
                              WHEN 'number' THEN CASE WHEN j::text ~ '[.eE]' THEN 'DOUBLE' ELSE 'INTEGER' END
                              WHEN 'boolean' THEN 'BOOLEAN' WHEN 'null' THEN 'NULL' END
$$;

-- A comparison next to a number: MySQL's comparisons are 0 or 1, so EXISTS(...) = 1 and JSON_CONTAINS(...) = 0
-- are ordinary tests there.
CREATE OR REPLACE FUNCTION iznik_bool_eq_int(b boolean, i integer) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT b = (i <> 0) $$;

CREATE OR REPLACE FUNCTION iznik_bool_ne_int(b boolean, i integer) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT b <> (i <> 0) $$;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_operator WHERE oprname = '=' AND oprleft = 'boolean'::regtype AND oprright = 'integer'::regtype) THEN
    CREATE OPERATOR = (LEFTARG = boolean, RIGHTARG = integer, FUNCTION = iznik_bool_eq_int);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_operator WHERE oprname = '<>' AND oprleft = 'boolean'::regtype AND oprright = 'integer'::regtype) THEN
    CREATE OPERATOR <> (LEFTARG = boolean, RIGHTARG = integer, FUNCTION = iznik_bool_ne_int);
  END IF;
END
$$;

-- MySQL takes any number where a condition is wanted: WHERE flag AND other. A tinyint column (smallint here) is true unless it is 0.
CREATE OR REPLACE FUNCTION iznik_smallint_to_bool(i smallint) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT i <> 0 $$;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_cast WHERE castsource = 'smallint'::regtype AND casttarget = 'boolean'::regtype) THEN
    CREATE CAST (smallint AS boolean) WITH FUNCTION iznik_smallint_to_bool(smallint) AS IMPLICIT;
  END IF;
END
$$;

-- ST_GeometryType(g) as MySQL names the types: POLYGON, MULTIPOLYGON, POINT, GEOMCOLLECTION. PostGIS says ST_Polygon.
-- The Go API's translator calls this for ST_GeometryType.
CREATE OR REPLACE FUNCTION iznik_geometrytype(g geometry) RETURNS text
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$
  SELECT CASE ST_GeometryType(g)
    WHEN 'ST_GeometryCollection' THEN 'GEOMCOLLECTION'
    ELSE upper(substr(ST_GeometryType(g), 4))
  END
$$;

-- FIELD(x, a, b, c): the 1 based position of x in the list, 0 when absent.
CREATE OR REPLACE FUNCTION field(s text, VARIADIC list text[]) RETURNS integer
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT COALESCE(array_position(list, s), 0) $$;

-- MAX and MIN of a JSON value, as MySQL orders JSON scalars: jsonb's own ordering.
CREATE OR REPLACE FUNCTION iznik_jsonb_max_step(a jsonb, b jsonb) RETURNS jsonb
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT CASE WHEN a IS NULL OR b > a THEN b ELSE a END $$;

CREATE OR REPLACE FUNCTION iznik_jsonb_min_step(a jsonb, b jsonb) RETURNS jsonb
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT CASE WHEN a IS NULL OR b < a THEN b ELSE a END $$;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_aggregate a JOIN pg_proc p ON p.oid = a.aggfnoid WHERE p.proname = 'max' AND p.proargtypes[0] = 'jsonb'::regtype) THEN
    CREATE AGGREGATE max(jsonb) (SFUNC = iznik_jsonb_max_step, STYPE = jsonb);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_aggregate a JOIN pg_proc p ON p.oid = a.aggfnoid WHERE p.proname = 'min' AND p.proargtypes[0] = 'jsonb'::regtype) THEN
    CREATE AGGREGATE min(jsonb) (SFUNC = iznik_jsonb_min_step, STYPE = jsonb);
  END IF;
END
$$;

-- A number matched with LIKE, as MySQL allows: id LIKE '%12%'.
CREATE OR REPLACE FUNCTION iznik_bigint_like(i bigint, pattern text) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT i::text LIKE pattern $$;

CREATE OR REPLACE FUNCTION iznik_bigint_not_like(i bigint, pattern text) RETURNS boolean
LANGUAGE sql IMMUTABLE PARALLEL SAFE AS $$ SELECT i::text NOT LIKE pattern $$;

DO $$
BEGIN
  IF NOT EXISTS (SELECT 1 FROM pg_operator WHERE oprname = '~~' AND oprleft = 'bigint'::regtype AND oprright = 'text'::regtype) THEN
    CREATE OPERATOR ~~ (LEFTARG = bigint, RIGHTARG = text, FUNCTION = iznik_bigint_like);
  END IF;
  IF NOT EXISTS (SELECT 1 FROM pg_operator WHERE oprname = '!~~' AND oprleft = 'bigint'::regtype AND oprright = 'text'::regtype) THEN
    CREATE OPERATOR !~~ (LEFTARG = bigint, RIGHTARG = text, FUNCTION = iznik_bigint_not_like);
  END IF;
END
$$;
