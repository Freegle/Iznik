-- Production: create the places gazetteer, then load it with
--   php artisan community-news:load-places
-- Idempotent: re-running either step changes nothing that is already right.
CREATE TABLE IF NOT EXISTS places (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(255) NOT NULL,
    lat DECIMAL(10,5) NOT NULL,
    lng DECIMAL(10,5) NOT NULL,
    population INT UNSIGNED NOT NULL DEFAULT 0,
    position POINT NOT NULL SRID 3857,
    PRIMARY KEY (id),
    UNIQUE KEY place_name_pos (name, lat, lng),
    KEY place_population (population),
    SPATIAL KEY place_position (position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
