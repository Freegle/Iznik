<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BatchEmailProgress extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = "batch_email_progress";

    /**
     * Disable automatic timestamps since this table has custom timestamp columns.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        "job_type",
        "last_processed_id",
        "last_processed_at",
        "started_at",
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        "last_processed_at" => "datetime",
        "started_at" => "datetime",
    ];

    /**
     * Get or create a progress record for a job type.
     */
    public static function forJob(string $jobType): self
    {
        return self::firstOrCreate(
            ["job_type" => $jobType],
            ["last_processed_id" => null, "last_processed_at" => null]
        );
    }

    /**
     * Update the last processed ID.
     */
    public function markProcessed(int $id): void
    {
        $this->last_processed_id = $id;
        $this->last_processed_at = now();
        $this->save();
    }

    /**
     * Mark that a batch has started.
     */
    public function markStarted(): void
    {
        $this->started_at = now();
        $this->save();
    }
}
