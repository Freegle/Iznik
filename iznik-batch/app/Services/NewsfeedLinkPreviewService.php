<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NewsfeedLinkPreviewService
{
    // Matches http(s):// URLs from message text
    const URL_PATTERN = '#https?://[^\s<>"\'`{|}\\\\\^\[\]]+#i';

    public function generatePreviews(): int
    {
        $recent = DB::select(
            "SELECT id, message FROM newsfeed WHERE DATEDIFF(NOW(), `timestamp`) < 2"
        );

        $urlsSeen = [];
        $count = 0;

        foreach ($recent as $row) {
            if (!$row->message) {
                continue;
            }

            if (preg_match_all(self::URL_PATTERN, $row->message, $matches)) {
                foreach ($matches[0] as $url) {
                    if (!$url || isset($urlsSeen[$url])) {
                        continue;
                    }
                    $urlsSeen[$url] = true;

                    $id = $this->getOrCreate($url);
                    Log::debug("Url $url has preview $id");

                    if ($id) {
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    protected function getOrCreate(string $url): ?int
    {
        $existing = DB::select(
            "SELECT id FROM link_previews WHERE url = ? AND DATEDIFF(NOW(), retrieved) < 7",
            [$url]
        );

        if ($existing) {
            return $existing[0]->id;
        }

        return $this->create($url);
    }

    protected function create(string $url): ?int
    {
        $fetchUrl = str_replace('http://', 'https://', $url);

        try {
            $response = Http::timeout(15)->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; Freegle/1.0)',
            ])->get($fetchUrl);

            if (!$response->successful()) {
                return $this->upsertInvalid($url);
            }

            $data = $this->parseHtml($response->body());

            DB::statement(
                "INSERT INTO link_previews(url, title, description, image) VALUES (?,?,?,?)
                 ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), title=?, description=?, image=?, retrieved=NOW()",
                [
                    $url,
                    $data['title'],
                    $data['description'],
                    $data['image'],
                    $data['title'],
                    $data['description'],
                    $data['image'],
                ]
            );

            return (int) DB::getPdo()->lastInsertId();
        } catch (\Exception $e) {
            return $this->upsertInvalid($url);
        }
    }

    protected function upsertInvalid(string $url): int
    {
        DB::statement(
            "INSERT INTO link_previews(url, invalid) VALUES (?,1)
             ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), retrieved=NOW()",
            [$url]
        );

        return (int) DB::getPdo()->lastInsertId();
    }

    protected function parseHtml(string $html): array
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML($html, LIBXML_NOERROR);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        $title = $this->getMeta($xpath, 'og:title', 'property')
            ?? $this->getMeta($xpath, 'twitter:title', 'name')
            ?? ($xpath->query('//title')->item(0)?->textContent ?? null);

        $description = $this->getMeta($xpath, 'og:description', 'property')
            ?? $this->getMeta($xpath, 'description', 'name');

        $image = $this->getMeta($xpath, 'og:image', 'property')
            ?? $this->getMeta($xpath, 'twitter:image', 'name');

        return [
            'title'       => $title ? preg_replace('/[[:^print:]]/', '', trim($title)) : null,
            'description' => $description ? preg_replace('/[[:^print:]]/', '', trim($description)) : null,
            'image'       => $image ? trim($image) : null,
        ];
    }

    private function getMeta(DOMXPath $xpath, string $value, string $attr): ?string
    {
        $nodes = $xpath->query("//meta[@{$attr}='{$value}']/@content");

        if ($nodes && $nodes->length > 0) {
            return $nodes->item(0)->nodeValue;
        }

        return null;
    }
}
