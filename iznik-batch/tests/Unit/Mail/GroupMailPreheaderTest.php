<?php

namespace Tests\Unit\Mail;

use App\Mail\Group\AlertNoMessagesMail;
use App\Mail\Group\BoundaryErrorMail;
use App\Mail\Group\ClosedGroupReminderMail;
use App\Mail\Group\CustomisationReminderMail;
use App\Mail\Group\WelcomeReviewMail;
use Tests\TestCase;

/**
 * Preheader (inbox preview) tests for group admin email templates.
 *
 * Each test renders the Blade view directly and asserts the
 * <mj-preview>…</mj-preview> element carries the expected dynamic value,
 * so inbox clients show meaningful content rather than a blank preview.
 */
class GroupMailPreheaderTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Minimal view-data helpers
    // -------------------------------------------------------------------------

    private function alertNoMessagesData(int $count): array
    {
        return [
            'count' => $count,
            'body'  => 'The following groups have not received any messages recently.',
            'email' => 'geeks@ilovefreegle.org',
        ];
    }

    private function boundaryErrorData(string $groupName): array
    {
        return [
            'groupId'      => 42,
            'groupName'    => $groupName,
            'errorMessage' => 'The boundary polygon is not valid GeoJSON.',
            'email'        => 'geeks@ilovefreegle.org',
        ];
    }

    private function closedReminderData(string $groupName): array
    {
        return [
            'groupName' => $groupName,
            'modSite'   => 'https://modtools.org',
            'email'     => 'mod@example.com',
        ];
    }

    private function customisationReminderData(string $groupName): array
    {
        return [
            'groupName'   => $groupName,
            'missing'     => "- Group description\n- Group photo",
            'modSite'     => 'https://modtools.org',
            'mentorsAddr' => 'mentors@ilovefreegle.org',
            'email'       => 'mod@example.com',
        ];
    }

    private function welcomeReviewData(string $groupName): array
    {
        return [
            'groupName'      => $groupName,
            'welcomeContent' => 'Welcome to the group! Please read our rules.',
            'modSite'        => 'https://modtools.org',
            'email'          => 'mod@example.com',
        ];
    }

    // -------------------------------------------------------------------------
    // alert-no-messages
    // -------------------------------------------------------------------------

    public function test_alert_no_messages_preheader_shows_count_for_plural(): void
    {
        $mjml = view('emails.mjml.group.alert-no-messages', $this->alertNoMessagesData(3))->render();

        // Preview must carry the count and the plural "groups".
        $this->assertStringContainsString('<mj-preview>3 groups not receiving messages</mj-preview>', $mjml);
    }

    public function test_alert_no_messages_preheader_shows_singular_for_count_one(): void
    {
        $mjml = view('emails.mjml.group.alert-no-messages', $this->alertNoMessagesData(1))->render();

        // When count === 1 the preview uses "group" (no 's').
        $this->assertStringContainsString('<mj-preview>1 group not receiving messages</mj-preview>', $mjml);
    }

    // -------------------------------------------------------------------------
    // boundary-error
    // -------------------------------------------------------------------------

    public function test_boundary_error_preheader_shows_group_name(): void
    {
        $mjml = view('emails.mjml.group.boundary-error', $this->boundaryErrorData('FreegleTestington'))->render();

        $this->assertStringContainsString('<mj-preview>Invalid CGA/DPA for FreegleTestington</mj-preview>', $mjml);
    }

    // -------------------------------------------------------------------------
    // closed-reminder
    // -------------------------------------------------------------------------

    public function test_closed_reminder_preheader_shows_group_name(): void
    {
        $mjml = view('emails.mjml.group.closed-reminder', $this->closedReminderData('FreegleSometown'))->render();

        $this->assertStringContainsString(
            '<mj-preview>FreegleSometown is currently closed - re-open it from ModTools</mj-preview>',
            $mjml
        );
    }

    // -------------------------------------------------------------------------
    // customisation-reminder
    // -------------------------------------------------------------------------

    public function test_customisation_reminder_preheader_shows_group_name(): void
    {
        $mjml = view('emails.mjml.group.customisation-reminder', $this->customisationReminderData('FreegleNorthampton'))->render();

        $this->assertStringContainsString(
            '<mj-preview>Make FreegleNorthampton more welcoming for your members</mj-preview>',
            $mjml
        );
    }

    // -------------------------------------------------------------------------
    // welcome-review
    // -------------------------------------------------------------------------

    public function test_welcome_review_preheader_shows_group_name(): void
    {
        $mjml = view('emails.mjml.group.welcome-review', $this->welcomeReviewData('FreegleBristol'))->render();

        $this->assertStringContainsString(
            '<mj-preview>Check the welcome mail for FreegleBristol is still up to date</mj-preview>',
            $mjml
        );
    }
}
