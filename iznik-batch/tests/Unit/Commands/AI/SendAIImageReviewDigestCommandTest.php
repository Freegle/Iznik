<?php

namespace Tests\Unit\Commands\AI;

use App\Console\Commands\Mail\SendAIImageReviewDigestCommand;
use App\Mail\AI\AIImageReviewDigestMail;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SendAIImageReviewDigestCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clean up test data.
        DB::table('microactions')->where('actiontype', 'AIImageReview')->delete();
        DB::table('ai_images')->where('name', 'LIKE', 'test-digest-%')->delete();
    }

    protected function tearDown(): void
    {
        DB::table('microactions')->where('actiontype', 'AIImageReview')->delete();
        DB::table('ai_images')->where('name', 'LIKE', 'test-digest-%')->delete();
        DB::table('users')->where('firstname', 'LIKE', 'test-digest-%')->where('lastname', 'Test')->delete();

        parent::tearDown();
    }

    public function test_mailable_subject_includes_stats(): void
    {
        $mail = new AIImageReviewDigestMail(
            todayVerdicts: 15,
            totalReviewed: 50,
            totalImages: 200,
            needsImproving: 8,
            topProblems: [],
        );

        $envelope = $mail->envelope();
        $this->assertStringContainsString('15 today', $envelope->subject);
        $this->assertStringContainsString('25%', $envelope->subject);
        $this->assertStringContainsString('8 need improving', $envelope->subject);
    }

    public function test_mailable_from_geeks(): void
    {
        $mail = new AIImageReviewDigestMail(0, 0, 0, 0, []);

        $envelope = $mail->envelope();
        $this->assertEquals(config('freegle.mail.geeks_addr'), $envelope->from->address);
    }

    public function test_mailable_builds_without_errors(): void
    {
        $mail = new AIImageReviewDigestMail(
            todayVerdicts: 5,
            totalReviewed: 10,
            totalImages: 100,
            needsImproving: 3,
            topProblems: [
                [
                    'name' => 'Broken Chair',
                    'usage_count' => 50,
                    'approve_count' => 1,
                    'reject_count' => 4,
                    'people_count' => 2,
                ],
            ],
        );

        $builtMail = $mail->build();
        $this->assertNotNull($builtMail);
        $this->assertTrue($builtMail->hasTo(config('freegle.mail.geeks_addr')));
    }

    public function test_outlier_detection_excludes_biased_voters(): void
    {
        // Create test users.
        $alwaysApproveUser = $this->createTestUserForDigest('test-digest-approve');
        $alwaysRejectUser = $this->createTestUserForDigest('test-digest-reject');
        $normalUser = $this->createTestUserForDigest('test-digest-normal');

        // Create test AI images.
        $imgId = $this->createTestAIImage('test-digest-sofa', 100);

        // Always-approve user: 12 votes all Approve.
        for ($i = 0; $i < 12; $i++) {
            $otherImg = $this->createTestAIImage("test-digest-other-a-{$i}", 10);
            DB::table('microactions')->insert([
                'actiontype' => 'AIImageReview',
                'userid' => $alwaysApproveUser,
                'aiimageid' => $otherImg,
                'result' => 'Approve',
                'containspeople' => 0,
            ]);
        }

        // Always-reject user: 12 votes all Reject.
        for ($i = 0; $i < 12; $i++) {
            $otherImg = $this->createTestAIImage("test-digest-other-r-{$i}", 10);
            DB::table('microactions')->insert([
                'actiontype' => 'AIImageReview',
                'userid' => $alwaysRejectUser,
                'aiimageid' => $otherImg,
                'result' => 'Reject',
                'containspeople' => 0,
            ]);
        }

        $command = new SendAIImageReviewDigestCommand;
        $outliers = $command->getOutlierVoterIds();

        $this->assertContains($alwaysApproveUser, $outliers, 'Always-approve user should be an outlier');
        $this->assertContains($alwaysRejectUser, $outliers, 'Always-reject user should be an outlier');
        $this->assertNotContains($normalUser, $outliers, 'Normal user should not be an outlier');
    }

    public function test_zero_division_handled(): void
    {
        // No images at all — should not crash.
        $mail = new AIImageReviewDigestMail(0, 0, 0, 0, []);
        $envelope = $mail->envelope();
        $this->assertStringContainsString('0%', $envelope->subject);
    }

    public function test_preheader_shows_verdicts_and_needs_improving(): void
    {
        // The inbox preview should immediately surface the two headline stats —
        // how many verdicts were cast today and how many images still need work.
        $html = view('emails.mjml.admin.ai-image-review-digest', [
            'todayVerdicts'   => 42,
            'totalReviewed'   => 80,
            'totalImages'     => 200,
            'percentReviewed' => 40,
            'needsImproving'  => 7,
            'topProblems'     => [],
            'siteName'        => config('freegle.branding.name', 'Freegle'),
        ])->render();

        $this->assertStringContainsString(
            '<mj-preview>42 verdicts today, 7 need improving</mj-preview>',
            $html,
            'Preheader must show the verdict count and needs-improving count'
        );
    }

    protected function createTestUserForDigest(string $name): int
    {
        DB::table('users')->insert([
            'firstname' => $name,
            'lastname' => 'Test',
            'systemrole' => 'User',
        ]);

        return (int) DB::table('users')
            ->where('firstname', $name)
            ->where('lastname', 'Test')
            ->orderByDesc('id')
            ->value('id');
    }

    protected function createTestAIImage(string $name, int $usageCount): int
    {
        DB::table('ai_images')->insert([
            'name' => $name,
            'externaluid' => 'freegletusd-test-' . $name,
            'usage_count' => $usageCount,
        ]);

        return (int) DB::table('ai_images')
            ->where('name', $name)
            ->orderByDesc('id')
            ->value('id');
    }
}
