<?php

namespace App\Mail;

use App\Services\MjmlCompilerService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

abstract class MjmlMailable extends Mailable
{
    use Queueable, SerializesModels;

    protected string $mjmlTemplate;
    protected array $mjmlData = [];

    /**
     * Trace ID for log correlation. Initialized early with default value.
     */
    protected string $traceId = '';

    /**
     * Timestamp when email was created.
     */
    protected string $timestamp = '';

    public function __construct()
    {
        // Initialize tracking fields immediately to avoid "accessed before initialization" errors.
        $this->traceId = 'freegle-' . time() . '_' . Str::random(8);
        $this->timestamp = now()->toIso8601String();
    }

    /**
     * Build the message.
     *
     * This method is called when the email is sent. It renders the MJML
     * template, compiles it to HTML, and sets it as the email content.
     */
    public function build(): static
    {
        // Render the Blade template to get MJML content.
        $mjmlContent = $this->renderMjmlTemplate();

        // Compile MJML to HTML.
        $html = $this->compileMjml($mjmlContent);

        // Set the HTML content.
        return $this->html($html);
    }

    /**
     * Render the MJML template.
     *
     * Views should be pre-compiled before tests run via `php artisan view:cache`.
     * If a view renders empty, that's a real problem that needs investigation -
     * not something to retry or patch over by deleting compiled views.
     */
    protected function renderMjmlTemplate(): string
    {
        $viewInstance = view($this->mjmlTemplate, $this->mjmlData);
        $result = $viewInstance->render();

        // If render returns empty, log diagnostics to help debug.
        if (empty(trim($result))) {
            $bladeCompiler = app('blade.compiler');
            $viewPath = $viewInstance->getPath();
            $compiledPath = $bladeCompiler->getCompiledPath($viewPath);

            \Log::error('View render returned empty', [
                'template' => $this->mjmlTemplate,
                'view_path' => $viewPath,
                'view_exists' => file_exists($viewPath),
                'view_size' => file_exists($viewPath) ? filesize($viewPath) : 'N/A',
                'compiled_path' => $compiledPath,
                'compiled_exists' => file_exists($compiledPath),
                'compiled_size' => file_exists($compiledPath) ? filesize($compiledPath) : 'N/A',
                'compiled_preview' => file_exists($compiledPath) ? substr(file_get_contents($compiledPath), 0, 500) : 'N/A',
                'data_keys' => array_keys($this->mjmlData),
                'check_timestamps' => config('view.check_cache_timestamps'),
            ]);
        }

        return $result;
    }

    /**
     * Compile MJML to HTML via the MJML server.
     *
     * @throws \RuntimeException If MJML compilation fails or produces empty output
     */
    protected function compileMjml(string $mjml): string
    {
        // Check for empty input - this indicates a template rendering failure.
        if (empty(trim($mjml))) {
            // Get the actual file path for additional diagnostics.
            $templatePath = $this->mjmlTemplate ?? 'unknown';
            $filePath = null;
            $fileSize = null;

            if ($templatePath !== 'unknown') {
                // Convert dot notation to file path.
                $relativePath = str_replace('.', '/', $templatePath) . '.blade.php';
                $filePath = resource_path('views/' . $relativePath);
                $fileSize = file_exists($filePath) ? filesize($filePath) : 'file not found';
            }

            $error = 'MJML template rendered to empty string - template may be missing or have syntax errors';
            \Log::error($error, [
                'mailable' => static::class,
                'template' => $templatePath,
                'file_path' => $filePath,
                'file_size' => $fileSize,
                'view_exists' => view()->exists($templatePath),
            ]);
            throw new \RuntimeException($error . " (template: {$templatePath}, file_size: {$fileSize})");
        }

        try {
            $compiler = app(MjmlCompilerService::class);
            return $compiler->compile($mjml);
        } catch (\Exception $e) {
            // Distinguish HTTP-call failures (genuine MJML compile failure) from
            // anything else (Redis pool init, DI, view render). Mislabelling
            // network/infra failures as "MJML compilation failed" sent us down
            // a wrong path during the 2026-04-27 Docker network incident.
            $msg = $e->getMessage();
            $isMjmlCompilation = str_contains($msg, 'MJML compilation')
                || str_contains($msg, 'mjml')
                || str_contains(get_class($e), 'Http');

            $label = $isMjmlCompilation
                ? 'MJML compilation failed'
                : 'Email build failed before MJML compile';
            $error = $label.': '.$msg;
            \Log::error($error, [
                'mailable' => static::class,
                'template' => $this->mjmlTemplate ?? 'unknown',
                'exception_class' => get_class($e),
                'exception' => $msg,
            ]);
            throw new \RuntimeException($error, 0, $e);
        }
    }

    /**
     * Set the MJML template and render it.
     *
     * @param string $template MJML template path (e.g., 'emails.mjml.welcome.welcome')
     * @param array $data Data to pass to templates
     * @param string|null $textTemplate Optional plain text template path
     */
    protected function mjmlView(string $template, array $data = [], ?string $textTemplate = NULL): static
    {
        $this->mjmlTemplate = $template;
        $this->mjmlData = array_merge($this->getDefaultData(), $data);

        // Check that the view exists before rendering.
        if (!view()->exists($this->mjmlTemplate)) {
            $error = "MJML template not found: {$this->mjmlTemplate}";
            \Log::error($error, [
                'mailable' => static::class,
                'template' => $this->mjmlTemplate,
                'view_paths' => config('view.paths'),
            ]);
            throw new \RuntimeException($error);
        }

        // Render the Blade template to get MJML content with retry on empty.
        try {
            $mjmlContent = $this->renderMjmlTemplate();
        } catch (\Throwable $e) {
            $error = "MJML template rendering failed: {$e->getMessage()}";
            \Log::error($error, [
                'mailable' => static::class,
                'template' => $this->mjmlTemplate,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \RuntimeException($error, 0, $e);
        }

        // Compile MJML to HTML.
        $html = $this->compileMjml($mjmlContent);

        // Set the HTML content.
        $this->html($html);

        // Set plain text version if template provided.
        if ($textTemplate && view()->exists($textTemplate)) {
            $this->text($textTemplate, $this->mjmlData);
        }

        return $this;
    }

    /**
     * Get default data for all MJML templates.
     */
    protected function getDefaultData(): array
    {
        return [
            'siteName' => config('freegle.branding.name', 'Freegle'),
            'logoUrl' => config('freegle.branding.logo_url'),
            'userSite' => config('freegle.sites.user'),
            'modSite' => config('freegle.sites.mod'),
            'supportEmail' => config('freegle.mail.support_addr'),
            'currentYear' => date('Y'),
        ];
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        throw new \LogicException(
            static::class . ' must override envelope() and set an explicit from address. '
            . 'Do not rely on a default - different emails use different sender addresses.'
        );
    }

    /**
     * Get the subject line.
     */
    abstract protected function getSubject(): string;

    /**
     * Get the attachments for the message.
     */
    public function attachments(): array
    {
        return [];
    }

    /**
     * Generate responsive image data for email templates.
     *
     * Uses the image delivery service (weserv/images) to create
     * multiple sizes for responsive srcset.
     *
     * @param string $sourceUrl The source image URL
     * @param array $widths Array of widths to generate (default: [80, 160, 240])
     * @param int $defaultWidth The default width for src attribute
     * @return array{src: string, srcset: string}
     */
    protected function responsiveImage(string $sourceUrl, array $widths = [80, 160, 240], int $defaultWidth = 80): array
    {
        $deliveryBase = config('freegle.delivery.base_url', 'https://delivery.ilovefreegle.org');

        // Generate srcset entries.
        // Use &amp; for MJML/XML compatibility - will be decoded to & in final HTML.
        $srcsetParts = [];
        foreach ($widths as $width) {
            $resizedUrl = "{$deliveryBase}/?url=" . urlencode($sourceUrl) . "&amp;w={$width}";
            $srcsetParts[] = "{$resizedUrl} {$width}w";
        }

        // Generate default src.
        $src = "{$deliveryBase}/?url=" . urlencode($sourceUrl) . "&amp;w={$defaultWidth}";

        return [
            'src' => $src,
            'srcset' => implode(', ', $srcsetParts),
        ];
    }

    /**
     * Add common tracking headers to the email.
     *
     * Called via withSymfonyMessage() callback when the email is built.
     * traceId and timestamp are initialized in the constructor to ensure
     * they're available when this callback runs.
     */
    protected function addCommonHeaders(Email $message): void
    {
        $headers = $message->getHeaders();

        // Trace ID for correlating email with Loki logs and bounce tracking.
        // If this mailable uses TrackableEmail trait, use the email_tracking.tracking_id
        // so bounces can be linked back to the specific email sent.
        $traceId = $this->getTraceIdForHeader();
        $headers->addTextHeader('X-Freegle-Trace-Id', $traceId);

        // Email type for filtering/categorization.
        $headers->addTextHeader('X-Freegle-Email-Type', class_basename($this));

        // Timestamp when email was created.
        $headers->addTextHeader('X-Freegle-Timestamp', $this->timestamp);

        // User ID if available (subclasses can override getRecipientUserId).
        $userId = $this->getRecipientUserId();
        if ($userId !== null) {
            $headers->addTextHeader('X-Freegle-User-Id', (string) $userId);
        }

        // RFC 8058 List-Unsubscribe headers for Gmail/Yahoo deliverability.
        // These headers enable one-click unsubscribe in email clients and are
        // required by Gmail and Yahoo for bulk senders.
        $this->addListUnsubscribeHeaders($message);
    }

    /**
     * Add RFC 8058 List-Unsubscribe and List-Unsubscribe-Post headers.
     *
     * Only added when we know the recipient user ID, so the unsubscribe URL
     * can target the correct account. Uses both a mailto: address and an HTTPS
     * URL for maximum compatibility. List-Unsubscribe-Post enables one-click
     * unsubscribe without opening a browser.
     *
     * These headers are required by Gmail and Yahoo for bulk senders.
     */
    protected function addListUnsubscribeHeaders(Email $message): void
    {
        $userId = $this->getRecipientUserId();

        if ($userId === null) {
            return;
        }

        $headers = $message->getHeaders();

        $unsubscribeEmail = config('freegle.mail.noreply_addr', 'noreply@ilovefreegle.org');
        $userSite = config('freegle.sites.user', 'https://www.ilovefreegle.org');
        $unsubscribeUrl = "{$userSite}/unsubscribe/{$userId}";

        // List-Unsubscribe: RFC 2369 — mailto: and https: URLs.
        $headers->addTextHeader(
            'List-Unsubscribe',
            "<mailto:{$unsubscribeEmail}?subject=unsubscribe>, <{$unsubscribeUrl}>"
        );

        // List-Unsubscribe-Post: RFC 8058 — enables one-click unsubscribe.
        $headers->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
    }

    /**
     * Get the recipient's user ID for tracking.
     * Override in subclasses to provide user-specific tracking.
     */
    protected function getRecipientUserId(): ?int
    {
        return null;
    }

    /**
     * Get the trace ID to use in the X-Freegle-Trace-Id header.
     *
     * If this mailable uses the TrackableEmail trait and has an EmailTracking record,
     * use the tracking_id from that record. This allows bounce messages to be linked
     * back to the specific email that was sent.
     *
     * Otherwise, use the default traceId generated in the constructor.
     */
    protected function getTraceIdForHeader(): string
    {
        // Check if this class uses TrackableEmail trait and has tracking initialized
        if (method_exists($this, 'getTracking')) {
            $tracking = $this->getTracking();
            if ($tracking && $tracking->tracking_id) {
                return $tracking->tracking_id;
            }
        }

        return $this->traceId;
    }

    /**
     * Configure the message with common headers.
     *
     * Subclasses should call this in their content() method via withSymfonyMessage().
     */
    protected function configureMessage(): static
    {
        return $this->withSymfonyMessage(function (Email $message) {
            $this->addCommonHeaders($message);
        });
    }
}
