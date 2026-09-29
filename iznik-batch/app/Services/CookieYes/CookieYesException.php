<?php

namespace App\Services\CookieYes;

/**
 * Anything that stopped a conversation with CookieYes. Usually transient: the
 * next scheduled run tries again.
 */
class CookieYesException extends \RuntimeException
{
}
