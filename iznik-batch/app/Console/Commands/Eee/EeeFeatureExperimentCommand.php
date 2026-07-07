<?php

namespace App\Console\Commands\Eee;

use App\Services\EeeVisionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Research experiment: component-observation approach to EEE classification.
 *
 * Instead of asking "is this EEE?" or listing a fixed taxonomy of features,
 * ask the model to describe any components it can see that use electricity in
 * any form. Derive contains_eee_components from that observation. Derive
 * is_eee (strictly EEE under WEEE) separately from text signals.
 *
 * Hypothesis: models are reliable at "describe what uses electricity here"
 * (open-ended observation), and a gas cooker will correctly report its ignition
 * circuit / clock as electrical components while the text signals identify it
 * as non-EEE (primary function = gas combustion).
 *
 * Run: php artisan eee:feature-experiment --items="Gas Cooker,Electric Cooker,Chainsaw"
 */
class EeeFeatureExperimentCommand extends Command
{
    protected $signature = 'eee:feature-experiment
                            {--items= : Comma-separated item names to test}
                            {--sample=3 : Images per item type}';

    protected $description = 'Research: open-ended electrical-component observation approach (experimental)';

    public function handle(): int
    {
        $itemsOpt = $this->option('items');
        $sample   = max(1, (int) $this->option('sample'));

        if (!$itemsOpt) {
            $this->error('Specify --items="Item Name,Another Item"');
            return Command::FAILURE;
        }

        $itemNames = array_map('trim', explode(',', $itemsOpt));

        $this->info("EEE component-observation experiment | items: " . implode(', ', $itemNames));
        $this->info("Approach: open-ended 'what uses electricity here?' → contains_eee_components; text → is_eee");
        $this->newLine();

        foreach ($itemNames as $itemName) {
            $this->processItem($itemName, $sample);
            $this->newLine();
        }

        return Command::SUCCESS;
    }

    protected function processItem(string $itemName, int $sample): void
    {
        $this->line("<comment>── {$itemName} ──</comment>");

        $attachments = DB::table('messages_attachments as ma')
            ->join('messages as m', 'm.id', '=', 'ma.msgid')
            ->whereExists(fn($q) => $q->select(DB::raw(1))
                ->from('messages_items as mi')
                ->join('items as i', 'i.id', '=', 'mi.itemid')
                ->whereColumn('mi.msgid', 'm.id')
                ->where('i.name', $itemName))
            ->whereNotNull('ma.externaluid')
            ->where('ma.archived', 0)
            ->where('ma.primary', 1)
            ->where('m.type', 'Offer')   // exclude WANTED posts — they use stock illustrations
            ->orderByDesc('m.arrival')
            ->limit($sample)
            ->select(['ma.externaluid', 'm.subject', 'm.textbody'])
            ->get();

        if ($attachments->isEmpty()) {
            $this->warn("  No images found.");
            return;
        }

        $urls      = $attachments->map(fn($a) => EeeVisionService::buildImageUrl($a->externaluid))->all();
        $imageData = $this->fetchImages($urls);

        $jobs = [];
        foreach ($attachments as $i => $att) {
            if ($imageData[$i] === null) continue;
            $jobs[] = [
                'img'     => $imageData[$i],
                'subject' => $att->subject ?? '',
                'desc'    => $att->textbody ?? '',
            ];
        }

        if (empty($jobs)) {
            $this->warn("  All image fetches failed.");
            return;
        }

        $results = $this->runComponentObservation($jobs);

        $allElectricalComponents = [];
        $itemsWithElectrical     = 0;
        $itemsStrictlyEee        = 0;

        foreach ($results as $idx => $r) {
            if ($r === null) continue;

            $components  = $r['electrical_components_observed'] ?? [];
            $textSignals = $r['text_power_signals']             ?? [];
            $isEee       = $r['is_eee_from_text']              ?? null;
            $containsEee = !empty($components);

            foreach ($components as $c) {
                $allElectricalComponents[$c] = ($allElectricalComponents[$c] ?? 0) + 1;
            }
            if ($containsEee) $itemsWithElectrical++;
            if ($isEee === true) $itemsStrictlyEee++;

            $imageLabel  = "Image " . ($idx + 1) . " (\"" . substr($jobs[$idx]['subject'], 0, 40) . "\")";
            $eeeIcon     = $isEee === true ? '<info>EEE</info>' : ($isEee === false ? '<fg=gray>non-EEE</fg=gray>' : '<comment>uncertain</comment>');
            $containsTag = $containsEee ? '<info>contains-EEE-components</info>' : '<fg=gray>no-EEE-components</fg=gray>';

            $this->line("  {$imageLabel}");
            $this->line("    Components using electricity:  " . (empty($components) ? '(none seen)' : implode('; ', $components)));
            $this->line("    Text power signals:           " . (empty($textSignals) ? '(none)' : implode(', ', $textSignals)));
            $this->line("    is_eee (from text):           {$eeeIcon}  |  {$containsTag}");
            $this->line("    Notes: " . ($r['observation_notes'] ?? '(none)'));
        }

        $n = count(array_filter($results));
        $this->newLine();
        $this->line("  <comment>Summary across {$n} image(s):</comment>");
        $this->line("    Images with electrical components: {$itemsWithElectrical}/{$n}");
        $this->line("    Images classified strictly EEE:    {$itemsStrictlyEee}/{$n}");
        if (!empty($allElectricalComponents)) {
            arsort($allElectricalComponents);
            $this->line("    Components seen (image count):");
            foreach ($allElectricalComponents as $component => $cnt) {
                $this->line("      ({$cnt}x) {$component}");
            }
        }
    }

    protected function runComponentObservation(array $jobs): array
    {
        $system = <<<'PROMPT'
You are examining a photo of a second-hand item for Freegle (UK free reuse platform).

Your task has three parts:

PART 1 — ELECTRICAL COMPONENTS (image only):
List every component you can directly observe in the photo that uses mains power, battery power, USB, solar, or any other external electrical supply. Describe each one briefly in plain English (e.g. "digital display panel", "mains power flex", "LED strip lights", "electronic control board", "battery compartment"). If you cannot see any such components, return an empty list.

Be inclusive: even a clock, indicator light, or built-in rechargeable counts. Only include components that are part of the item being offered — exclude items visible in the background that are clearly separate. Do not classify whether the item as a whole is electrical — just list the components you can see.

PART 2 — TEXT SIGNALS (title and description only):
From the item title and description (if provided), extract any words or phrases that explicitly name the item's primary power source or how the item is used. Examples: "gas cooker", "petrol engine", "electric", "battery-powered", "manual", "solar", "cordless", "wind-up", "no electricity needed".

PART 3 — IS_EEE FROM TEXT:
Based on the text signals only (ignore the image for this part), decide whether this item's PRIMARY FUNCTION requires electricity to work. The WEEE test: would the item be completely unable to perform its main purpose without electricity?
- true  = primary function requires electricity (electric cooker, laptop, cordless drill)
- false = primary function does not require electricity (gas cooker, petrol lawnmower, manual wheelchair, acoustic guitar)
- null  = text signals insufficient to decide

PART 4 — PHYSICAL ATTRIBUTES:
Extract what you can observe about the item.

Return ONLY valid JSON:
{
  "electrical_components_observed": ["digital display panel", "mains power cable"],
  "text_power_signals": ["gas cooker"],
  "is_eee_from_text": true/false/null,
  "observation_notes": "brief note on anything ambiguous or notable, or null",
  "photo_quality": 1-5,
  "photo_quality_notes": "notes or null",
  "primary_item": "main item name",
  "brand": "brand or null",
  "brand_confidence": 0.0-1.0,
  "model_number": "model code if legible or null",
  "model_number_confidence": 0.0-1.0,
  "material_primary": "dominant material",
  "material_secondary": "secondary material or null",
  "weight_kg_min": null,
  "weight_kg_max": null,
  "size_cm": {"w": null, "h": null, "d": null},
  "condition": "Reusable" or "Damaged" or "Unknown",
  "condition_confidence": 0.0-1.0,
  "item_complete": true/false/null,
  "item_complete_notes": "e.g. missing lid or null",
  "value_band_gbp": "0-20" or "20-100" or "100-500" or "500+" or null,
  "short_description": "one sentence from giver perspective"
}
PROMPT;

        $headers = [
            'x-api-key'         => config('freegle.eee.anthropic_api_key'),
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ];

        $responses = Http::pool(function ($pool) use ($jobs, $system, $headers) {
            return array_map(function ($job) use ($pool, $system, $headers) {
                $userText = 'Examine this item.';
                if (!empty($job['subject'])) $userText .= "\nTitle: "       . $job['subject'];
                if (!empty($job['desc']))    $userText .= "\nDescription: " . $job['desc'];

                return $pool->withHeaders($headers)->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                    'model'      => config('freegle.eee.claude_model', 'claude-sonnet-4-6'),
                    'max_tokens' => 1024,
                    'system'     => $system,
                    'messages'   => [['role' => 'user', 'content' => [
                        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $job['img']['mime_type'], 'data' => $job['img']['base64']]],
                        ['type' => 'text', 'text' => $userText],
                    ]]],
                ]);
            }, $jobs);
        });

        return array_map(function ($response) {
            if ($response instanceof \Throwable || !$response->successful()) return null;
            $text = trim($response->json('content.0.text', ''));
            if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $text, $m)) $text = $m[1];
            $start = strpos($text, '{');
            $end   = strrpos($text, '}');
            if ($start === false || $end === false) return null;
            return json_decode(substr($text, $start, $end - $start + 1), true);
        }, $responses);
    }

    protected function fetchImages(array $urls): array
    {
        $responses = Http::pool(fn($pool) => array_map(
            fn($url) => $pool->timeout(30)->get($url),
            $urls
        ));

        return array_map(function ($r) {
            if ($r instanceof \Throwable || !$r->successful()) return null;
            $mime = trim(explode(';', $r->header('Content-Type') ?? '')[0]);
            if (!str_starts_with($mime, 'image/')) return null;
            return ['base64' => base64_encode($r->body()), 'mime_type' => $mime];
        }, $responses);
    }
}
