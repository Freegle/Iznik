<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A request to download and apply a Royal Mail address data (PAF) release. Created by the Go
 * API from the PAF update on SysAdmin Housekeeping, worked through by paf:import-run.
 */
class PafImport extends Model
{
    protected $table = 'paf_imports';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_DOWNLOADING = 'downloading';
    public const STATUS_EXTRACTING = 'extracting';
    public const STATUS_COMPARING = 'comparing';
    public const STATUS_FETCHING = 'fetching';
    public const STATUS_APPLYING = 'applying';
    public const STATUS_DONE = 'done';
    public const STATUS_FAILED = 'failed';

    // The steps the extractor sidecar reports, which are copied straight into status.
    public const EXTRACTOR_STEPS = [self::STATUS_DOWNLOADING, self::STATUS_EXTRACTING, self::STATUS_COMPARING];

    protected $fillable = [
        'status', 'edition', 'release_published', 'unlock_key', 'progress', 'detail', 'lines_in_release',
        'lines_to_apply', 'lines_applied', 'inserted', 'updated', 'skipped', 'error',
        'requested_by', 'started_at', 'finished_at',
    ];

    protected $casts = [
        'progress' => 'integer',
        'lines_in_release' => 'integer',
        'lines_to_apply' => 'integer',
        'lines_applied' => 'integer',
        'inserted' => 'integer',
        'updated' => 'integer',
        'skipped' => 'integer',
        'release_published' => 'date:Y-m-d',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected $hidden = ['unlock_key'];

    /** The oldest request that has not finished, if any. */
    public static function nextUnfinished(): ?self
    {
        return static::whereNotIn('status', [self::STATUS_DONE, self::STATUS_FAILED])
            ->orderBy('id')
            ->first();
    }
}
