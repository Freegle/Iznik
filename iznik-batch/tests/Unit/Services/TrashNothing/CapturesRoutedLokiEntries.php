<?php

namespace Tests\Unit\Services\TrashNothing;

use App\Services\LokiService;

/**
 * Captures the `type=routed` Loki entries a test produces, by pointing
 * LokiService at a temporary log directory and reading back what it wrote.
 *
 * Deliberately exercises the real LokiService rather than a mock: the whole
 * point of this stream is that its on-disk shape matches the email path's
 * byte for byte, so the assertions have to run against what actually lands in
 * the file.
 */
trait CapturesRoutedLokiEntries
{
    private string $lokiLogPath;

    protected function enableLokiCapture(): LokiService
    {
        $this->lokiLogPath = sys_get_temp_dir().'/loki-parity-'.uniqid();
        mkdir($this->lokiLogPath, 0777, true);

        // LokiService reads config in its constructor, so configure first.
        config(['freegle.loki.enabled' => true, 'freegle.loki.log_path' => $this->lokiLogPath]);

        $loki = new LokiService;
        $this->app->instance(LokiService::class, $loki);

        return $loki;
    }

    protected function tearDownLokiCapture(): void
    {
        if (! isset($this->lokiLogPath)) {
            return;
        }

        foreach (glob($this->lokiLogPath.'/*.log') as $file) {
            unlink($file);
        }
        @rmdir($this->lokiLogPath);
    }

    /**
     * Every routed entry written so far, decoded.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function routedEntries(): array
    {
        $file = $this->lokiLogPath.'/incoming_mail.log';
        if (! file_exists($file)) {
            return [];
        }

        return array_map(
            fn ($line) => json_decode($line, true),
            array_values(array_filter(explode("\n", file_get_contents($file)), fn ($l) => $l !== ''))
        );
    }

    /**
     * The single routed entry for a post — enforcing, not assuming, that there
     * was exactly one.
     *
     * The email path emits exactly one type=routed entry per item it handles,
     * with no exceptions, and being 1:1 with it is the point of this stream: a
     * post emitting none silently vanishes from the comparison, and one
     * emitting two inflates it.
     *
     * @return array<string, mixed>
     */
    protected function onlyRoutedEntry(): array
    {
        $entries = $this->routedEntries();

        $this->assertCount(
            1,
            $entries,
            'Expected exactly one routed Loki entry for this post. The email path emits exactly '
            .'one per item it handles, and this stream has to match it 1:1.'
        );

        return $entries[0];
    }
}
