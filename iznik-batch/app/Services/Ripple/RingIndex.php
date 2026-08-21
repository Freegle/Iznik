<?php

namespace App\Services\Ripple;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Whether an overflow ring admits a member — asked of the spatial server, which
 * is the ONE place that answers it.
 *
 * The mail used to answer it for itself, out of `rippling_reach.overflow_bounds`,
 * while browse, search, the badge, the message page and the reply gate asked the
 * spatial index. Two derivations of one question, and on 2026-08-21 they came
 * apart in the worst direction: mail invited members the site would not show the
 * post to and whose replies the gate then held until the item was gone. Nobody
 * is inviting anybody on an answer only they can see.
 *
 * The ring geometry is not consulted here at all, deliberately. It is 37,000
 * vertices on average; parsing it is what made this question cost seconds on the
 * read surfaces, and a copy of that test living here is what let the two answers
 * drift. This asks, and applies what it is told.
 */
class RingIndex
{
    /**
     * Which of these candidate members does this post's ring admit?
     *
     * $points is a list of ['lat' => .., 'lng' => .., 'lanes' => ['$.rural.sparse', ...]],
     * keyed however the caller likes; the returned array holds the keys of the
     * admitted ones, so callers keep whatever they had attached.
     *
     * Fails CLOSED: on any error nobody is admitted, matching what apiv2 does
     * when the index cannot answer. That costs ring members their invitation for
     * as long as the outage lasts, which is the safe direction — the unsafe one
     * is mailing somebody the site will turn away.
     */
    public static function admits(int $msgid, array $points): array
    {
        if ($msgid <= 0 || $points === []) {
            return [];
        }

        $keys = array_keys($points);
        $body = [];
        foreach ($points as $p) {
            $body[] = [
                'lng' => (float) ($p['lng'] ?? 0),
                'lat' => (float) ($p['lat'] ?? 0),
                'lanes' => array_values(array_filter((array) ($p['lanes'] ?? []), 'is_string')),
            ];
        }

        $url = rtrim((string) config('freegle.spatial_server_url', 'http://localhost:8194'), '/');

        try {
            $response = Http::timeout((int) config('freegle.ripple.ring_index_timeout', 10))
                ->post("$url/v1/reachoverflow/admits", ['msgid' => $msgid, 'points' => $body]);

            if (! $response->successful()) {
                self::note($msgid, 'HTTP ' . $response->status());

                return [];
            }

            $admitted = $response->json('admitted');
            if (! is_array($admitted)) {
                self::note($msgid, 'no admitted list in response');

                return [];
            }

            $out = [];
            foreach ($admitted as $i) {
                if (is_int($i) && isset($keys[$i])) {
                    $out[] = $keys[$i];
                }
            }

            return $out;
        } catch (\Throwable $e) {
            self::note($msgid, $e->getMessage());

            return [];
        }
    }

    /**
     * Which posts do this member's rings admit them to?
     *
     * The browse direction of the same question - one member, many posts - and
     * the SAME call the website's feed, badge and search make. The digest asks it
     * because a post a ring admits is one this member can see and reply to, so
     * telling them about it is honest; and because if it asked differently it
     * would eventually answer differently.
     *
     * Fails CLOSED: no rings on any error.
     *
     * @return array<int, int> msgids
     */
    public static function admittedFor(float $lat, float $lng, array $lanes): array
    {
        if ($lanes === []) {
            return [];
        }

        $url = rtrim((string) config('freegle.spatial_server_url', 'http://localhost:8194'), '/');

        try {
            $response = Http::timeout((int) config('freegle.ripple.ring_index_timeout', 10))
                ->get("$url/v1/reachoverflow/containing", [
                    'lng' => $lng,
                    'lat' => $lat,
                    'lanes' => implode(',', $lanes),
                ]);

            if (! $response->successful()) {
                self::note(0, 'HTTP ' . $response->status());

                return [];
            }

            $in = $response->json('in');

            return is_array($in) ? array_values(array_map('intval', $in)) : [];
        } catch (\Throwable $e) {
            self::note(0, $e->getMessage());

            return [];
        }
    }

    /**
     * The lanes a member is in, in the spatial server's own names.
     *
     * The rural lane is gated on the member's OWN density band — a post carries a
     * ring per band and only their band's ring may admit them. The cluster wedges
     * are not band-gated: they sit beyond every band's ceiling, which is why they
     * exist, so gating them on a band would switch the lane off for precisely the
     * members it was drawn for.
     *
     * Fairness is absent because it needs the member's deprivation fifth, which
     * only the spatial server knows and which nothing records against anybody;
     * the daily digest has never applied that lane, and inventing it here would
     * be a new surface split rather than a fix for one.
     */
    public static function lanesFor(?string $densityBand): array
    {
        $lanes = [];

        if ((bool) config('freegle.ripple.rural_access.enabled', false)) {
            $bands = [
                'dense' => '$.rural.dense',
                'medium' => '$.rural.medium',
                'sparse' => '$.rural.sparse',
            ];
            if (is_string($densityBand) && isset($bands[$densityBand])) {
                $lanes[] = $bands[$densityBand];
            }
        }

        if ((bool) config('freegle.ripple.cluster.enabled', false)) {
            $lanes[] = '$.cluster.w1';
            $lanes[] = '$.cluster.w2';
            $lanes[] = '$.cluster.w3';
        }

        return $lanes;
    }

    private static function note(int $msgid, string $why): void
    {
        Log::warning('Ring index could not answer; nobody admitted by ring', [
            'msgid' => $msgid,
            'error' => $why,
        ]);
    }
}
