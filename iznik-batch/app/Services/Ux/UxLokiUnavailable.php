<?php

namespace App\Services\Ux;

/**
 * A Loki query failed. The rollup must not store that day's Loki-derived tables: a hole in the
 * data is honest, a row of zeros made from an error is not.
 */
class UxLokiUnavailable extends \RuntimeException
{
}
