<?php

namespace App\Services\Ux;

/**
 * Loki refused one metric query for returning too many series (HTTP 400, max_query_series). That is a property of
 * the query, not of Loki being down, so the caller splits it or skips it.
 */
class UxSeriesLimit extends \RuntimeException
{
}
