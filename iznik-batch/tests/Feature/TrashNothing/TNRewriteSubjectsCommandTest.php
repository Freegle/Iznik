<?php

namespace Tests\Feature\TrashNothing;

use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * tn:rewrite-subjects gives posts the TN API ingested before the bracket rewrite
 * the "(Area OUTWARD)" bracket ingestion builds now.
 */
class TNRewriteSubjectsCommandTest extends TestCase
{
    private int $areaId;
    private int $pcId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->areaId = (int) DB::table('locations')->insertGetId(['name' => 'Stoneygate', 'type' => 'Polygon']);
        $this->pcId   = (int) DB::table('locations')->insertGetId(['name' => 'LE2 3HE', 'type' => 'Postcode', 'areaid' => $this->areaId]);
    }

    protected function tearDown(): void
    {
        DB::table('locations')->whereIn('id', [$this->areaId, $this->pcId])->delete();
        parent::tearDown();
    }

    private function tnApiMessage(array $attributes = []): Message
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();

        return $this->createTestMessage($user, $group, array_merge([
            'sourceheader'     => 'TN-API',
            'tnpostid'         => 'tn-' . uniqid(),
            'subject'          => 'OFFER: Double bed Mattress (LE2 3HE)',
            'suggestedsubject' => 'OFFER: Double bed Mattress (LE2 3HE)',
            'locationid'       => $this->pcId,
            'arrival'          => now(),
        ], $attributes));
    }

    public function test_dry_run_reports_and_writes_nothing(): void
    {
        $message = $this->tnApiMessage();

        $this->artisan('tn:rewrite-subjects')
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('OFFER: Double bed Mattress (Stoneygate LE2)')
            ->assertSuccessful();

        $this->assertSame('OFFER: Double bed Mattress (LE2 3HE)', $message->fresh()->subject);
    }

    public function test_apply_rewrites_the_subject_and_the_suggested_subject(): void
    {
        $message = $this->tnApiMessage();

        $this->artisan('tn:rewrite-subjects', ['--apply' => true])->assertSuccessful();

        $fresh = $message->fresh();
        $this->assertSame('OFFER: Double bed Mattress (Stoneygate LE2)', $fresh->subject);
        $this->assertSame('OFFER: Double bed Mattress (Stoneygate LE2)', $fresh->suggestedsubject);
    }

    public function test_a_moderator_edited_suggested_subject_is_kept(): void
    {
        $message = $this->tnApiMessage(['suggestedsubject' => 'OFFER: Mattress, double (Leicester)']);

        $this->artisan('tn:rewrite-subjects', ['--apply' => true])->assertSuccessful();

        $fresh = $message->fresh();
        $this->assertSame('OFFER: Double bed Mattress (Stoneygate LE2)', $fresh->subject);
        $this->assertSame('OFFER: Mattress, double (Leicester)', $fresh->suggestedsubject);
    }

    public function test_only_tn_api_posts_are_touched_and_a_right_subject_is_left_alone(): void
    {
        $email = $this->tnApiMessage(['sourceheader' => 'TN-native-app']);
        $right = $this->tnApiMessage(['subject' => 'OFFER: Double bed Mattress (Stoneygate LE2)']);
        $noLoc = $this->tnApiMessage(['locationid' => null]);

        $this->artisan('tn:rewrite-subjects', ['--apply' => true])->assertSuccessful();

        $this->assertSame('OFFER: Double bed Mattress (LE2 3HE)', $email->fresh()->subject, 'Email-path posts keep the subject TN formatted');
        $this->assertSame('OFFER: Double bed Mattress (Stoneygate LE2)', $right->fresh()->subject);
        $this->assertSame('OFFER: Double bed Mattress', $noLoc->fresh()->subject, 'No location: the bracket goes');
    }

    public function test_since_limits_the_run(): void
    {
        $old = $this->tnApiMessage(['arrival' => now()->subDays(10)]);
        $new = $this->tnApiMessage();

        $this->artisan('tn:rewrite-subjects', ['--apply' => true, '--since' => now()->subDay()->toDateString()])->assertSuccessful();

        $this->assertSame('OFFER: Double bed Mattress (LE2 3HE)', $old->fresh()->subject);
        $this->assertSame('OFFER: Double bed Mattress (Stoneygate LE2)', $new->fresh()->subject);
    }
}
