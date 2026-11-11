<?php

namespace App\Services\AutoSplit;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Turns a new native OFFER that lists several separate things into a multi-item post: the
 * messages_bulk_items rows a clearance has, each photo linked to the item it shows, and a crop
 * per item for a photo of several. The post stays an ordinary Offer; its messages_autosplit row
 * ('Split') is what tells it apart from a clearance everywhere that treats clearances
 * differently (App\Support\ClearanceSql). docs/developers/reference/auto-split.md.
 *
 * Each post is decided once. A post with no answer from the model stays a candidate and is tried
 * again on the next run until it falls out of the window.
 */
class AutoSplitService
{
    public const MODE_OFF = 'off';

    public const MODE_SHADOW = 'shadow';

    public const MODE_ON = 'on';

    public const PER_RUN = 20;

    public function __construct(
        private TextSplitter $text,
        private PhotoSplitter $photos,
        private GeminiJson $gemini,
    ) {}

    public static function mode(): string
    {
        $mode = strtolower(trim((string) config('freegle.autosplit.mode', self::MODE_OFF)));

        return in_array($mode, [self::MODE_SHADOW, self::MODE_ON], true) ? $mode : self::MODE_OFF;
    }

    /** @return array<string,int> what happened this run, by outcome */
    public function process(int $limit = self::PER_RUN): array
    {
        $mode = self::mode();
        $tally = ['split' => 0, 'shadow' => 0, 'notsplit' => 0, 'retry' => 0];
        if ($mode === self::MODE_OFF || ! $this->gemini->isConfigured()) {
            return $tally;
        }

        foreach ($this->candidates($limit) as $msg) {
            $tally[$this->processOne($msg, $mode)]++;
        }

        return $tally;
    }

    /**
     * New native Freegle OFFERs the splitter has not decided on: no partner posts, no clearances,
     * nothing with an outcome, nothing withdrawn from every community. Oldest first, so nothing
     * ages out of the window while newer posts are served.
     *
     * @return list<object>
     */
    public function candidates(int $limit): array
    {
        $since = now()->subMinutes(max(1, (int) config('freegle.autosplit.window_minutes', 120)));

        return DB::table('messages as m')
            ->select('m.id', 'm.subject', 'm.textbody', 'm.availableinitially', 'm.availablenow')
            ->where('m.type', 'Offer')
            ->where('m.source', 'Platform')
            ->whereNull('m.tnpostid')
            ->whereNull('m.deleted')
            ->where('m.arrival', '>=', $since)
            ->whereNotNull('m.subject')
            ->whereNotExists(fn ($q) => $q->from('messages_autosplit')->whereColumn('messages_autosplit.msgid', 'm.id'))
            ->whereNotExists(fn ($q) => $q->from('messages_bulk_items')->whereColumn('messages_bulk_items.msgid', 'm.id'))
            ->whereNotExists(fn ($q) => $q->from('messages_outcomes')->whereColumn('messages_outcomes.msgid', 'm.id'))
            ->whereExists(fn ($q) => $q->from('messages_groups')
                ->whereColumn('messages_groups.msgid', 'm.id')
                ->where('messages_groups.deleted', 0)
                ->whereIn('messages_groups.collection', ['Pending', 'Approved']))
            ->orderBy('m.id')
            ->limit($limit)
            ->get()
            ->all();
    }

    /** @return string one of split, shadow, notsplit, retry */
    public function processOne(object $msg, string $mode): string
    {
        $tokens = [0, 0];
        $text = $this->text->split((string) $msg->subject, $msg->textbody);
        if ($text === null) {
            return 'retry';
        }
        $this->addUsage($tokens, $this->text->usage());

        if (! $text['split']) {
            $this->record($msg->id, 'NotSplit', $text, null, $tokens);

            return 'notsplit';
        }

        $attachments = array_values(array_filter(
            DB::table('messages_attachments')
                ->select('id', 'externaluid', 'externalmods', 'contenttype')
                ->where('msgid', $msg->id)
                ->orderByDesc('primary')
                ->orderBy('id')
                ->get()
                ->all(),
            [PhotoSplitter::class, 'eligible'],
        ));
        $attachments = array_slice($attachments, 0, max(1, (int) config('freegle.autosplit.max_photos', 8)));

        $photos = ['photos' => []];
        if ($attachments) {
            $photos = $this->photos->split($text['items'], $attachments);
            if ($photos === null) {
                return 'retry';
            }
            $this->addUsage($tokens, $this->photos->usage());
        }

        if ($mode === self::MODE_SHADOW) {
            $this->record($msg->id, 'Shadow', $text, $photos, $tokens);

            return 'shadow';
        }

        return $this->apply($msg, $text, $photos, $attachments, $tokens) ? 'split' : 'notsplit';
    }

    /**
     * Write the split in one transaction. The models take a while, and the first minutes after
     * posting are when posters fix things, so the post is locked and checked again here: still
     * an open Offer, still not a clearance, and only the photos that still exist. The
     * messages_autosplit row goes in first: its unique msgid claims the post, so two runs cannot
     * both split it.
     */
    private function apply(object $msg, array $text, array $photos, array $attachments, array $tokens): bool
    {
        $ids = array_map(fn ($a) => (int) $a->id, $attachments);

        try {
            return DB::transaction(function () use ($msg, $text, $photos, $ids, $tokens) {
                $now = DB::table('messages')->select('id', 'type', 'deleted', 'availableinitially', 'availablenow')
                    ->where('id', $msg->id)->lockForUpdate()->first();
                if (! $now || $now->deleted !== null || $now->type !== 'Offer'
                    || DB::table('messages_outcomes')->where('msgid', $msg->id)->exists()
                    || DB::table('messages_bulk_items')->where('msgid', $msg->id)->exists()) {
                    return false;
                }
                $msg = $now;

                // A photo deleted while the models were working is not brought back as crops.
                $byId = [];
                foreach (DB::table('messages_attachments')->select('id', 'externaluid', 'externalmods', 'contenttype')
                    ->where('msgid', $msg->id)->whereIn('id', $ids ?: [0])->get() as $a) {
                    $byId[(int) $a->id] = $a;
                }

                $rowId = DB::table('messages_autosplit')->insertGetId($this->row($msg->id, 'Split', $text, $photos, $tokens) + [
                    'originalavailableinitially' => max(0, (int) $msg->availableinitially),
                    'originalavailablenow' => max(0, (int) $msg->availablenow),
                ]);

                $itemIds = [];
                foreach ($text['items'] as $position => $item) {
                    $itemIds[$position + 1] = DB::table('messages_bulk_items')->insertGetId([
                        'msgid' => $msg->id,
                        'position' => $position,
                        'name' => $item['name'],
                        'quantity' => $item['quantity'],
                        'available' => 1,
                        'condition' => 'Unknown',
                    ]);
                }

                $created = [];
                foreach ($photos['photos'] as $photo) {
                    $original = $byId[$photo['attachmentid']] ?? null;
                    if (! $original) {
                        continue;
                    }
                    if ($photo['action'] === 'whole') {
                        $this->link($itemIds[$photo['item']], $original->id);
                    } elseif ($photo['action'] === 'crop') {
                        foreach ($photo['crops'] as $crop) {
                            $id = DB::table('messages_attachments')->insertGetId([
                                'msgid' => $msg->id,
                                'externaluid' => $original->externaluid,
                                'externalmods' => json_encode(self::cropMods($original->externalmods, $crop, $photo['width'], $photo['height'])),
                                'contenttype' => $original->contenttype ?: 'image/jpeg',
                                'primary' => 0,
                            ]);
                            $created[] = $id;
                            $this->link($itemIds[$crop['item']], $id);
                        }
                    }
                }

                $total = array_sum(array_column($text['items'], 'quantity'));
                DB::table('messages')->where('id', $msg->id)->update([
                    'availableinitially' => $total,
                    'availablenow' => $total,
                ]);
                DB::table('messages_autosplit')->where('id', $rowId)->update(['createdattachments' => json_encode($created)]);

                Log::info('AutoSplit: split message', ['msgid' => $msg->id, 'items' => count($itemIds), 'crops' => count($created)]);

                return true;
            });
        } catch (QueryException $e) {
            // A duplicate msgid means another run decided this post first.
            if (($e->errorInfo[1] ?? null) === 1062) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * The crop is kept in the attachment's externalmods, in pixels of the photo as the poster
     * turned it, beside that rotation: delivery applies ro first and then the crop, and precrop
     * makes it crop before any resize. fw and fh are the turned photo's size, so the crop can
     * turn with the photo if the poster turns it again (Go misc.Mods.Rotated). The Go API and
     * the frontend put ro, cx, cy, cw, ch and precrop on the delivery URL.
     *
     * No hash: the content check counts how often a photo's hash appears in a day, and copies
     * of one photo would make an ordinary post look like a repeated one.
     */
    public static function cropMods(?string $originalMods, array $crop, int $width, int $height): array
    {
        $mods = json_decode((string) $originalMods, true);
        $out = [];
        if (is_array($mods) && ! empty($mods['rotate'])) {
            $out['rotate'] = (int) $mods['rotate'];
        }

        return $out + [
            'cx' => $crop['cx'], 'cy' => $crop['cy'], 'cw' => $crop['cw'], 'ch' => $crop['ch'],
            'fw' => $width, 'fh' => $height, 'precrop' => true,
        ];
    }

    /**
     * Every item still on offer has gone with the post. Called wherever a split post is marked
     * Taken as a whole: its stats are counted per item, as a clearance's are, and the whole-post
     * outcome is left out of them, so without this the post would count nothing. The Go API does
     * the same for a TAKEN the poster records (markSplitItemsGone).
     */
    public static function markItemsGone(int $msgid): void
    {
        if (! DB::table('messages_autosplit')->where('msgid', $msgid)->where('state', 'Split')->exists()) {
            return;
        }
        DB::table('messages_bulk_items')->where('msgid', $msgid)->where('available', 1)->update(['available' => 0]);
        DB::table('messages')->where('id', $msgid)->update(['availablenow' => 0]);
    }

    private function link(int $bulkitemid, int $attachmentid): void
    {
        DB::table('messages_bulk_item_attachments')->insertOrIgnore([
            'bulkitemid' => $bulkitemid,
            'attachmentid' => $attachmentid,
        ]);
    }

    private function record(int $msgid, string $state, array $text, ?array $photos, array $tokens): void
    {
        DB::table('messages_autosplit')->insertOrIgnore($this->row($msgid, $state, $text, $photos, $tokens));
    }

    private function row(int $msgid, string $state, array $text, ?array $photos, array $tokens): array
    {
        return [
            'msgid' => $msgid,
            'state' => $state,
            'kind' => $text['kind'],
            'items' => json_encode($text['items']),
            'photos' => $photos === null ? null : json_encode($photos['photos']),
            'model' => $this->gemini->model(),
            'tokensin' => $tokens[0],
            'tokensout' => $tokens[1],
        ];
    }

    private function addUsage(array &$tokens, array $usage): void
    {
        $tokens[0] += $usage[0];
        $tokens[1] += $usage[1];
    }
}
