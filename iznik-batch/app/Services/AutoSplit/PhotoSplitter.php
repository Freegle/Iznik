<?php

namespace App\Services\AutoSplit;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Gives each photo of a split post to the item it shows. Each photo goes to the vision model in a
 * request of its own, with the item list, and it draws a box for every listed item it can see;
 * PhotoDecision then turns the boxes into a whole-photo assignment, crops or a shared photo.
 * One photo a request, because with several photos in one request the model often listed an item
 * in the photo that showed it best and left it out of a group photo that also showed it, which
 * gave the whole group photo to one item (a lamp missed in 3 of 8 tries; 0 of 8 alone, 2026-11). The photos are fetched through the delivery service with the poster's
 * rotation, because that is the frame the poster sees and the frame the crop is applied in.
 * docs/developers/reference/auto-split.md.
 */
class PhotoSplitter
{
    private const FETCH_SIZE = 1024;

    public const SCHEMA = [
        'type' => 'OBJECT',
        'properties' => [
            'shows' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'item' => ['type' => 'INTEGER'],
                        'box_2d' => ['type' => 'ARRAY', 'items' => ['type' => 'INTEGER']],
                    ],
                    'required' => ['item', 'box_2d'],
                ],
            ],
        ],
        'required' => ['shows'],
    ];

    /** Tokens the last split used, over all its requests: [in, out]. */
    private array $usage = [0, 0];

    public function __construct(private GeminiJson $gemini) {}

    /** Tokens the last split used, over all its requests: [in, out]. */
    public function usage(): array
    {
        return $this->usage;
    }

    /** Only photos the member uploaded: not an AI drawing, and stored where delivery can fetch it. */
    public static function eligible(object $attachment): bool
    {
        $mods = json_decode((string) ($attachment->externalmods ?? ''), true);

        return str_starts_with((string) ($attachment->externaluid ?? ''), 'freegletusd-')
            && ! (is_array($mods) && ! empty($mods['ai']));
    }

    public static function prompt(array $items): string
    {
        $list = [];
        foreach (array_values($items) as $i => $item) {
            $list[] = ($i + 1).'. '.$item['name'].($item['quantity'] > 1 ? " ({$item['quantity']})" : '');
        }

        return "This photo comes from one post on Freegle, a UK site where people give things away free. The post offers these items:\n"
            .implode("\n", $list)."\n\n"
            .'List every one of those items that you can see in the photo, by its number, with box_2d [ymin, xmin, ymax, xmax] on a 0 to 1000 scale. '
            .'The box covers all of that item that is visible in the photo; if there are several of it (for example 4 chairs), one box covers all of them. '
            .'Leave out items you cannot see, and do not guess: if you cannot tell which item something is, leave it out. '
            .'If the photo shows none of the items, the list is empty.';
    }

    /** The delivery URL for an uploaded photo, turned as the poster turned it. */
    public static function deliveryUrl(string $externaluid, ?string $externalmods, string $extra = ''): string
    {
        $file = preg_replace('/^freegletusd-/', '', $externaluid);
        $url = preg_replace('/\?url=$/', '', (string) config('freegle.delivery.base_url')).'?url='
            .rtrim((string) config('freegle.tus_uploader'), '/').'/'.$file;
        $mods = json_decode((string) $externalmods, true);
        if (is_array($mods) && ! empty($mods['rotate'])) {
            $url .= '&ro='.(int) $mods['rotate'];
        }

        return $url.($extra !== '' ? '&'.$extra : '');
    }

    /**
     * @param  list<array{name:string,quantity:int}>  $items
     * @param  list<object>  $attachments  rows with id, externaluid, externalmods, in display order
     * @return array{photos: list<array<string,mixed>>}|null null when the model gave no answer, so the post waits for the next run
     */
    public function split(array $items, array $attachments): ?array
    {
        $this->usage = [0, 0];
        $out = [];
        foreach ($attachments as $a) {
            $fetched = $this->fetch($a);
            if ($fetched === null) {
                $out[] = ['attachmentid' => (int) $a->id, 'action' => 'shared', 'why' => 'unfetched', 'shows' => []];

                continue;
            }

            $answer = $this->gemini->ask([
                ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => base64_encode($fetched['jpeg'])]],
                ['text' => self::prompt($items)],
            ], self::SCHEMA, 60);
            if ($answer === null || ! is_array($answer['shows'] ?? null)) {
                // All of the post's photos or none: try the whole post again next run.
                return null;
            }
            $this->usage[0] += $this->gemini->lastUsage[0];
            $this->usage[1] += $this->gemini->lastUsage[1];

            $out[] = ['attachmentid' => (int) $a->id]
                + PhotoDecision::decide($answer['shows'], count($items), $fetched['width'], $fetched['height'])
                + ['width' => $fetched['width'], 'height' => $fetched['height'], 'shows' => $answer['shows']];
        }

        return ['photos' => $out];
    }

    /** @return array{jpeg:string,width:int,height:int}|null */
    private function fetch(object $a): ?array
    {
        try {
            $meta = Http::timeout(20)->get(self::deliveryUrl($a->externaluid, $a->externalmods, 'output=json'));
            $img = Http::timeout(30)->get(self::deliveryUrl($a->externaluid, $a->externalmods,
                'w='.self::FETCH_SIZE.'&h='.self::FETCH_SIZE.'&fit=inside&output=jpg'));
        } catch (\Throwable $e) {
            Log::info('AutoSplit: photo fetch failed', ['attachment' => $a->id, 'error' => $e->getMessage()]);

            return null;
        }

        $width = (int) $meta->json('width', 0);
        $height = (int) $meta->json('height', 0);
        if (! $meta->successful() || ! $img->successful() || $width <= 0 || $height <= 0 || $img->body() === '') {
            Log::info('AutoSplit: photo not fetched', ['attachment' => $a->id, 'meta' => $meta->status(), 'image' => $img->status()]);

            return null;
        }

        return ['jpeg' => $img->body(), 'width' => $width, 'height' => $height];
    }
}
