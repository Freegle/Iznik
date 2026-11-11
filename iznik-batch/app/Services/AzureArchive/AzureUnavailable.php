<?php

namespace App\Services\AzureArchive;

/**
 * Azure's management API could not give a figure the egress budget needs: no credentials, a
 * refused token, a throttled or failed query, or an answer in a shape we do not understand.
 *
 * The copier treats every one of these the same way: it does not fetch. A figure it cannot read
 * is never taken as zero.
 */
class AzureUnavailable extends \RuntimeException
{
}
