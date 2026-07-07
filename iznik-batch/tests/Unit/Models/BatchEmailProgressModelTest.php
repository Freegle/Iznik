<?php

namespace Tests\Unit\Models;

use App\Models\BatchEmailProgress;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BatchEmailProgressModelTest extends TestCase
{
    public function test_batch_email_progress_has_correct_table(): void
    {
        $progress = new BatchEmailProgress();
        $this->assertEquals('batch_email_progress', $progress->getTable());
    }

    public function test_batch_email_progress_has_no_timestamps(): void
    {
        $progress = new BatchEmailProgress();
        $this->assertFalse($progress->timestamps);
    }

    public function test_batch_email_progress_casts(): void
    {
        $progress = new BatchEmailProgress();
        $casts = $progress->getCasts();

        $this->assertArrayHasKey('last_processed_at', $casts);
        $this->assertArrayHasKey('started_at', $casts);
        $this->assertEquals('datetime', $casts['last_processed_at']);
        $this->assertEquals('datetime', $casts['started_at']);
    }

    public function test_batch_email_progress_fillable(): void
    {
        $progress = new BatchEmailProgress();
        $fillable = $progress->getFillable();

        $this->assertContains('job_type', $fillable);
        $this->assertContains('last_processed_id', $fillable);
        $this->assertContains('last_processed_at', $fillable);
        $this->assertContains('started_at', $fillable);
    }

    public function test_for_job_creates_new_record(): void
    {
        DB::table('batch_email_progress')->where('job_type', 'test_job')->delete();

        $progress = BatchEmailProgress::forJob('test_job');

        $this->assertNotNull($progress);
        $this->assertEquals('test_job', $progress->job_type);
        $this->assertNull($progress->last_processed_id);
    }

    public function test_for_job_returns_existing_record(): void
    {
        DB::table('batch_email_progress')->where('job_type', 'existing_job')->delete();

        // Create first record.
        $progress1 = BatchEmailProgress::forJob('existing_job');
        $progress1->last_processed_id = 100;
        $progress1->save();

        // Get same record.
        $progress2 = BatchEmailProgress::forJob('existing_job');

        $this->assertEquals($progress1->id, $progress2->id);
        $this->assertEquals(100, $progress2->last_processed_id);
    }

    public function test_mark_processed_updates_id_and_timestamp(): void
    {
        DB::table('batch_email_progress')->where('job_type', 'mark_test')->delete();

        $progress = BatchEmailProgress::forJob('mark_test');
        $progress->markProcessed(42);

        $this->assertEquals(42, $progress->last_processed_id);
        $this->assertNotNull($progress->last_processed_at);
        
        // Verify persisted to database.
        $reloaded = BatchEmailProgress::find($progress->id);
        $this->assertEquals(42, $reloaded->last_processed_id);
    }

    public function test_mark_started_sets_started_at(): void
    {
        DB::table('batch_email_progress')->where('job_type', 'started_test')->delete();

        $progress = BatchEmailProgress::forJob('started_test');
        $this->assertNull($progress->started_at);

        $progress->markStarted();

        $this->assertNotNull($progress->started_at);
        
        // Verify persisted to database.
        $reloaded = BatchEmailProgress::find($progress->id);
        $this->assertNotNull($reloaded->started_at);
    }
}
