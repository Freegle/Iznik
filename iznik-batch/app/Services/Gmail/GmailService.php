<?php

namespace App\Services\Gmail;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Minimal Gmail API client used by the community-outreach sender and by the
 * mailbox transport of the concierge FSM. It sends and reads as a real Google
 * Workspace mailbox (natalie-wagg@ilovefreegle.org) via a service account with
 * domain-wide delegation (DWD), scopes gmail.send + gmail.modify.
 *
 * Deliberately NOT routed through Laravel Mail / the Freegle MTA: the outreach
 * recipients are not Freegle users, so this is ordinary email from a Workspace
 * mailbox (which gives inherent SPF/DKIM/DMARC alignment for ilovefreegle.org).
 *
 * DRY_RUN (default until the SA key is wired) writes the fully-rendered .eml to
 * storage/app/outreach/dryrun/ and never contacts Google, so the whole sender
 * can be exercised and eyeballed before any Workspace admin step or image rebuild.
 *
 * Live auth needs google/auth (composer require google/auth); dry-run does not.
 */
class GmailService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API_BASE = 'https://gmail.googleapis.com/gmail/v1/users/me';

    private const SCOPES = [
        'https://www.googleapis.com/auth/gmail.send',
        'https://www.googleapis.com/auth/gmail.modify',
    ];

    private bool $dryRun;

    private string $mailbox;

    private ?string $credentialsPath;

    private ?string $accessToken = null;

    private float $accessTokenExpiresAt = 0.0;

    public function __construct()
    {
        $this->dryRun = (bool) config('services.gmail_outreach.dry_run', true);
        $this->mailbox = (string) config('services.gmail_outreach.mailbox', 'natalie-wagg@ilovefreegle.org');
        $this->credentialsPath = config('services.gmail_outreach.credentials_path');
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function mailbox(): string
    {
        return $this->mailbox;
    }

    /**
     * Send an email as the mailbox.
     *
     * @param  array<string,string>  $headers  Extra headers (e.g. List-Unsubscribe).
     * @return array{id:string,thread_id:?string,dry_run:bool} Gmail message id (or a dryrun marker).
     */
    public function send(
        Address $to,
        string $subject,
        string $html,
        string $text,
        ?Address $replyTo = null,
        array $headers = [],
        ?string $fromName = null,
    ): array {
        $from = new Address($this->mailbox, $fromName ?? (string) config('services.gmail_outreach.from_name', 'Natalie @ Freegle'));

        $email = (new Email())
            ->from($from)
            ->to($to)
            ->subject($subject)
            ->text($text)
            ->html($html);

        if ($replyTo) {
            $email->replyTo($replyTo);
        }

        $h = $email->getHeaders();
        foreach ($headers as $name => $value) {
            $h->addTextHeader($name, $value);
        }

        $raw = $this->base64url($email->toString());

        if ($this->dryRun) {
            return $this->writeDryRun($to->getAddress(), $email->toString());
        }

        $resp = Http::withToken($this->accessToken())
            ->post(self::API_BASE.'/messages/send', ['raw' => $raw]);

        if (! $resp->successful()) {
            Log::error('Gmail send failed', ['status' => $resp->status(), 'body' => $resp->body()]);
            throw new \RuntimeException('Gmail send failed: '.$resp->body());
        }

        return [
            'id' => (string) $resp->json('id'),
            'thread_id' => $resp->json('threadId'),
            'dry_run' => false,
        ];
    }

    /**
     * List thread stubs matching a Gmail search query (e.g. "in:inbox is:unread").
     *
     * @return array<int,array{id:string,threadId:string,snippet?:string}>
     */
    public function listThreads(string $query, int $maxResults = 50): array
    {
        $resp = Http::withToken($this->accessToken())
            ->get(self::API_BASE.'/threads', ['q' => $query, 'maxResults' => $maxResults]);

        if (! $resp->successful()) {
            throw new \RuntimeException('Gmail list threads failed: '.$resp->body());
        }

        return $resp->json('threads', []);
    }

    /** Fetch a full thread (all messages, full format). */
    public function getThread(string $threadId): array
    {
        $resp = Http::withToken($this->accessToken())
            ->get(self::API_BASE.'/threads/'.$threadId, ['format' => 'full']);

        if (! $resp->successful()) {
            throw new \RuntimeException('Gmail get thread failed: '.$resp->body());
        }

        return $resp->json();
    }

    /** Add/remove labels on a message (e.g. mark read by removing UNREAD). */
    public function modifyMessageLabels(string $messageId, array $add = [], array $remove = []): void
    {
        $resp = Http::withToken($this->accessToken())
            ->post(self::API_BASE.'/messages/'.$messageId.'/modify', [
                'addLabelIds' => array_values($add),
                'removeLabelIds' => array_values($remove),
            ]);

        if (! $resp->successful()) {
            throw new \RuntimeException('Gmail modify labels failed: '.$resp->body());
        }
    }

    /**
     * Mint (and cache) an access token via the service account with DWD
     * impersonation of the mailbox. Requires google/auth.
     */
    private function accessToken(): string
    {
        if ($this->accessToken !== null && microtime(true) < $this->accessTokenExpiresAt - 60) {
            return $this->accessToken;
        }

        if (! $this->credentialsPath || ! is_file($this->credentialsPath)) {
            throw new \RuntimeException(
                'Gmail outreach credentials not found. Set GMAIL_OUTREACH_CREDENTIALS_PATH to the '
                .'service-account JSON key, or leave GMAIL_OUTREACH_DRY_RUN=true.'
            );
        }

        if (! class_exists(\Google\Auth\Credentials\ServiceAccountCredentials::class)) {
            throw new \RuntimeException(
                'google/auth is not installed. Run "composer require google/auth" in iznik-batch '
                .'and rebuild the batch image, or stay in dry-run mode.'
            );
        }

        $creds = new \Google\Auth\Credentials\ServiceAccountCredentials(
            self::SCOPES,
            $this->credentialsPath,
            $this->mailbox, // sub: impersonate the mailbox (domain-wide delegation)
        );

        $token = $creds->fetchAuthToken();

        if (empty($token['access_token'])) {
            throw new \RuntimeException('Gmail DWD token exchange returned no access_token.');
        }

        $this->accessToken = $token['access_token'];
        $this->accessTokenExpiresAt = microtime(true) + (int) ($token['expires_in'] ?? 3600);

        return $this->accessToken;
    }

    private function writeDryRun(string $to, string $rawMime): array
    {
        $dir = storage_path('app/outreach/dryrun');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $safe = preg_replace('/[^a-z0-9._@-]/i', '_', $to);
        $id = 'dryrun-'.date('Ymd-His').'-'.substr(md5($rawMime), 0, 8);
        $path = $dir.'/'.$id.'-'.$safe.'.eml';
        file_put_contents($path, $rawMime);

        Log::info('Gmail DRY_RUN: wrote .eml instead of sending', ['to' => $to, 'path' => $path]);

        return ['id' => $id, 'thread_id' => null, 'dry_run' => true, 'path' => $path];
    }

    private function base64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
