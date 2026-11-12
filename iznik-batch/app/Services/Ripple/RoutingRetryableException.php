<?php

namespace App\Services\Ripple;

/**
 * The routing service could not answer in a way that asking again may fix: it was unreachable, or it
 * returned a server error or said it was not ready. The reach mail pass holds its mark for these, for
 * a short bounded time. Any other failure is not worth asking again and does not hold anything.
 */
class RoutingRetryableException extends \RuntimeException
{
}
