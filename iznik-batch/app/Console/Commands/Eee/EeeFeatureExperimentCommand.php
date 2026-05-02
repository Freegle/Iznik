<?php

namespace App\Console\Commands\Eee;

use App\Services\EeeVisionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Research experiment: feature-detection approach to EEE classification.
 *
 * Instead of asking "is this EEE?", ask the model to list specific observable
 * electrical and non-electrical features. Derive the WEEE judgment from those
 * feature observations via deterministic rules.
 *
 * Hypothesis: models are more reliable at "is this feature visible?" than at
 * "make an overall EEE judgment", and the feature list is directly auditable.
 *
 * Run: php artisan eee:feature-experiment --items="Gas Cooker,Electric Cooker,Chainsaw"
 */
class EeeFeatureExperimentCommand extends Command
{
    protected $signature = 'eee:feature-experiment
                            {--items= : Comma-separated item names to test}
                            {--sample=3 : Images per item type}';

    protected $description = 'Research: feature-detection approach to EEE classification (experimental)';

    // Observable electrical features — things a model can directly see or read.
    public const ELECTRICAL_FEATURES = [
        'mains_cable'        => 'Electrical power cable/flex visible',
        'mains_plug'         => 'Mains plug (3-pin or 2-pin) visible',
        'battery_compartment'=> 'Battery door or compartment visible',
        'display_screen'     => 'Digital/LCD/LED display panel or screen',
        'control_panel'      => 'Electronic buttons, switches, or touchpad (not simple mechanical dials)',
        'charging_port'      => 'USB or proprietary charging port visible',
        'solar_panel'        => 'Photovoltaic/solar panel visible',
        'motor_housing'      => 'Motor unit, motor housing, or "motor" label visible',
        'led_lights'         => 'LED strip lights or LED bulbs built into item',
        'speaker_grille'     => 'Speaker grille or mesh (indicates audio electronics)',
        'heating_element'    => 'Electric heating element, coil, or ceramic hob surface',
    ];

    // Observable non-electrical indicators — things that suggest combustion/manual power.
    public const NON_ELECTRICAL_FEATURES = [
        'gas_burner_grates'  => 'Gas ring burner grates visible on hob',
        'gas_control_knobs'  => 'Knobs with flame symbols or described as gas controls',
        'fuel_filler_cap'    => 'Fuel filler cap (petrol/diesel engine)',
        'pull_cord_starter'  => 'Pull-cord starter rope visible',
        'exhaust_pipe'       => 'Exhaust pipe visible',
        'manual_mechanism'   => 'Pedals, hand cranks, or purely mechanical linkage with no electronics',
        'hand_pump'          => 'Manual hand pump mechanism',
    ];

    public function handle(): int
    {
        $itemsOpt = $this->option('items');
        $sample   = max(1, (int) $this->option('sample'));

        if (!$itemsOpt) {
            $this->error('Specify --items="Item Name,Another Item"');
            return Command::FAILURE;
        }

        $itemNames = array_map('trim', explode(',', $itemsOpt));

        $this->info("EEE feature-detection experiment | items: " . implode(', ', $itemNames));
        $this->info("Approach: model lists observable features → rules derive EEE status");
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
            ->orderByDesc('m.arrival')
            ->limit($sample)
            ->select(['ma.externaluid', 'm.subject', 'm.textbody'])
            ->get();

        if ($attachments->isEmpty()) {
            $this->warn("  No images found.");
            return;
        }

        // Fetch images concurrently.
        $urls      = $attachments->map(fn($a) => EeeVisionService::buildImageUrl($a->externaluid))->all();
        $imageData = $this->fetchImages($urls);

        // Run feature-detection calls concurrently.
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

        $results = $this->runFeatureDetection($jobs);

        // Aggregate across images for this item type.
        $allElectrical    = [];
        $allNonElectrical = [];
        $allTextSignals   = [];

        foreach ($results as $idx => $r) {
            if ($r === null) continue;

            $electrical    = $r['electrical_features_observed']    ?? [];
            $nonElectrical = $r['non_electrical_features_observed'] ?? [];
            $textSignals   = $r['text_power_signals']              ?? [];

            foreach ($electrical    as $f) $allElectrical[$f]    = ($allElectrical[$f]    ?? 0) + 1;
            foreach ($nonElectrical as $f) $allNonElectrical[$f] = ($allNonElectrical[$f] ?? 0) + 1;
            foreach ($textSignals   as $f) $allTextSignals[$f]   = ($allTextSignals[$f]   ?? 0) + 1;

            $imageLabel = "Image " . ($idx + 1) . " (\"" . substr($jobs[$idx]['subject'], 0, 40) . "\")";
            $this->line("  {$imageLabel}");
            $this->line("    Electrical features:     " . (empty($electrical)    ? '(none)' : implode(', ', $electrical)));
            $this->line("    Non-electrical features: " . (empty($nonElectrical) ? '(none)' : implode(', ', $nonElectrical)));
            $this->line("    Text power signals:      " . (empty($textSignals)   ? '(none)' : implode(', ', $textSignals)));
            $this->line("    Other notes:             " . ($r['feature_notes'] ?? '(none)'));

            // Show derived status for this image.
            $derived = $this->deriveEeeStatus($electrical, $nonElectrical, $textSignals);
            $eeeIcon = $derived['is_eee'] ? '<info>EEE</info>' : ($derived['is_eee'] === false ? '<fg=gray>non-EEE</fg=gray>' : '<comment>?</comment>');
            $this->line("    Derived: {$eeeIcon} | contains_eee_components=" . ($derived['contains_eee'] ? 'yes' : 'no') . " | basis={$derived['basis']}");
        }

        // Aggregate summary.
        $this->newLine();
        $this->line("  <comment>Aggregate across " . count($results) . " images:</comment>");
        if (!empty($allElectrical)) {
            arsort($allElectrical);
            $this->line("    Electrical (count of images where seen):");
            foreach ($allElectrical as $f => $cnt) {
                $label = self::ELECTRICAL_FEATURES[$f] ?? $f;
                $this->line("      {$f} ({$cnt}x): {$label}");
            }
        }
        if (!empty($allNonElectrical)) {
            arsort($allNonElectrical);
            $this->line("    Non-electrical:");
            foreach ($allNonElectrical as $f => $cnt) {
                $label = self::NON_ELECTRICAL_FEATURES[$f] ?? $f;
                $this->line("      {$f} ({$cnt}x): {$label}");
            }
        }
        if (!empty($allTextSignals)) {
            $this->line("    Text signals: " . implode(', ', array_keys($allTextSignals)));
        }
    }

    protected function runFeatureDetection(array $jobs): array
    {
        $electricalList    = implode("\n", array_map(fn($k, $v) => "  - {$k}: {$v}", array_keys(self::ELECTRICAL_FEATURES),    self::ELECTRICAL_FEATURES));
        $nonElectricalList = implode("\n", array_map(fn($k, $v) => "  - {$k}: {$v}", array_keys(self::NON_ELECTRICAL_FEATURES), self::NON_ELECTRICAL_FEATURES));

        $system = <<<PROMPT
You are examining a photo of a second-hand item. Your task is to list ONLY what you can directly observe or what is explicitly stated — do not infer or classify overall.

Step 1 — Electrical features: List which of the following you can directly see in the photo:
{$electricalList}

Step 2 — Non-electrical features: List which of the following you can directly see:
{$nonElectricalList}

Step 3 — Text signals: From the item title and description (if provided), list any words or phrases that explicitly name the power source or electrical status (e.g. "gas", "electric", "petrol", "battery", "USB", "manual", "solar", "cordless", "wind-up", "no electricity needed").

Step 4 — Other attributes: Extract what you can observe about the item's physical attributes.

Return ONLY valid JSON:
{
  "electrical_features_observed": ["mains_cable", "display_screen"],
  "non_electrical_features_observed": ["gas_burner_grates"],
  "text_power_signals": ["gas cooker"],
  "feature_notes": "brief note on anything visually ambiguous or notable",
  "photo_quality": 1-5,
  "photo_quality_notes": "notes or null",
  "primary_item": "main item name",
  "brand": "brand or null",
  "brand_confidence": 0.0-1.0,
  "model_number": "model code if legible or null",
  "model_number_confidence": 0.0-1.0,
  "material_primary": "dominant material",
  "material_secondary": "secondary material or null",
  "weight_kg_min": float or null,
  "weight_kg_max": float or null,
  "size_cm": {"w": float, "h": float, "d": float} or null,
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
                $userText = 'Identify the features of this item.';
                if (!empty($job['subject']))  $userText .= "\nTitle: "       . $job['subject'];
                if (!empty($job['desc']))     $userText .= "\nDescription: " . $job['desc'];

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
            $text  = trim($response->json('content.0.text', ''));
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

    /**
     * Derive EEE status from observed features using deterministic rules.
     * Returns: is_eee (true/false/null=uncertain), contains_eee (bool), basis (string).
     */
    protected function deriveEeeStatus(array $electrical, array $nonElectrical, array $textSignals): array
    {
        $hasMains     = in_array('mains_cable', $electrical)    || in_array('mains_plug', $electrical)
                     || in_array('heating_element', $electrical);
        $hasBattery   = in_array('battery_compartment', $electrical);
        $hasMotor     = in_array('motor_housing', $electrical);
        $hasDisplay   = in_array('display_screen', $electrical) || in_array('control_panel', $electrical);
        $hasLed       = in_array('led_lights', $electrical)     || in_array('solar_panel', $electrical);
        $hasCharging  = in_array('charging_port', $electrical);

        $hasGas       = in_array('gas_burner_grates', $nonElectrical) || in_array('gas_control_knobs', $nonElectrical);
        $hasPetrol    = in_array('fuel_filler_cap', $nonElectrical)   || in_array('pull_cord_starter', $nonElectrical);
        $hasManual    = in_array('manual_mechanism', $nonElectrical);

        $textGas      = !empty(array_filter($textSignals, fn($s) => str_contains(strtolower($s), 'gas')));
        $textElec     = !empty(array_filter($textSignals, fn($s) => preg_match('/electric|mains|plug|usb|battery|solar|cordless/i', $s)));
        $textPetrol   = !empty(array_filter($textSignals, fn($s) => preg_match('/petrol|diesel|combustion/i', $s)));
        $textManual   = !empty(array_filter($textSignals, fn($s) => preg_match('/manual|wind.up|no electric/i', $s)));

        $containsEee  = !empty($electrical);

        // Strictly EEE: basic function depends on electricity.
        if ($textElec || $hasMains || $hasBattery || $hasMotor) {
            // But gas overrides if it's a gas cooker with supplementary electrics.
            if (($hasGas || $textGas) && !$hasMains && !$hasBattery) {
                return ['is_eee' => false, 'contains_eee' => $containsEee, 'basis' => 'gas+supplementary-electrics'];
            }
            return ['is_eee' => true, 'contains_eee' => true, 'basis' => $hasMains ? 'mains_cable/plug/element' : ($hasBattery ? 'battery_compartment' : ($hasMotor ? 'motor_housing' : 'text_electric'))];
        }

        if ($textGas || $hasPetrol || ($textPetrol)) {
            return ['is_eee' => false, 'contains_eee' => $containsEee, 'basis' => $textGas ? 'text:gas' : 'petrol_indicators'];
        }

        if ($textManual || ($hasManual && !$containsEee)) {
            return ['is_eee' => false, 'contains_eee' => false, 'basis' => 'manual/no-electrical-features'];
        }

        // Only display/LED/charging visible — contains EEE components but basic function unclear.
        if ($hasDisplay || $hasLed || $hasCharging) {
            return ['is_eee' => null, 'contains_eee' => true, 'basis' => 'display/led/charging-only:ambiguous'];
        }

        if (empty($electrical) && empty($textSignals)) {
            return ['is_eee' => false, 'contains_eee' => false, 'basis' => 'no-electrical-features-detected'];
        }

        return ['is_eee' => null, 'contains_eee' => $containsEee, 'basis' => 'insufficient-evidence'];
    }
}
