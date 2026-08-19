<?php

namespace Tests\Unit\Services\Mail\Deferrals;

use App\Services\Mail\Deferrals\MxGrouper;
use Tests\TestCase;

class MxGrouperTest extends TestCase
{
    // ===================================================================
    // Grouping
    //
    // The whole point of grouping by relay rather than by recipient domain:
    // on 2026-08-15 one Yahoo block took out yahoo.co.uk, yahoo.com, ymail,
    // rocketmail, aol.com, aol.co.uk, aim.com and sky.com at once, because
    // they all relay through *.am0.yahoodns.net.
    // ===================================================================

    public function test_collapses_yahoo_relay_machines_into_one_family(): void
    {
        $this->assertSame('yahoodns.net', MxGrouper::group('mta5.am0.yahoodns.net'));
        $this->assertSame('yahoodns.net', MxGrouper::group('mta6.am0.yahoodns.net'));
        $this->assertSame('yahoodns.net', MxGrouper::group('mta7.am0.yahoodns.net'));
    }

    public function test_grouping_is_case_and_trailing_dot_insensitive(): void
    {
        $this->assertSame('yahoodns.net', MxGrouper::group('MTA7.AM0.YahooDNS.NET.'));
    }

    public function test_keeps_an_extra_label_for_shared_hosting_platforms(): void
    {
        // Without this, one customer's problem on a big filtering platform
        // would suppress mail to every organisation behind it.
        $this->assertSame(
            'acme.mail.protection.outlook.com',
            MxGrouper::group('acme.mail.protection.outlook.com')
        );
        $this->assertNotSame(
            MxGrouper::group('acme.mail.protection.outlook.com'),
            MxGrouper::group('other.mail.protection.outlook.com')
        );
    }

    public function test_never_groups_down_to_a_public_suffix(): void
    {
        // "co.uk" as a group would suppress an entire country's mail.
        $this->assertSame('talktalk.co.uk', MxGrouper::group('mx1.talktalk.co.uk'));
    }

    public function test_a_bare_ip_is_its_own_group(): void
    {
        $this->assertSame('198.51.100.5', MxGrouper::group('198.51.100.5'));
    }

    public function test_a_two_label_host_is_already_its_own_group(): void
    {
        $this->assertSame('example.com', MxGrouper::group('example.com'));
    }

    // ===================================================================
    // Pulling the relay out of a delay_reason
    // ===================================================================

    public function test_extracts_relay_from_a_said_style_delay_reason(): void
    {
        $reason = 'host mta7.am0.yahoodns.net[67.195.228.94] said: 421 4.7.0 [TSS04] '
            . 'Messages from 185.53.57.161 temporarily deferred due to unexpected volume '
            . 'or user complaints - 4.16.55.1 (in reply to MAIL FROM command)';

        $this->assertSame('yahoodns.net', MxGrouper::fromDelayReason($reason));
    }

    public function test_extracts_relay_from_a_connect_style_delay_reason(): void
    {
        // A connection-level failure never reaches an SMTP dialog, so there
        // is no "said:" clause and no SMTP code to key off.
        $reason = 'connect to mx1.example.com[198.51.100.5]:25: Connection timed out';

        $this->assertSame('example.com', MxGrouper::fromDelayReason($reason));
    }

    public function test_extracts_relay_from_a_quota_delay_reason(): void
    {
        $reason = 'host mx.example.com[203.0.113.10] said: 452 4.2.2 The email account '
            . 'that you tried to reach is over quota (in reply to RCPT TO command)';

        $this->assertSame('example.com', MxGrouper::fromDelayReason($reason));
    }

    public function test_blames_nobody_for_a_purely_local_failure(): void
    {
        // "mail transport unavailable" is our problem, not a provider's.
        // Attributing it to one would suppress entirely the wrong mail.
        $this->assertNull(MxGrouper::fromDelayReason('mail transport unavailable'));
        $this->assertNull(MxGrouper::fromDelayReason('delivery temporarily suspended'));
    }

    // ===================================================================
    // Naming the provider
    // ===================================================================

    public function test_names_the_provider_a_member_would_recognise(): void
    {
        $this->assertSame('Yahoo', MxGrouper::providerName('yahoodns.net'));
        $this->assertSame('Gmail', MxGrouper::providerName('google.com'));
        $this->assertSame('Microsoft', MxGrouper::providerName('acme.mail.protection.outlook.com'));
    }

    public function test_returns_null_for_a_relay_we_have_no_friendly_name_for(): void
    {
        $this->assertNull(MxGrouper::providerName('mx.somesmallisp.example'));
    }
}
