<?php

namespace App\Services\CookieYes;

/**
 * The stored CookieYes login is missing, expired or revoked. Retrying will not
 * help: someone has to run `php artisan cookieyes:authorize` again.
 */
class CookieYesAuthException extends CookieYesException
{
}
