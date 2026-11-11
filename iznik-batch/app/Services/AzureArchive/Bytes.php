<?php

namespace App\Services\AzureArchive;

final class Bytes
{
    public static function gb(float|int|null $bytes): string
    {
        return $bytes === null ? 'unknown' : number_format($bytes / 1e9, 2) . ' GB';
    }
}
