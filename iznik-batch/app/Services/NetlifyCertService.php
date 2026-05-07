<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Service for uploading SSL certificates to Netlify.
 *
 * Reads Let's Encrypt certificate files and uploads them to Netlify
 * via their API. Sends email notifications on success or failure.
 */
class NetlifyCertService
{
    /**
     * Netlify API token.
     */
    protected string $token;

    /**
     * Netlify site ID.
     */
    protected string $siteId;

    /**
     * Path to Let's Encrypt certificate files.
     */
    protected string $certPath;

    /**
     * Email address for alerts.
     */
    protected string $geekAlertsEmail;

    public function __construct()
    {
        $this->token = config('freegle.netlify.token', '');
        $this->siteId = config('freegle.netlify.site_id', '');
        $this->certPath = config('freegle.netlify.cert_path', '');
        $this->geekAlertsEmail = config('freegle.mail.geek_alerts_addr', 'geek-alerts@ilovefreegle.org');
    }

    /**
     * Check if the service is properly configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->token) && !empty($this->siteId);
    }

    /**
     * Get the certificate path.
     */
    public function getCertPath(): string
    {
        return $this->certPath;
    }

    /**
     * Check if all required certificate files exist.
     *
     * @return array{exists: bool, missing: array<string>}
     */
    public function checkCertificateFiles(): array
    {
        $files = ['cert.pem', 'privkey.pem', 'chain.pem'];
        $missing = [];

        foreach ($files as $file) {
            $path = "{$this->certPath}/{$file}";
            if (!file_exists($path)) {
                $missing[] = $file;
            }
        }

        return [
            'exists' => empty($missing),
            'missing' => $missing,
        ];
    }

    /**
     * Upload the certificate to Netlify.
     *
     * @return array{success: bool, message: string, response?: array}
     */
    public function uploadCertificate(): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'message' => 'Netlify token or site ID not configured. Set NETLIFY_TOKEN and NETLIFY_SITE_ID in .env',
            ];
        }

        // Check certificate files exist
        $check = $this->checkCertificateFiles();
        if (!$check['exists']) {
            return [
                'success' => false,
                'message' => "Certificate files not found in {$this->certPath}: " . implode(', ', $check['missing']),
            ];
        }

        // Read certificate files
        $cert = file_get_contents("{$this->certPath}/cert.pem");
        $key = file_get_contents("{$this->certPath}/privkey.pem");
        $chain = file_get_contents("{$this->certPath}/chain.pem");

        if ($cert === false || $key === false || $chain === false) {
            return [
                'success' => false,
                'message' => "Failed to read certificate files from {$this->certPath}",
            ];
        }

        // Make API request to Netlify
        // API requires certificate data as query parameters (URL-encoded)
        $url = "https://api.netlify.com/api/v1/sites/{$this->siteId}/ssl";

        try {
            $response = Http::withToken($this->token)
                ->timeout(60)
                ->post($url, [
                    'certificate' => $cert,
                    'key' => $key,
                    'ca_certificates' => $chain,
                ]);

            if ($response->successful()) {
                Log::info('NetlifyCertService: Certificate uploaded successfully', [
                    'site_id' => $this->siteId,
                    'status' => $response->status(),
                ]);

                return [
                    'success' => true,
                    'message' => 'Certificate uploaded to Netlify successfully',
                    'response' => $response->json(),
                ];
            }

            // Handle error response
            $body = $response->json();
            $error = $body['message'] ?? $body['error'] ?? $response->body();

            Log::error('NetlifyCertService: Failed to upload certificate', [
                'site_id' => $this->siteId,
                'status' => $response->status(),
                'error' => $error,
            ]);

            return [
                'success' => false,
                'message' => "Netlify API error (HTTP {$response->status()}): {$error}",
            ];

        } catch (\Exception $e) {
            Log::error('NetlifyCertService: Exception during certificate upload', [
                'site_id' => $this->siteId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => "Exception: {$e->getMessage()}",
            ];
        }
    }

    /**
     * Send email notification about the certificate upload result.
     */
    public function sendNotification(bool $success, string $message, ?array $verification = null): void
    {
        $subject = $success
            ? 'Netlify SSL Certificate Renewed Successfully'
            : 'Netlify SSL Certificate Renewal FAILED';

        $body = $this->buildNotificationBody($success, $message, $verification);

        try {
            Mail::raw($body, function ($mail) use ($subject) {
                $mail->to($this->geekAlertsEmail)
                    ->from(config('mail.from.address', 'noreply@ilovefreegle.org'), config('mail.from.name', 'Freegle'))
                    ->subject($subject);
            });

            Log::info('NetlifyCertService: Notification email sent', [
                'to' => $this->geekAlertsEmail,
                'success' => $success,
            ]);

        } catch (\Exception $e) {
            Log::error('NetlifyCertService: Failed to send notification email', [
                'to' => $this->geekAlertsEmail,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Verify the deployed certificate by checking the live site.
     *
     * @param string $hostname Hostname to check (default: www.ilovefreegle.org)
     * @return array{success: bool, issuer: ?string, subject: ?string, notBefore: ?string, notAfter: ?string, error: ?string}
     */
    public function verifyCertificate(string $hostname = 'www.ilovefreegle.org'): array
    {
        $command = sprintf(
            'echo | openssl s_client -servername %s -connect %s:443 2>/dev/null | openssl x509 -noout -dates -issuer -subject 2>/dev/null',
            escapeshellarg($hostname),
            escapeshellarg($hostname)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode !== 0 || empty($output)) {
            return [
                'success' => false,
                'issuer' => null,
                'subject' => null,
                'notBefore' => null,
                'notAfter' => null,
                'error' => 'Failed to retrieve certificate from ' . $hostname,
            ];
        }

        $result = [
            'success' => true,
            'issuer' => null,
            'subject' => null,
            'notBefore' => null,
            'notAfter' => null,
            'error' => null,
        ];

        foreach ($output as $line) {
            if (str_starts_with($line, 'notBefore=')) {
                $result['notBefore'] = substr($line, 10);
            } elseif (str_starts_with($line, 'notAfter=')) {
                $result['notAfter'] = substr($line, 9);
            } elseif (str_starts_with($line, 'issuer=')) {
                $result['issuer'] = substr($line, 7);
            } elseif (str_starts_with($line, 'subject=')) {
                $result['subject'] = substr($line, 8);
            }
        }

        return $result;
    }

    /**
     * Build the notification email body.
     */
    public function buildNotificationBody(bool $success, string $message, ?array $verification = null): string
    {
        $timestamp = now()->toDateTimeString();

        if ($success) {
            $verificationInfo = '';
            if ($verification && $verification['success']) {
                $verificationInfo = <<<VERIFY

CERTIFICATE VERIFICATION (live check):
  Subject: {$verification['subject']}
  Issuer: {$verification['issuer']}
  Valid from: {$verification['notBefore']}
  Expires: {$verification['notAfter']}
VERIFY;
            } elseif ($verification) {
                $verificationInfo = "\n\nWARNING: Could not verify live certificate: {$verification['error']}";
            }

            return <<<TEXT
The SSL certificate for ilovefreegle.org has been successfully renewed and uploaded to Netlify.

Site ID: {$this->siteId}
Certificate path: {$this->certPath}
Time: {$timestamp}
{$verificationInfo}

You can verify the certificate at:
https://www.sslshopper.com/ssl-checker.html#hostname=https://www.ilovefreegle.org/
TEXT;
        }

        return <<<TEXT
FAILED to upload the SSL certificate for ilovefreegle.org to Netlify.

Error: {$message}

Site ID: {$this->siteId}
Certificate path: {$this->certPath}
Time: {$timestamp}

Please investigate and manually upload the certificate if necessary.

Netlify SSL settings: https://app.netlify.com/sites/{$this->siteId}/settings/ssl
TEXT;
    }
}
