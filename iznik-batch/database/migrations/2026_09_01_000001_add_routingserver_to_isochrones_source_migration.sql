-- Let isochrones.source record the value the code actually writes.
--
-- iznik-server-go sets source = 'RoutingServer' when our own routing server
-- answers, but the enum has no such member, so that INSERT errors under strict
-- mode. It has not bitten because the routing-server path has never succeeded
-- in production (all 193,831 rows read 'Mapbox'); it would bite the moment
-- ROUTING_EVAL_URL is set for apiv2.
--
-- Idempotent: re-running is a no-op because the target definition is the same.

ALTER TABLE isochrones
  MODIFY COLUMN source
    enum('Mapbox','OSM','Valhalla','GraphHopper','ORS','RoutingServer')
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
    DEFAULT 'Mapbox' COMMENT 'Isochrone data source';
