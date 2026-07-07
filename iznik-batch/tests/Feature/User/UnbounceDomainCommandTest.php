<?php

namespace Tests\Feature\User;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UnbounceDomainCommandTest extends TestCase
{
    public function test_clears_bouncing_flag_for_users_on_domain(): void
    {
        $user = $this->createTestUser(['bouncing' => 1, 'email_preferred' => 'testbounce@clearme.example.com']);

        DB::table('users_emails')
            ->where('userid', $user->id)
            ->update(['bounced' => now()->subDay()]);

        $this->artisan('users:unbounce-domain --domain=clearme.example.com')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'bouncing' => 0]);
        $this->assertNull(DB::table('users_emails')->where('userid', $user->id)->value('bounced'));
    }

    public function test_does_not_affect_users_on_other_domains(): void
    {
        $user = $this->createTestUser(['bouncing' => 1, 'email_preferred' => 'testbounce@other.example.com']);

        DB::table('users_emails')
            ->where('userid', $user->id)
            ->update(['bounced' => now()->subDay()]);

        $this->artisan('users:unbounce-domain --domain=clearme.example.com')
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', ['id' => $user->id, 'bouncing' => 1]);
    }

    public function test_fails_if_domain_not_provided(): void
    {
        $this->artisan('users:unbounce-domain')
            ->assertExitCode(1);
    }

    public function test_outputs_count_of_unbounced_users(): void
    {
        $user = $this->createTestUser(['bouncing' => 1, 'email_preferred' => 'testbounce2@clearme2.example.com']);
        DB::table('users_emails')->where('userid', $user->id)->update(['bounced' => now()->subDay()]);

        $this->artisan('users:unbounce-domain --domain=clearme2.example.com')
            ->expectsOutputToContain('Unbounced')
            ->assertExitCode(0);
    }
}
