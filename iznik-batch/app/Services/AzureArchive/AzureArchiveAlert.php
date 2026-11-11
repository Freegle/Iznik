<?php

namespace App\Services\AzureArchive;

/**
 * Something about the Azure archive copy a person must look at: Azure charged for bandwidth,
 * egress went over the ceiling, or archived images are not being served from our bucket. Raised
 * through report() so it reaches Sentry.
 */
class AzureArchiveAlert extends \RuntimeException
{
}
