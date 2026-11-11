<?php

namespace App\Services\CommunityNews;

/**
 * The research model refused a call because the subscription's usage limit is reached. Every further call is
 * refused the same way until it resets, so the run stops rather than going on through the areas.
 */
class CommunityNewsLimitReached extends \RuntimeException
{
}
