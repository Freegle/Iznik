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
