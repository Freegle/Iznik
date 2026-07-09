<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads the live apiv2 OpenAPI spec (the single source of truth for what is
 * deprecated and when it sunsets) and returns the operations whose sunset date
 * has passed — the set monitor:deprecated-endpoints must report on.
 */
class DeprecatedEndpointService
{
    /**
     * @return array<int, array{method:string, path:string, sunset:string, logged_endpoint:string}>
     */
    public function pastSunset(Carbon $now): array
    {
        $spec = $this->fetchSpec();
        if ($spec === null) {
            return [];
        }

        $out = [];
        foreach (($spec['paths'] ?? []) as $path => $operations) {
            foreach ($operations as $method => $op) {
                if (! is_array($op) || empty($op['deprecated'])) {
                    continue;
                }
                $sunset = $op['x-sunset'] ?? null;
                if (! $sunset) {
                    // Deprecated but no sunset date yet: not armed, skip.
                    continue;
                }
                if (Carbon::parse($sunset)->startOfDay()->gt($now->copy()->startOfDay())) {
                    continue; // still inside the grace window
                }

                $upperMethod = strtoupper($method);
                $out[] = [
                    'method' => $upperMethod,
                    'path' => $path,
                    'sunset' => $sunset,
                    'logged_endpoint' => $this->loggedEndpoint($upperMethod, $path),
                ];
            }
        }

        return $out;
    }

    /**
     * Convert an OpenAPI path ("/message/{id}") to the Fiber route-pattern form
     * the Go middleware logs ("GET /message/:id"), so the LogQL filter matches.
     */
    private function loggedEndpoint(string $method, string $path): string
    {
        $fiberPath = preg_replace('/\{([^}]+)\}/', ':$1', $path);

        return $method.' '.$fiberPath;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchSpec(): ?array
    {
        $url = config('freegle.apiv2_swagger_url');
        try {
            $resp = Http::timeout(30)->get($url);
        } catch (\Throwable $e) {
            Log::warning('DeprecatedEndpointService: spec fetch failed: '.$e->getMessage());

            return null;
        }
        if (! $resp->ok()) {
            Log::warning('DeprecatedEndpointService: spec fetch non-200: '.$resp->status());

            return null;
        }

        return $resp->json();
    }
}
