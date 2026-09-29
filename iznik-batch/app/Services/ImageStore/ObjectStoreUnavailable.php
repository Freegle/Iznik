<?php

namespace App\Services\ImageStore;

/**
 * The object store answered something other than "no such key": a 401 or 403
 * (public read off, key revoked, service disabled), a 5xx, or nothing at all.
 *
 * It is its own class so the callers can tell it from a failure with one
 * object. One object failing is counted and the pass goes on; the store being
 * unavailable stops the pass, because every further object would fail the
 * same way and the migrator would otherwise mark thousands of rows failed
 * at chunk speed (2026-09-28: 338,541 rows in an hour) while nothing named
 * the cause. Reported to Sentry by the commands, so it alerts.
 */
class ObjectStoreUnavailable extends \RuntimeException
{
}
