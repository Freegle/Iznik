<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates MySQL stored functions needed by the application.
 *
 * These functions were previously loaded from functions.sql and damlevlim.sql.
 * Now managed as a Laravel migration (single source of truth).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS GetMaxDimension');
        DB::unprepared("
            CREATE FUNCTION GetMaxDimension(g GEOMETRY) RETURNS DOUBLE
            NO SQL
            DETERMINISTIC
            BEGIN
                DECLARE area, radius, diag DOUBLE;
                IF ST_Dimension(g) > 1 THEN
                    SET area = ST_AREA(g);
                    SET radius = SQRT(area / PI());
                    SET diag = SQRT(radius * radius * 2);
                    RETURN(diag);
                ELSE
                    RETURN 0;
                END IF;
            END
        ");

        DB::unprepared('DROP FUNCTION IF EXISTS GetMaxDimensionT');
        DB::unprepared("
            CREATE FUNCTION GetMaxDimensionT(g GEOMETRY) RETURNS DOUBLE
            NO SQL
            BEGIN
                DECLARE area, radius, diag DOUBLE;
                IF ST_Dimension(g) > 1 THEN
                    SET area = ST_AREA(g);
                    SET radius = SQRT(area / PI());
                    SET diag = SQRT(radius * radius * 2);
                    RETURN(diag);
                ELSE
                    RETURN 0;
                END IF;
            END
        ");

        DB::unprepared('DROP FUNCTION IF EXISTS haversine');
        DB::unprepared("
            CREATE FUNCTION haversine(
                lat1 FLOAT, lon1 FLOAT,
                lat2 FLOAT, lon2 FLOAT
            ) RETURNS FLOAT
            NO SQL
            DETERMINISTIC
            COMMENT 'Returns the distance in degrees on the Earth between two known points of latitude and longitude'
            BEGIN
                RETURN 69 * DEGREES(ACOS(
                    COS(RADIANS(lat1)) *
                    COS(RADIANS(lat2)) *
                    COS(RADIANS(lon2) - RADIANS(lon1)) +
                    SIN(RADIANS(lat1)) * SIN(RADIANS(lat2))
                ));
            END
        ");

        DB::unprepared('DROP FUNCTION IF EXISTS damlevlim');
        DB::unprepared("
            CREATE FUNCTION damlevlim(s1 VARCHAR(255), s2 VARCHAR(255), n INT)
            RETURNS INT
            DETERMINISTIC
            BEGIN
                DECLARE s1_len, s2_len, i, j, c, c_temp, cost, c_min INT;
                DECLARE s1_char CHAR;
                DECLARE cv0, cv1 VARBINARY(256);
                SET s1_len = CHAR_LENGTH(s1), s2_len = CHAR_LENGTH(s2), cv1 = 0x00, j = 1, i = 1, c = 0, c_min = 0;
                IF s1 = s2 THEN
                    RETURN 0;
                ELSEIF s1_len = 0 THEN
                    RETURN s2_len;
                ELSEIF s2_len = 0 THEN
                    RETURN s1_len;
                ELSE
                    WHILE j <= s2_len DO
                        SET cv1 = CONCAT(cv1, UNHEX(HEX(j))), j = j + 1;
                    END WHILE;
                    WHILE i <= s1_len AND c_min < n DO
                        SET s1_char = SUBSTRING(s1, i, 1), c = i, c_min = i, cv0 = UNHEX(HEX(i)), j = 1;
                        WHILE j <= s2_len DO
                            SET c = c + 1;
                            IF s1_char = SUBSTRING(s2, j, 1) THEN
                                SET cost = 0; ELSE SET cost = 1;
                            END IF;
                            SET c_temp = CONV(HEX(SUBSTRING(cv1, j, 1)), 16, 10) + cost;
                            IF c > c_temp THEN SET c = c_temp; END IF;
                            SET c_temp = CONV(HEX(SUBSTRING(cv1, j+1, 1)), 16, 10) + 1;
                            IF c > c_temp THEN
                                SET c = c_temp;
                            END IF;
                            SET cv0 = CONCAT(cv0, UNHEX(HEX(c))), j = j + 1;
                            IF c < c_min THEN
                                SET c_min = c;
                            END IF;
                        END WHILE;
                        SET cv1 = cv0, i = i + 1;
                    END WHILE;
                END IF;
                IF i <= s1_len THEN
                    SET c = c_min;
                END IF;
                RETURN c;
            END
        ");
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS GetMaxDimension');
        DB::unprepared('DROP FUNCTION IF EXISTS GetMaxDimensionT');
        DB::unprepared('DROP FUNCTION IF EXISTS haversine');
        DB::unprepared('DROP FUNCTION IF EXISTS damlevlim');
    }
};
