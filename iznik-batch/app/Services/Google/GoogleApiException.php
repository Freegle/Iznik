<?php

namespace App\Services\Google;

/**
 * Something went wrong talking to a Google API, or we were never able to start:
 * the message is written to be read by a person and says what to do next.
 */
class GoogleApiException extends \RuntimeException
{
}
