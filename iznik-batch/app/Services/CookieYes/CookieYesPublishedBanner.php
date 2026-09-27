<?php

namespace App\Services\CookieYes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * The cookie categories the banner actually publishes to visitors.
 *
 * They are carried in the banner script CookieYes serves from its CDN (the
 * `src` of the embed code), as a JavaScript literal:
 *
 *   _ckyStore={_categories:[{slug:"necessary",...,cookies:[{cookieID:"euconsent",domain:".ilovefreegle.org"},...]},...,{slug:"other",...,cookies:[]}]
 *
 * This is the state after the AI Cookie Classifier has placed the cookies a
 * scan found and published the result. The MCP server's scan results are a
 * record of what the scanner found and how it categorised them at the time,
 * so they still list cookies as uncategorised long after the classifier has
 * dealt with them. Only the published script says what visitors see.
 */
class CookieYesPublishedBanner
{
    /**
     * @return array<string, list<string>> category slug => cookie ids, in the banner's order
     */
    public function categories(string $scriptUrl): array
    {
        try {
            $response = Http::timeout(30)->get($scriptUrl);
        } catch (ConnectionException $e) {
            throw new CookieYesException("Could not fetch the published banner script {$scriptUrl}: " . $e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new CookieYesException("The published banner script {$scriptUrl} returned HTTP {$response->status()}: " . Str::limit($response->body(), 120));
        }

        return $this->parse($response->body());
    }

    /**
     * @return array<string, list<string>>
     */
    public function parse(string $script): array
    {
        $marker = '_categories:[';
        $start = strpos($script, $marker);
        if ($start === false) {
            throw new CookieYesException('The published banner script has no _categories list');
        }

        $open = $start + strlen($marker) - 1;
        $close = $this->matchingBracket($script, $open);
        if ($close === null) {
            throw new CookieYesException('The published banner script\'s _categories list is not complete');
        }

        $categories = [];
        foreach ($this->topLevelObjects(substr($script, $open + 1, $close - $open - 1)) as $object) {
            if (! preg_match('/\bslug:"((?:[^"\\\\]|\\\\.)*)"/', $object, $slug)) {
                throw new CookieYesException('A published banner category has no slug: ' . Str::limit($object, 80));
            }

            $cookies = [];
            $list = strpos($object, 'cookies:[');
            if ($list !== false) {
                $listOpen = $list + strlen('cookies:[') - 1;
                $listClose = $this->matchingBracket($object, $listOpen);
                if ($listClose === null) {
                    throw new CookieYesException("The published banner category {$slug[1]} has an incomplete cookie list");
                }
                preg_match_all('/\bcookieID:"((?:[^"\\\\]|\\\\.)*)"/', substr($object, $listOpen, $listClose - $listOpen + 1), $ids);
                $cookies = array_map('stripslashes', $ids[1]);
            }

            $categories[stripslashes($slug[1])] = $cookies;
        }

        if ($categories === []) {
            throw new CookieYesException('The published banner script\'s _categories list is empty');
        }

        return $categories;
    }

    /**
     * Position of the bracket closing the one at $open, skipping over string
     * literals, or null if the text ends first.
     */
    private function matchingBracket(string $text, int $open): ?int
    {
        $pairs = ['[' => ']', '{' => '}'];
        $stack = [];
        $length = strlen($text);
        $inString = false;

        for ($i = $open; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === '"') {
                    $inString = false;
                }
                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif (isset($pairs[$char])) {
                $stack[] = $pairs[$char];
            } elseif ($char === ']' || $char === '}') {
                if (array_pop($stack) !== $char) {
                    return null;
                }
                if ($stack === []) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * The `{...}` objects directly inside an array literal's contents.
     *
     * @return list<string>
     */
    private function topLevelObjects(string $contents): array
    {
        $objects = [];
        $offset = 0;

        while (($start = strpos($contents, '{', $offset)) !== false) {
            $end = $this->matchingBracket($contents, $start);
            if ($end === null) {
                throw new CookieYesException('The published banner script\'s _categories list has an incomplete category');
            }
            $objects[] = substr($contents, $start, $end - $start + 1);
            $offset = $end + 1;
        }

        return $objects;
    }
}
