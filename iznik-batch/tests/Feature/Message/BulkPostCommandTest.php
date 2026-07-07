<?php

namespace Tests\Feature\Message;

use App\Models\Location;
use App\Services\FreegleApiClient;
use App\Services\TusService;
use Tests\TestCase;

class BulkPostCommandTest extends TestCase
{
    private string $folder;

    private string $email;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = sys_get_temp_dir().'/bulk-post-test-'.uniqid();
        mkdir($this->folder);

        // Create a test user with a known email.
        $user = $this->createTestUser();
        $this->email = $user->emails()->where('preferred', 1)->first()->email;

        // Create a test group.
        $group = $this->createTestGroup([
            'lat' => 50.8225,
            'lng' => -0.1372,
        ]);
        $this->createMembership($user, $group);

        // Create a postcode location.
        Location::create([
            'name' => 'BN1 1AA',
            'type' => 'Postcode',
            'canon' => 'bn11aa',
            'lat' => 50.8225,
            'lng' => -0.1372,
        ]);

        // Store group name for later lookups.
        $this->app->instance('test.group', $group);
        $this->app->instance('test.user', $user);
    }

    protected function tearDown(): void
    {
        // Clean up temp folder.
        if (is_dir($this->folder)) {
            $files = glob($this->folder.'/*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->folder);
        }

        TusService::clearFake();
        FreegleApiClient::clearFake();

        parent::tearDown();
    }

    private function writeCsv(array $rows): void
    {
        $handle = fopen($this->folder.'/items.csv', 'w');
        fputcsv($handle, ['name', 'count', 'photos']);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);
    }

    private function writeBody(string $body = 'Test body text for bulk post.'): void
    {
        file_put_contents($this->folder.'/body.txt', $body);
    }

    private function writePhoto(string $name): void
    {
        // Create a minimal valid JPEG (smallest possible).
        $img = imagecreatetruecolor(1, 1);
        imagejpeg($img, $this->folder.'/'.$name);
        imagedestroy($img);
    }

    public function test_dry_run_shows_items_without_posting(): void
    {
        $this->writeCsv([
            ['Wall Clocks', 6, 'clock.jpg'],
            ['Red Sofa', 1, 'sofa.jpg'],
        ]);
        $this->writeBody();
        $this->writePhoto('clock.jpg');
        $this->writePhoto('sofa.jpg');

        $group = $this->app->make('test.group');

        $result = $this->withoutMockingConsoleOutput()->artisan('messages:bulk-post', [
            'folder' => $this->folder,
            '--email' => $this->email,
            '--postcode' => 'BN1 1AA',
            '--group' => $group->nameshort,
            '--dry-run' => true,
        ]);

        $output = \Artisan::output();
        $this->assertStringContainsString('DRY RUN', $output);
        $this->assertStringContainsString('Wall Clocks', $output);
        $this->assertStringContainsString('qty: 6', $output);
        $this->assertStringContainsString('Red Sofa', $output);
        $this->assertStringContainsString('2 posts', $output);
        $this->assertStringContainsString('PENDING', $output);
        $this->assertEquals(0, $result);
    }

    public function test_fails_if_folder_missing(): void
    {
        $this->artisan('messages:bulk-post', [
            'folder' => '/nonexistent/path',
            '--email' => $this->email,
            '--postcode' => 'BN1 1AA',
        ])
            ->expectsOutputToContain('Folder not found')
            ->assertExitCode(1);
    }

    public function test_fails_if_csv_missing(): void
    {
        $this->writeBody();

        $this->artisan('messages:bulk-post', [
            'folder' => $this->folder,
            '--email' => $this->email,
            '--postcode' => 'BN1 1AA',
        ])
            ->expectsOutputToContain('items.csv not found')
            ->assertExitCode(1);
    }

    public function test_fails_if_body_missing(): void
    {
        $this->writeCsv([['Sofa', 1, '']]);

        $this->artisan('messages:bulk-post', [
            'folder' => $this->folder,
            '--email' => $this->email,
            '--postcode' => 'BN1 1AA',
        ])
            ->expectsOutputToContain('body.txt not found')
            ->assertExitCode(1);
    }

    public function test_fails_if_email_not_found(): void
    {
        $this->writeCsv([['Sofa', 1, '']]);
        $this->writeBody();

        $this->artisan('messages:bulk-post', [
            'folder' => $this->folder,
            '--email' => 'nobody@nonexistent.example',
            '--postcode' => 'BN1 1AA',
        ])
            ->expectsOutputToContain('No user found')
            ->assertExitCode(1);
    }

    public function test_fails_if_postcode_not_found(): void
    {
        $this->writeCsv([['Sofa', 1, '']]);
        $this->writeBody();

        $this->artisan('messages:bulk-post', [
            'folder' => $this->folder,
            '--email' => $this->email,
            '--postcode' => 'ZZ99 9ZZ',
        ])
            ->expectsOutputToContain('Postcode not found')
            ->assertExitCode(1);
    }

    public function test_fails_if_photo_file_missing(): void
    {
        $this->writeCsv([['Sofa', 1, 'nonexistent.jpg']]);
        $this->writeBody();

        $group = $this->app->make('test.group');

        $this->artisan('messages:bulk-post', [
            'folder' => $this->folder,
            '--email' => $this->email,
            '--postcode' => 'BN1 1AA',
            '--group' => $group->nameshort,
        ])
            ->expectsOutputToContain('Missing photo: nonexistent.jpg')
            ->assertExitCode(1);
    }

    public function test_csv_parsing_handles_multiple_photos(): void
    {
        $this->writeCsv([
            ['Chairs', 4, 'chair1.jpg;chair2.jpg'],
        ]);
        $this->writeBody();
        $this->writePhoto('chair1.jpg');
        $this->writePhoto('chair2.jpg');

        $group = $this->app->make('test.group');

        $result = $this->withoutMockingConsoleOutput()->artisan('messages:bulk-post', [
            'folder' => $this->folder,
            '--email' => $this->email,
            '--postcode' => 'BN1 1AA',
            '--group' => $group->nameshort,
            '--dry-run' => true,
        ]);

        $output = \Artisan::output();
        $this->assertStringContainsString('Chairs', $output);
        $this->assertStringContainsString('qty: 4', $output);
        $this->assertStringContainsString('2 photos', $output);
        $this->assertEquals(0, $result);
    }

    public function test_csv_parsing_handles_comma_separated_photos(): void
    {
        $this->writeCsv([
            ['Desk', 1, 'desk1.jpg,desk2.jpg'],
        ]);
        $this->writeBody();
        $this->writePhoto('desk1.jpg');
        $this->writePhoto('desk2.jpg');

        $group = $this->app->make('test.group');

        $this->artisan('messages:bulk-post', [
            'folder' => $this->folder,
            '--email' => $this->email,
            '--postcode' => 'BN1 1AA',
            '--group' => $group->nameshort,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Desk')
            ->expectsOutputToContain('2 photos')
            ->assertExitCode(0);
    }

    public function test_csv_skips_blank_rows(): void
    {
        $this->writeCsv([
            ['Sofa', 1, 'sofa.jpg'],
            ['', 0, ''],
            ['Table', 2, ''],
        ]);
        $this->writeBody();
        $this->writePhoto('sofa.jpg');

        $group = $this->app->make('test.group');

        $this->artisan('messages:bulk-post', [
            'folder' => $this->folder,
            '--email' => $this->email,
            '--postcode' => 'BN1 1AA',
            '--group' => $group->nameshort,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('2 posts')
            ->assertExitCode(0);
    }

    public function test_posting_creates_messages_via_api_with_forcepending(): void
    {
        $this->writeCsv([
            ['Red Sofa', 1, 'sofa.jpg'],
            ['Wall Clock', 1, ''],
        ]);
        $this->writeBody('Furniture from Mind in Brighton.');
        $this->writePhoto('sofa.jpg');

        $group = $this->app->make('test.group');

        // Mock TUS: create file (201) + verify (200) + upload (204)
        TusService::fake([
            ['status' => 201, 'headers' => ['Location' => 'http://tus.test/files/abc123'], 'body' => ''],
            ['status' => 200, 'headers' => [], 'body' => ''],
            ['status' => 204, 'headers' => [], 'body' => ''],
        ]);

        // Mock API responses. The API handles forcepending server-side.
        FreegleApiClient::fake([
            ['body' => ['ret' => 0, 'jwt' => 'test-jwt-token']],   // Auth
            ['body' => ['ret' => 0, 'id' => 999]],                  // POST /image for sofa.jpg
            ['body' => ['ret' => 0, 'id' => 10001]],                // PUT /message for Red Sofa
            ['body' => ['ret' => 0]],                                // POST /message JoinAndPost (forcepending=true)
            ['body' => ['ret' => 0, 'id' => 10002]],                // PUT /message for Wall Clock
            ['body' => ['ret' => 0]],                                // POST /message JoinAndPost (forcepending=true)
        ]);

        $this->artisan('messages:bulk-post', [
            'folder' => $this->folder,
            '--email' => $this->email,
            '--postcode' => 'BN1 1AA',
            '--group' => $group->nameshort,
        ])
            ->expectsOutputToContain('Authenticated')
            ->expectsOutputToContain('Red Sofa')
            ->expectsOutputToContain('Wall Clock')
            ->expectsOutputToContain('2 posted, 0 failed')
            ->assertExitCode(0);
    }

    public function test_first_option_posts_only_first_item(): void
    {
        $this->writeCsv([
            ['Red Sofa', 1, ''],
            ['Wall Clock', 1, ''],
            ['Table', 1, ''],
        ]);
        $this->writeBody('Test body.');

        $group = $this->app->make('test.group');

        FreegleApiClient::fake([
            ['body' => ['ret' => 0, 'jwt' => 'test-jwt-token']],   // Auth
            ['body' => ['ret' => 0, 'id' => 10001]],                // PUT /message for Red Sofa
            ['body' => ['ret' => 0]],                                // POST /message JoinAndPost
        ]);

        $this->artisan('messages:bulk-post', [
            'folder' => $this->folder,
            '--email' => $this->email,
            '--postcode' => 'BN1 1AA',
            '--group' => $group->nameshort,
            '--first' => true,
        ])
            ->expectsOutputToContain('Red Sofa')
            ->expectsOutputToContain('1 posted, 0 failed')
            ->doesntExpectOutputToContain('Wall Clock')
            ->assertExitCode(0);
    }

    public function test_handles_api_auth_failure(): void
    {
        $this->writeCsv([['Sofa', 1, '']]);
        $this->writeBody();

        $group = $this->app->make('test.group');

        FreegleApiClient::fake([
            ['body' => ['ret' => 3, 'status' => 'Login failed']],
        ]);

        $this->artisan('messages:bulk-post', [
            'folder' => $this->folder,
            '--email' => $this->email,
            '--postcode' => 'BN1 1AA',
            '--group' => $group->nameshort,
        ])
            ->expectsOutputToContain('Failed to authenticate')
            ->assertExitCode(1);
    }

    public function test_count_defaults_to_1_when_zero(): void
    {
        $this->writeCsv([
            ['Bookcase', 0, ''],
        ]);
        $this->writeBody();

        $group = $this->app->make('test.group');

        $this->artisan('messages:bulk-post', [
            'folder' => $this->folder,
            '--email' => $this->email,
            '--postcode' => 'BN1 1AA',
            '--group' => $group->nameshort,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('defaulting to 1')
            ->expectsOutputToContain('Bookcase')
            ->assertExitCode(0);
    }

    public function test_dry_run_shows_quantity_when_greater_than_1(): void
    {
        $this->writeCsv([
            ['Blue Chairs', 14, ''],
        ]);
        $this->writeBody();

        $group = $this->app->make('test.group');

        $result = $this->withoutMockingConsoleOutput()->artisan('messages:bulk-post', [
            'folder' => $this->folder,
            '--email' => $this->email,
            '--postcode' => 'BN1 1AA',
            '--group' => $group->nameshort,
            '--dry-run' => true,
        ]);

        $output = \Artisan::output();
        $this->assertStringContainsString('Blue Chairs', $output);
        $this->assertStringContainsString('qty: 14', $output, "Output was: {$output}");
        $this->assertEquals(0, $result);
    }

    public function test_dry_run_omits_quantity_when_1(): void
    {
        $this->writeCsv([
            ['Red Sofa', 1, ''],
        ]);
        $this->writeBody();

        $group = $this->app->make('test.group');

        $this->artisan('messages:bulk-post', [
            'folder' => $this->folder,
            '--email' => $this->email,
            '--postcode' => 'BN1 1AA',
            '--group' => $group->nameshort,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Red Sofa')
            ->doesntExpectOutputToContain('qty:')
            ->assertExitCode(0);
    }

    public function test_auto_detects_nearest_group(): void
    {
        $this->writeCsv([['Sofa', 1, '']]);
        $this->writeBody();

        $this->artisan('messages:bulk-post', [
            'folder' => $this->folder,
            '--email' => $this->email,
            '--postcode' => 'BN1 1AA',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Auto-detected nearest group')
            ->assertExitCode(0);
    }

    public function test_fails_if_group_not_found(): void
    {
        $this->writeCsv([['Sofa', 1, '']]);
        $this->writeBody();

        $this->artisan('messages:bulk-post', [
            'folder' => $this->folder,
            '--email' => $this->email,
            '--postcode' => 'BN1 1AA',
            '--group' => 'NonexistentGroup_'.uniqid(),
        ])
            ->expectsOutputToContain('Group not found')
            ->assertExitCode(1);
    }

    public function test_deadline_shown_in_dry_run(): void
    {
        $this->writeCsv([['Sofa', 1, '']]);
        // Rewrite with deadline column.
        $handle = fopen($this->folder.'/items.csv', 'w');
        fputcsv($handle, ['name', 'count', 'photos', 'deadline']);
        fputcsv($handle, ['Sofa', 1, '', '2026-04-03']);
        fclose($handle);
        $this->writeBody();

        $group = $this->app->make('test.group');

        $result = $this->withoutMockingConsoleOutput()->artisan('messages:bulk-post', [
            'folder' => $this->folder,
            '--email' => $this->email,
            '--postcode' => 'BN1 1AA',
            '--group' => $group->nameshort,
            '--dry-run' => true,
        ]);

        $output = \Artisan::output();
        $this->assertStringContainsString('deadline: 2026-04-03', $output);
        $this->assertEquals(0, $result);
    }

    public function test_cross_post_groups_shown_in_dry_run(): void
    {
        $this->writeCsv([['Sofa', 1, '']]);
        $this->writeBody();

        $primaryGroup = $this->app->make('test.group');
        $crossGroup = $this->createTestGroup(['lat' => 50.83, 'lng' => -0.17]);

        $result = $this->withoutMockingConsoleOutput()->artisan('messages:bulk-post', [
            'folder'              => $this->folder,
            '--email'             => $this->email,
            '--postcode'          => 'BN1 1AA',
            '--group'             => $primaryGroup->nameshort,
            '--cross-post-groups' => $crossGroup->nameshort,
            '--dry-run'           => true,
        ]);

        $output = \Artisan::output();
        $this->assertStringContainsString($crossGroup->nameshort, $output);
        $this->assertStringContainsString('cross-post', $output);
        $this->assertStringContainsString('1 items × 2 groups = 2 posts', $output);
        $this->assertEquals(0, $result);
    }

    public function test_skip_primary_group_dry_run_excludes_primary(): void
    {
        $this->writeCsv([['Sofa', 1, '']]);
        $this->writeBody();

        $primaryGroup = $this->app->make('test.group');
        $crossGroup = $this->createTestGroup(['lat' => 50.83, 'lng' => -0.17]);

        $result = $this->withoutMockingConsoleOutput()->artisan('messages:bulk-post', [
            'folder'               => $this->folder,
            '--email'              => $this->email,
            '--postcode'           => 'BN1 1AA',
            '--group'              => $primaryGroup->nameshort,
            '--cross-post-groups'  => $crossGroup->nameshort,
            '--skip-primary-group' => true,
            '--dry-run'            => true,
        ]);

        $output = \Artisan::output();
        $this->assertStringContainsString('[SKIPPED]', $output);
        $this->assertStringContainsString('1 items × 1 groups = 1 posts', $output);
        $this->assertEquals(0, $result);
    }

    public function test_skip_primary_group_without_cross_post_groups_fails(): void
    {
        $this->writeCsv([['Sofa', 1, '']]);
        $this->writeBody();

        $group = $this->app->make('test.group');

        $this->artisan('messages:bulk-post', [
            'folder'               => $this->folder,
            '--email'              => $this->email,
            '--postcode'           => 'BN1 1AA',
            '--group'              => $group->nameshort,
            '--skip-primary-group' => true,
        ])
            ->expectsOutputToContain('--skip-primary-group requires --cross-post-groups')
            ->assertExitCode(1);
    }

    public function test_cross_posting_posts_to_each_group_with_modified_body(): void
    {
        $this->writeCsv([['Red Sofa', 1, '']]);
        $this->writeBody('Original body text.');

        $primaryGroup = $this->app->make('test.group');
        $crossGroup = $this->createTestGroup(['lat' => 50.83, 'lng' => -0.17]);

        FreegleApiClient::fake([
            ['body' => ['ret' => 0, 'jwt' => 'test-jwt-token']],  // Auth
            ['body' => ['ret' => 0, 'id' => 10001]],               // PUT /message — primary group
            ['body' => ['ret' => 0]],                               // POST JoinAndPost — primary group
            ['body' => ['ret' => 0, 'id' => 10002]],               // PUT /message — cross-post group
            ['body' => ['ret' => 0]],                               // POST JoinAndPost — cross-post group
        ]);

        $this->artisan('messages:bulk-post', [
            'folder'              => $this->folder,
            '--email'             => $this->email,
            '--postcode'          => 'BN1 1AA',
            '--group'             => $primaryGroup->nameshort,
            '--cross-post-groups' => $crossGroup->nameshort,
        ])
            ->expectsOutputToContain($crossGroup->nameshort)
            ->expectsOutputToContain('2 posted, 0 failed')
            ->assertExitCode(0);
    }

    public function test_skip_primary_posts_only_to_cross_post_groups(): void
    {
        $this->writeCsv([['Red Sofa', 1, '']]);
        $this->writeBody('Original body text.');

        $primaryGroup = $this->app->make('test.group');
        $crossGroup = $this->createTestGroup(['lat' => 50.83, 'lng' => -0.17]);

        FreegleApiClient::fake([
            ['body' => ['ret' => 0, 'jwt' => 'test-jwt-token']],  // Auth
            ['body' => ['ret' => 0, 'id' => 10002]],               // PUT /message — cross-post group only
            ['body' => ['ret' => 0]],                               // POST JoinAndPost — cross-post group only
        ]);

        $this->artisan('messages:bulk-post', [
            'folder'               => $this->folder,
            '--email'              => $this->email,
            '--postcode'           => 'BN1 1AA',
            '--group'              => $primaryGroup->nameshort,
            '--cross-post-groups'  => $crossGroup->nameshort,
            '--skip-primary-group' => true,
        ])
            ->expectsOutputToContain('1 posted, 0 failed')
            ->expectsOutputToContain($crossGroup->nameshort)
            ->assertExitCode(0);
    }

    public function test_cross_post_warns_and_skips_unknown_group(): void
    {
        $this->writeCsv([['Sofa', 1, '']]);
        $this->writeBody();

        $group = $this->app->make('test.group');

        $this->artisan('messages:bulk-post', [
            'folder'             => $this->folder,
            '--email'            => $this->email,
            '--postcode'         => 'BN1 1AA',
            '--group'            => $group->nameshort,
            '--cross-post-groups' => 'NonexistentGroup_'.uniqid(),
            '--dry-run'          => true,
        ])
            ->expectsOutputToContain('Cross-post group not found')
            ->assertExitCode(0);
    }

    public function test_cross_post_warns_and_skips_when_same_as_primary(): void
    {
        $this->writeCsv([['Sofa', 1, '']]);
        $this->writeBody();

        $group = $this->app->make('test.group');

        $this->artisan('messages:bulk-post', [
            'folder'             => $this->folder,
            '--email'            => $this->email,
            '--postcode'         => 'BN1 1AA',
            '--group'            => $group->nameshort,
            '--cross-post-groups' => $group->nameshort,
            '--dry-run'          => true,
        ])
            ->expectsOutputToContain('same as the primary group')
            ->assertExitCode(0);
    }

    public function test_cross_post_falls_back_to_nameshort_when_namedisplay_empty(): void
    {
        $this->writeCsv([['Sofa', 1, '']]);
        $this->writeBody('Original body.');

        // Create a primary group with empty namedisplay; nameshort is auto-generated.
        $primaryGroup = $this->createTestGroup(['namedisplay' => '']);
        $crossGroup = $this->createTestGroup(['lat' => 50.83, 'lng' => -0.17]);

        // Summary line must show nameshort (not blank) in the X-post prefix notice.
        $result = $this->withoutMockingConsoleOutput()->artisan('messages:bulk-post', [
            'folder'              => $this->folder,
            '--email'             => $this->email,
            '--postcode'          => 'BN1 1AA',
            '--group'             => $primaryGroup->nameshort,
            '--cross-post-groups' => $crossGroup->nameshort,
            '--dry-run'           => true,
        ]);

        $output = \Artisan::output();
        // Should show the nameshort in the group summary line, not a blank.
        $this->assertStringContainsString($primaryGroup->nameshort, $output);
        $this->assertStringNotContainsString('no takers so far on .', $output);
        $this->assertEquals(0, $result);
    }

    public function test_invalid_deadline_format_rejected(): void
    {
        $handle = fopen($this->folder.'/items.csv', 'w');
        fputcsv($handle, ['name', 'count', 'photos', 'deadline']);
        fputcsv($handle, ['Sofa', 1, '', 'April 3rd']);
        fclose($handle);
        $this->writeBody();

        $group = $this->app->make('test.group');

        $this->artisan('messages:bulk-post', [
            'folder' => $this->folder,
            '--email' => $this->email,
            '--postcode' => 'BN1 1AA',
            '--group' => $group->nameshort,
        ])
            ->expectsOutputToContain('Invalid deadline')
            ->assertExitCode(1);
    }

    public function test_deadline_column_is_optional(): void
    {
        // CSV without deadline column should still work.
        $this->writeCsv([['Sofa', 1, '']]);
        $this->writeBody();

        $group = $this->app->make('test.group');

        $this->artisan('messages:bulk-post', [
            'folder' => $this->folder,
            '--email' => $this->email,
            '--postcode' => 'BN1 1AA',
            '--group' => $group->nameshort,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Sofa')
            ->assertExitCode(0);
    }

    public function test_reports_partial_failure(): void
    {
        $this->writeCsv([
            ['Sofa', 1, ''],
            ['Table', 1, ''],
        ]);
        $this->writeBody();

        $group = $this->app->make('test.group');

        FreegleApiClient::fake([
            // Auth
            ['body' => ['ret' => 0, 'jwt' => 'test-jwt-token']],
            // PUT /message for Sofa — success
            ['body' => ['ret' => 0, 'id' => 99999]],
            // POST /message JoinAndPost for Sofa — success
            ['body' => ['ret' => 0]],
            // PUT /message for Table — fails
            ['body' => ['ret' => 1, 'status' => 'Error']],
        ]);

        $this->artisan('messages:bulk-post', [
            'folder' => $this->folder,
            '--email' => $this->email,
            '--postcode' => 'BN1 1AA',
            '--group' => $group->nameshort,
        ])
            ->expectsOutputToContain('1 posted, 1 failed')
            ->assertExitCode(1);
    }
}
