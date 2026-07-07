<?php

namespace App\Console\Commands\Ssl;

use App\Services\NetlifyCertService;
use Illuminate\Console\Command;

/**
 * Upload renewed SSL certificate to Netlify.
 *
 * This command reads Let's Encrypt certificate files and uploads them
 * to Netlify via their API. It's designed to run after certbot renews
 * the certificate.
 *
 * Usage:
 *   php artisan ssl:netlify-upload              # Upload and notify
 *   php artisan ssl:netlify-upload --dry-run    # Check files only
 *   php artisan ssl:netlify-upload --no-notify  # Upload without email
 */
class RenewNetlifyCertCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'ssl:netlify-upload
                            {--dry-run : Check certificate files exist without uploading}
                            {--no-notify : Skip sending email notification}';

    /**
     * The console command description.
     */
    protected $description = 'Upload renewed Let\'s Encrypt SSL certificate to Netlify';

    /**
     * Execute the console command.
     */
    public function handle(NetlifyCertService $service): int
    {
        $this->info('Netlify SSL Certificate Upload');
        $this->info('==============================');
        $this->newLine();

        // Check configuration
        if (!$service->isConfigured()) {
            $this->error('Netlify is not configured.');
            $this->newLine();
            $this->line('Please set the following environment variables:');
            $this->line('  NETLIFY_TOKEN    - Your Netlify Personal Access Token');
            $this->line('  NETLIFY_SITE_ID  - The Netlify site ID (default: golden-caramel-d2c3a7)');
            $this->newLine();
            $this->line('Generate a token at: https://app.netlify.com/user/applications#personal-access-tokens');

            return Command::FAILURE;
        }

        $certPath = $service->getCertPath();
        $this->info("Certificate path: {$certPath}");
        $this->newLine();

        // Check certificate files exist
        $this->info('Checking certificate files...');
        $check = $service->checkCertificateFiles();

        if (!$check['exists']) {
            $this->error('Missing certificate files: ' . implode(', ', $check['missing']));
            $this->newLine();
            $this->line('Expected files:');
            $this->line("  {$certPath}/cert.pem");
            $this->line("  {$certPath}/privkey.pem");
            $this->line("  {$certPath}/chain.pem");
            $this->newLine();
            $this->line('Run certbot to renew the certificate first.');

            return Command::FAILURE;
        }

        $this->info('  cert.pem     - Found');
        $this->info('  privkey.pem  - Found');
        $this->info('  chain.pem    - Found');
        $this->newLine();

        // Dry run - just check files
        if ($this->option('dry-run')) {
            $this->info('Dry run mode - not uploading to Netlify.');
            $this->line('Remove --dry-run to actually upload the certificate.');

            return Command::SUCCESS;
        }

        // Upload to Netlify
        $this->info('Uploading certificate to Netlify...');
        $result = $service->uploadCertificate();

        if ($result['success']) {
            $this->newLine();
            $this->info($result['message']);
            $this->newLine();

            // Verify the deployed certificate
            $this->info('Verifying deployed certificate...');
            sleep(2); // Brief pause to allow Netlify to deploy
            $verification = $service->verifyCertificate();

            if ($verification['success']) {
                $this->info('  Subject: ' . $verification['subject']);
                $this->info('  Issuer: ' . $verification['issuer']);
                $this->info('  Valid from: ' . $verification['notBefore']);
                $this->info('  Expires: ' . $verification['notAfter']);
            } else {
                $this->warn('  Could not verify: ' . $verification['error']);
            }
            $this->newLine();

            // Send success notification
            if (!$this->option('no-notify')) {
                $this->info('Sending success notification...');
                $service->sendNotification(true, $result['message'], $verification);
                $this->info('Notification sent.');
            }

            $this->newLine();
            $this->line('Verify at: https://www.sslshopper.com/ssl-checker.html#hostname=https://www.ilovefreegle.org/');

            return Command::SUCCESS;
        }

        // Upload failed
        $this->newLine();
        $this->error('Upload failed!');
        $this->error($result['message']);
        $this->newLine();

        // Send failure notification
        if (!$this->option('no-notify')) {
            $this->info('Sending failure notification...');
            $service->sendNotification(false, $result['message']);
            $this->info('Notification sent.');
        }

        return Command::FAILURE;
    }
}
