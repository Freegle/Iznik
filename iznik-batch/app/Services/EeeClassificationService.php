<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Core EEE classification logic.
 *
 * Two-tier strategy:
 *  Tier 1 — Item-type lookup: sample N images per item type, build consensus.
 *            Applied to all messages of that type without individual API calls.
 *  Tier 2 — Per-image analysis: for unknown/ambiguous types and long-tail items.
 */
class EeeClassificationService
{
    protected const SAMPLE_SIZE        = 10;
    protected const CONFIDENCE_MIN     = 0.92;
    protected const AGREE_RATE_MIN     = 0.85;

    protected const EEE_TEXT_SIGNALS = [
        'plug', 'mains', 'electric', 'electrical', 'battery', 'batteries',
        'usb', 'charger', 'charging', 'power supply', 'adaptor', 'adapter',
        'solar', 'rechargeable', 'cordless', 'wireless', 'bluetooth', 'wifi',
        'wi-fi', 'smart', 'digital', 'lcd', 'led', 'screen', 'display',
        'remote control', 'remote', 'speaker', 'headphones', 'earphones',
        'keyboard', 'mouse', 'printer', 'scanner', 'monitor', 'laptop',
        'tablet', 'phone', 'mobile', 'router', 'modem', 'console',
    ];

    protected const NON_EEE_TEXT_SIGNALS = [
        'no batteries', 'not electric', 'no plug', 'manual', 'hand-powered',
        'wind-up', 'non-electric', 'mechanical only',
    ];

    public function __construct(
        protected EeeVisionService $vision,
        protected EeeSqliteService $sqlite,
    ) {}

    // -------------------------------------------------------------------------
    // Tier 1 — Item-type lookup cache
    // -------------------------------------------------------------------------

    /**
     * Classify the top $limit item types by popularity.
     * Skips types already in the cache unless $forceRefresh is true.
     */
    public function classifyItemTypes(int $limit, bool $forceRefresh = false, ?callable $progress = null): array
    {
        $items = DB::table('items')
            ->orderByDesc('popularity')
            ->limit($limit)
            ->pluck('popularity', 'name')
            ->toArray();

        $toClassify = $forceRefresh
            ? array_keys($items)
            : $this->sqlite->getUnclassifiedItemTypeNames(array_keys($items));

        $stats = ['processed' => 0, 'eee' => 0, 'skipped' => 0, 'cost' => 0.0];

        foreach ($toClassify as $itemName) {
            $result = $this->classifySingleItemType($itemName, $items[$itemName] ?? 0);
            if ($result === null) {
                $stats['skipped']++;
            } else {
                $stats['processed']++;
                $stats['cost'] += $result['cost'];
                if ($result['is_eee']) $stats['eee']++;
            }
            if ($progress) {
                ($progress)($itemName, $result);
            }
        }

        return $stats;
    }

    protected function classifySingleItemType(string $itemName, int $popularity): ?array
    {
        $attachments = DB::table('messages_attachments as ma')
            ->join('messages as m', 'm.id', '=', 'ma.msgid')
            ->join('messages_items as mi', 'mi.msgid', '=', 'm.id')
            ->join('items as i', 'i.id', '=', 'mi.itemid')
            ->where('i.name', $itemName)
            ->whereNotNull('ma.externaluid')
            ->where('ma.archived', 0)
            ->whereRaw("(ma.externalmods IS NULL OR JSON_EXTRACT(ma.externalmods, '$.ai') IS NULL)")
            ->inRandomOrder()
            ->limit(self::SAMPLE_SIZE)
            ->select(['ma.id as attid', 'ma.externaluid', 'm.id as messageid', 'm.subject', 'm.textbody'])
            ->get();

        if ($attachments->isEmpty()) {
            return null;
        }

        $results   = [];
        $totalCost = 0.0;

        foreach ($attachments as $att) {
            $result = $this->vision->analyse(
                EeeVisionService::buildImageUrl($att->externaluid),
                ['subject' => $att->subject ?? '', 'description' => $att->textbody ?? '']
            );
            if ($result !== null) {
                $results[]  = $result;
                $totalCost += $result['_meta']['cost_usd'] ?? 0.0;
            }
        }

        if (empty($results)) {
            return null;
        }

        $consensus = $this->computeConsensus($results);

        $this->sqlite->upsertItemType([
            'item_name'               => $itemName,
            'item_id'                 => DB::table('items')->where('name', $itemName)->value('id'),
            'popularity'              => $popularity,
            'sample_size'             => self::SAMPLE_SIZE,
            'images_analysed'         => count($results),
            'eee_sample_count'        => $consensus['eee_sample_count'],
            'is_eee'                  => $consensus['is_eee'] ? 1 : 0,
            'is_eee_confidence'       => $consensus['is_eee_confidence'],
            'is_eee_agree_rate'       => $consensus['is_eee_agree_rate'],
            'weee_category'           => $consensus['weee_category'],
            'weee_category_name'      => $consensus['weee_category_name'],
            'weee_category_confidence' => $consensus['weee_category_confidence'],
            'needs_image_analysis'    => $consensus['needs_image_analysis'] ? 1 : 0,
            'model'                   => $this->vision->getModelName(),
            'prompt_version'          => $this->vision->getPromptVersion(),
            'classified_at'           => now()->toIso8601String(),
        ]);

        return ['is_eee' => $consensus['is_eee'], 'cost' => $totalCost];
    }

    // -------------------------------------------------------------------------
    // Per-message classification
    // -------------------------------------------------------------------------

    /**
     * Classify a message using a specific driver, bypassing the item-type cache.
     * Used by eee:compare-models to run the same images through multiple models.
     */
    public function classifyMessageWithDriver(int $messageid, string $driver): ?array
    {
        $vision = $this->vision->withDriver($driver);

        if ($this->sqlite->hasClassification($messageid, $vision->getModelName())) {
            return null;
        }

        $message = DB::table('messages as m')
            ->where('m.id', $messageid)
            ->select(['m.id', 'm.subject', 'm.textbody'])
            ->first();

        if (!$message) {
            return null;
        }

        $att = DB::table('messages_attachments')
            ->where('msgid', $messageid)
            ->whereNotNull('externaluid')
            ->where('archived', 0)
            ->orderBy('id')
            ->first(['id as attid', 'externaluid']);

        if (!$att) {
            return null;
        }

        $context = [
            'subject'     => $message->subject ?? '',
            'description' => $message->textbody ?? '',
        ];

        $result = $vision->analyse(EeeVisionService::buildImageUrl($att->externaluid), $context);
        if ($result === null) {
            return null;
        }

        $meta = $result['_meta'];

        $data = [
            'messageid'                => $message->id,
            'attid'                    => $att->attid,
            'model'                    => $meta['model'],
            'prompt_version'           => $vision->getPromptVersion(),
            'run_at'                   => now()->toIso8601String(),
            'data_sources'             => json_encode(['image' => true, 'type_lookup' => false, 'text' => false, 'chat' => false]),
            'is_eee'                   => ($result['is_eee'] ?? false) ? 1 : 0,
            'is_eee_confidence'        => $result['is_eee_confidence'] ?? 0.0,
            'is_eee_reasoning'         => $result['is_eee_reasoning'] ?? null,
            'is_unusual_eee'           => ($result['is_unusual_eee'] ?? false) ? 1 : 0,
            'unusual_eee_reason'       => $result['unusual_eee_reason'] ?? null,
            'weee_category'            => $result['weee_category'] ?? null,
            'weee_category_name'       => $result['weee_category_name'] ?? null,
            'weee_category_confidence' => $result['weee_category_confidence'] ?? null,
            'weight_kg_min'            => $result['weight_kg_min'] ?? null,
            'weight_kg_max'            => $result['weight_kg_max'] ?? null,
            'weight_kg_confidence'     => $result['weight_kg_confidence'] ?? null,
            'size_cm'                  => isset($result['size_cm']) ? json_encode($result['size_cm']) : null,
            'size_confidence'          => $result['size_confidence'] ?? null,
            'condition'                => $result['condition'] ?? null,
            'condition_confidence'     => $result['condition_confidence'] ?? null,
            'brand'                    => $result['brand'] ?? null,
            'brand_confidence'         => $result['brand_confidence'] ?? null,
            'model_number'             => $result['model_number'] ?? null,
            'model_number_confidence'  => $result['model_number_confidence'] ?? null,
            'material_primary'         => $result['material_primary'] ?? null,
            'material_secondary'       => $result['material_secondary'] ?? null,
            'material_confidence'      => $result['material_confidence'] ?? null,
            'primary_item'             => $result['primary_item'] ?? null,
            'short_description'        => $result['short_description'] ?? null,
            'long_description'         => $result['long_description'] ?? null,
            'photo_quality'            => $result['photo_quality'] ?? null,
            'photo_quality_notes'      => $result['photo_quality_notes'] ?? null,
            'item_complete'            => isset($result['item_complete']) ? ($result['item_complete'] ? 1 : 0) : null,
            'item_complete_confidence' => $result['item_complete_confidence'] ?? null,
            'item_complete_notes'      => $result['item_complete_notes'] ?? null,
            'accessories_visible'      => isset($result['accessories_visible']) ? json_encode($result['accessories_visible']) : null,
            'value_band_gbp'           => $result['value_band_gbp'] ?? null,
            'value_band_confidence'    => $result['value_band_confidence'] ?? null,
            'text_eee_signals'         => null,
            'conflict_flag'            => 0,
            'raw_response'             => $meta['raw_response'] ?? null,
            'input_tokens'             => $meta['input_tokens'] ?? 0,
            'output_tokens'            => $meta['output_tokens'] ?? 0,
            'cost_usd'                 => $meta['cost_usd'] ?? 0.0,
        ];

        $this->sqlite->insertClassification($data);
        return $data;
    }

    public function classifyMessage(int $messageid): ?array
    {
        if ($this->sqlite->hasClassification($messageid, $this->vision->getModelName())) {
            return null;
        }

        $message = DB::table('messages as m')
            ->leftJoin('messages_items as mi', 'mi.msgid', '=', 'm.id')
            ->leftJoin('items as i', 'i.id', '=', 'mi.itemid')
            ->where('m.id', $messageid)
            ->select(['m.id', 'm.subject', 'm.textbody', 'i.name as item_name'])
            ->first();

        if (!$message) {
            return null;
        }

        $textSignals = $this->extractTextSignals($message->subject . ' ' . $message->textbody);

        // Try the item-type lookup as a definitive skip ONLY for non-EEE homogeneous types.
        // Rules:
        //  1. EEE types: always run per-image (lookup is a prior only; attributes vary by instance).
        //  2. Non-EEE types with any EEE minority (eee_sample_count > 0): always per-image (mixed type).
        //  3. EEE text signals present despite non-EEE type: escalate to per-image.
        //  4. Non-EEE + zero EEE minority + high confidence/agreement + no EEE text: safe to skip.
        if ($message->item_name) {
            $type = $this->sqlite->getItemType($message->item_name);
            if ($type
                && !$type['needs_image_analysis']
                && !$type['is_eee']
                && (int) ($type['eee_sample_count'] ?? 1) === 0
                && $type['is_eee_confidence'] >= self::CONFIDENCE_MIN
                && $type['is_eee_agree_rate']  >= self::AGREE_RATE_MIN
                && empty($textSignals['eee'])
            ) {
                return $this->storeTypeLookupResult($message, $type, $textSignals);
            }
        }

        return $this->classifyByImage($message, $textSignals);
    }

    protected function classifyByImage(object $message, array $textSignals): ?array
    {
        $att = DB::table('messages_attachments')
            ->where('msgid', $message->id)
            ->whereNotNull('externaluid')
            ->where('archived', 0)
            ->whereRaw("(externalmods IS NULL OR JSON_EXTRACT(externalmods, '$.ai') IS NULL)")
            ->orderBy('id')
            ->first(['id as attid', 'externaluid']);

        $context = [
            'subject'     => $message->subject ?? '',
            'description' => $message->textbody ?? '',
        ];

        if (config('freegle.eee.use_chat_data')) {
            $context['chat'] = $this->fetchChatContext($message->id);
        }

        if (!$att) {
            return $this->storeTextOnlyResult($message, $textSignals);
        }

        $result = $this->vision->analyse(EeeVisionService::buildImageUrl($att->externaluid), $context);
        if ($result === null) {
            return null;
        }

        return $this->storeImageResult($message, $att->attid, $result, $textSignals, $context);
    }

    // -------------------------------------------------------------------------
    // Storing results
    // -------------------------------------------------------------------------

    protected function storeTypeLookupResult(object $message, array $type, array $textSignals): array
    {
        $conflictFlag = ($textSignals['non_eee'] && $type['is_eee'])
                     || ($textSignals['eee']     && !$type['is_eee']) ? 1 : 0;

        $data = [
            'messageid'                => $message->id,
            'attid'                    => null,
            'model'                    => $type['model'],
            'prompt_version'           => $type['prompt_version'],
            'run_at'                   => now()->toIso8601String(),
            'data_sources'             => json_encode(['image' => false, 'type_lookup' => true, 'text' => !empty($textSignals['eee']), 'chat' => false]),
            'is_eee'                   => $type['is_eee'],
            'is_eee_confidence'        => $type['is_eee_confidence'],
            'is_eee_reasoning'         => 'Applied from item-type consensus.',
            'weee_category'            => $type['weee_category'],
            'weee_category_name'       => $type['weee_category_name'],
            'weee_category_confidence' => $type['weee_category_confidence'],
            'text_eee_signals'         => json_encode($textSignals['eee']),
            'conflict_flag'            => $conflictFlag,
            'cost_usd'                 => 0.0,
        ];

        $this->sqlite->insertClassification($data);
        return $data;
    }

    protected function storeImageResult(object $message, int $attid, array $result, array $textSignals, array $context): array
    {
        $meta             = $result['_meta'];
        $isEee            = (bool) ($result['is_eee'] ?? false);
        $isEeeConfidence  = (float) ($result['is_eee_confidence'] ?? 0.5);
        $conflictFlag     = 0;

        if (!empty($textSignals['non_eee']) && $isEee)  $conflictFlag = 1;
        if (!empty($textSignals['eee'])     && !$isEee) $conflictFlag = 1;
        if (!empty($textSignals['eee'])     && $isEee)  $isEeeConfidence = min(1.0, $isEeeConfidence + 0.05);

        $data = [
            'messageid'                => $message->id,
            'attid'                    => $attid,
            'model'                    => $meta['model'],
            'prompt_version'           => $this->vision->getPromptVersion(),
            'run_at'                   => now()->toIso8601String(),
            'data_sources'             => json_encode(['image' => true, 'type_lookup' => false, 'text' => !empty($textSignals['eee']), 'chat' => !empty($context['chat'])]),
            'is_eee'                   => $isEee ? 1 : 0,
            'is_eee_confidence'        => round($isEeeConfidence, 4),
            'is_eee_reasoning'         => $result['is_eee_reasoning'] ?? null,
            'is_unusual_eee'           => ($result['is_unusual_eee'] ?? false) ? 1 : 0,
            'unusual_eee_reason'       => $result['unusual_eee_reason'] ?? null,
            'weee_category'            => $result['weee_category'] ?? null,
            'weee_category_name'       => $result['weee_category_name'] ?? null,
            'weee_category_confidence' => $result['weee_category_confidence'] ?? null,
            'weight_kg_min'            => $result['weight_kg_min'] ?? null,
            'weight_kg_max'            => $result['weight_kg_max'] ?? null,
            'weight_kg_confidence'     => $result['weight_kg_confidence'] ?? null,
            'size_cm'                  => isset($result['size_cm']) ? json_encode($result['size_cm']) : null,
            'size_confidence'          => $result['size_confidence'] ?? null,
            'condition'                => $result['condition'] ?? null,
            'condition_confidence'     => $result['condition_confidence'] ?? null,
            'brand'                    => $result['brand'] ?? null,
            'brand_confidence'         => $result['brand_confidence'] ?? null,
            'model_number'             => $result['model_number'] ?? null,
            'model_number_confidence'  => $result['model_number_confidence'] ?? null,
            'material_primary'         => $result['material_primary'] ?? null,
            'material_secondary'       => $result['material_secondary'] ?? null,
            'material_confidence'      => $result['material_confidence'] ?? null,
            'primary_item'             => $result['primary_item'] ?? null,
            'short_description'        => $result['short_description'] ?? null,
            'long_description'         => $result['long_description'] ?? null,
            'photo_quality'            => $result['photo_quality'] ?? null,
            'photo_quality_notes'      => $result['photo_quality_notes'] ?? null,
            'item_complete'            => isset($result['item_complete']) ? ($result['item_complete'] ? 1 : 0) : null,
            'item_complete_confidence' => $result['item_complete_confidence'] ?? null,
            'item_complete_notes'      => $result['item_complete_notes'] ?? null,
            'accessories_visible'      => isset($result['accessories_visible']) ? json_encode($result['accessories_visible']) : null,
            'value_band_gbp'           => $result['value_band_gbp'] ?? null,
            'value_band_confidence'    => $result['value_band_confidence'] ?? null,
            'text_eee_signals'         => json_encode($textSignals['eee']),
            'chat_eee_signals'         => isset($context['chat'])
                ? json_encode($this->extractTextSignals($context['chat'])['eee'])
                : null,
            'conflict_flag'            => $conflictFlag,
            'raw_response'             => $meta['raw_response'] ?? null,
            'input_tokens'             => $meta['input_tokens'] ?? 0,
            'output_tokens'            => $meta['output_tokens'] ?? 0,
            'cost_usd'                 => $meta['cost_usd'] ?? 0.0,
        ];

        $this->sqlite->insertClassification($data);
        return $data;
    }

    protected function storeTextOnlyResult(object $message, array $textSignals): array
    {
        $isEee = !empty($textSignals['eee']) && empty($textSignals['non_eee']);

        $data = [
            'messageid'         => $message->id,
            'attid'             => null,
            'model'             => 'text-only',
            'prompt_version'    => $this->vision->getPromptVersion(),
            'run_at'            => now()->toIso8601String(),
            'data_sources'      => json_encode(['image' => false, 'type_lookup' => false, 'text' => true, 'chat' => false]),
            'is_eee'            => $isEee ? 1 : 0,
            'is_eee_confidence' => $isEee ? 0.60 : 0.50,
            'is_eee_reasoning'  => 'Text-only: no image available.',
            'text_eee_signals'  => json_encode($textSignals['eee']),
            'conflict_flag'     => 0,
            'cost_usd'          => 0.0,
        ];

        $this->sqlite->insertClassification($data);
        return $data;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function computeConsensus(array $results): array
    {
        $isEeeVotes  = array_filter(array_map(fn($r) => $r['is_eee'] ?? null, $results), fn($v) => $v !== null);
        $eeeCount    = count(array_filter($isEeeVotes, fn($v) => $v === true));
        $totalVotes  = count($isEeeVotes);
        $agreeCount  = max($eeeCount, $totalVotes - $eeeCount);
        $agreeRate   = $totalVotes > 0 ? $agreeCount / $totalVotes : 0.0;
        $isEee       = $totalVotes > 0 && ($eeeCount / $totalVotes) > 0.5;
        $eeeSampleCount = $eeeCount;

        $confidences    = array_filter(array_map(fn($r) => $r['is_eee_confidence'] ?? null, $results));
        $meanConf       = count($confidences) > 0 ? array_sum($confidences) / count($confidences) : 0.0;

        $categories     = array_filter(array_map(fn($r) => $r['weee_category'] ?? null, $results));
        $catMode        = null;
        $catName        = null;
        $catConf        = 0.0;
        if (!empty($categories)) {
            $freq   = array_count_values(array_map('strval', $categories));
            arsort($freq);
            $top    = (int) array_key_first($freq);
            $catMode = $top;
            $catName = EeeVisionService::WEEE_CATEGORIES[$top] ?? null;
            $catConf = $freq[strval($top)] / count($categories);
        }

        return [
            'is_eee'                  => $isEee,
            'is_eee_confidence'       => round($meanConf, 4),
            'is_eee_agree_rate'       => round($agreeRate, 4),
            'eee_sample_count'        => $eeeSampleCount,
            'weee_category'           => $catMode,
            'weee_category_name'      => $catName,
            'weee_category_confidence' => round($catConf, 4),
            'needs_image_analysis'    => $agreeRate < self::AGREE_RATE_MIN || $meanConf < self::CONFIDENCE_MIN,
        ];
    }

    public function extractTextSignals(string $text): array
    {
        $lower  = strtolower($text);
        return [
            'eee'     => array_values(array_filter(self::EEE_TEXT_SIGNALS,     fn($s) => str_contains($lower, $s))),
            'non_eee' => array_values(array_filter(self::NON_EEE_TEXT_SIGNALS, fn($s) => str_contains($lower, $s))),
        ];
    }

    protected function fetchChatContext(int $messageid): string
    {
        return DB::table('chatmessages as cm')
            ->join('chat_rooms as cr', 'cr.id', '=', 'cm.chatid')
            ->where('cr.messageid', $messageid)
            ->orderBy('cm.date')
            ->limit(5)
            ->pluck('cm.message')
            ->filter()
            ->implode("\n");
    }
}
