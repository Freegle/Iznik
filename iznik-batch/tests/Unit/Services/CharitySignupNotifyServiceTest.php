<?php

namespace Tests\Unit\Services;

use App\Mail\Alert\AlertMail;
use App\Services\CharitySignupNotifyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CharitySignupNotifyServiceTest extends TestCase
{
    protected CharitySignupNotifyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CharitySignupNotifyService();
    }

    private function makeCharity(array $attrs = []): int
    {
        return DB::table('charities')->insertGetId(array_merge([
            'orgname' => 'Test Charity',
            'orgtype' => 'registered',
            'charitynumber' => '123456',
            'contactemail' => 'contact@example.com',
            'contactname' => 'Jane Doe',
            'status' => 'Pending',
            'geeknotified' => 0,
        ], $attrs));
    }

    public function test_notifies_geeks_of_new_signup_and_marks_notified(): void
    {
        Mail::fake();
        $id = $this->makeCharity(['orgname' => 'Acme Reuse']);

        $stats = $this->service->process();

        $this->assertEquals(1, $stats['notified']);
        Mail::assertSent(AlertMail::class, function ($mail) {
            return str_contains($mail->subjectLine, 'Charity Partner')
                && str_contains($mail->recipientEmail, 'geeks');
        });
        $this->assertDatabaseHas('charities', ['id' => $id, 'geeknotified' => 1]);
    }

    public function test_does_not_renotify_already_notified(): void
    {
        Mail::fake();
        $this->makeCharity(['geeknotified' => 1]);

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['notified']);
        Mail::assertNothingSent();
    }

    public function test_no_signups_sends_nothing(): void
    {
        Mail::fake();

        $stats = $this->service->process();

        $this->assertEquals(0, $stats['notified']);
        Mail::assertNothingSent();
    }

    public function test_dry_run_does_not_send_or_mark(): void
    {
        Mail::fake();
        $id = $this->makeCharity();

        $stats = $this->service->process(dryRun: true);

        $this->assertEquals(1, $stats['notified']);
        Mail::assertNothingSent();
        $this->assertDatabaseHas('charities', ['id' => $id, 'geeknotified' => 0]);
    }

    public function test_batches_multiple_into_one_email(): void
    {
        Mail::fake();
        $this->makeCharity(['orgname' => 'A', 'contactemail' => 'a@x.com']);
        $this->makeCharity(['orgname' => 'B', 'contactemail' => 'b@x.com']);

        $stats = $this->service->process();

        $this->assertEquals(2, $stats['notified']);
        // One email summarising both signups.
        Mail::assertSent(AlertMail::class, 1);
    }
}
