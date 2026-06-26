<?php

namespace App\Console\Commands\Eee;

use App\Services\EeeVisionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Cheap experiment to validate the LLM-as-judge reconciliation approach.
 *
 * Uses synthetic rationale pairs with known correct answers — no image API calls needed.
 * Tests whether a text-only judge call can reliably pick the better-quality reasoning
 * when text_only and image_only signals disagree.
 *
 * Run: php artisan eee:judge-experiment
 */
class EeeJudgeExperimentCommand extends Command
{
    protected $signature = 'eee:judge-experiment
                            {--show-reasoning : Show full judge reasoning for each case}';

    protected $description = 'Test LLM-as-judge accuracy on synthetic rationale pairs (cheap, no image calls)';

    // Each case: item, text verdict+reasoning, image verdict+reasoning, correct answer, notes.
    protected const CASES = [
        [
            'item'             => 'Gas Cooker',
            'text_is_eee'      => false,
            'text_reasoning'   => 'The title explicitly states "Gas Cooker". Under WEEE regulations, a gas cooker\'s primary function (cooking) operates via gas combustion, not electricity. Any electrical components (electronic ignition, clock, extraction fan) are supplementary features. This item is not dependent on electric current to work properly.',
            'image_is_eee'     => true,
            'image_reasoning'  => 'The image shows a freestanding white cooker with four circular elements on the hob and an oven below. The appearance is consistent with a standard electric range cooker. The circular heating zones on the hob are characteristic of electric cooking appliances.',
            'correct'          => 'text',
            'note'             => 'Image infers from visual similarity; text has explicit power-source name',
        ],
        [
            'item'             => 'Exercise Bike',
            'text_is_eee'      => false,
            'text_reasoning'   => 'The title "Exercise Bike" refers to a stationary bicycle. Most exercise bikes are mechanical devices powered entirely by the rider\'s pedalling — no electricity is required for the basic function of resistance cycling. Electricity is not required to work properly.',
            'image_is_eee'     => true,
            'image_reasoning'  => 'The image shows a stationary exercise bike with a digital display panel mounted on the handlebars showing speed, distance, and heart rate. Electronic display units require electrical power. The item has visible control buttons and a monitor screen.',
            'correct'          => 'text',
            'note'             => 'Passive display powered by pedalling is not EEE; text correctly defaults to mechanical',
        ],
        [
            'item'             => 'Chainsaw (with visible power cord in photo)',
            'text_is_eee'      => false,
            'text_reasoning'   => 'The title says "Chainsaw". Chainsaws are commonly petrol-powered, which would not qualify as EEE under WEEE regulations. Without explicit mention of "electric" or "cordless" in the title, this is ambiguous. Defaulting to the most common case: petrol-powered, non-EEE.',
            'image_is_eee'     => true,
            'image_reasoning'  => 'The image clearly shows an electric chainsaw with an orange mains power cable attached to the body of the saw near the trigger. The saw has no visible fuel tank or pull-cord starter. This is definitively a mains-powered electric tool that cannot operate without electricity.',
            'correct'          => 'image',
            'note'             => 'Image has direct visual evidence (power cord); text has no information and defaults incorrectly',
        ],
        [
            'item'             => 'Washing Machine',
            'text_is_eee'      => true,
            'text_reasoning'   => 'The title "Washing Machine" refers to a home appliance that requires electricity to power its motor, drum rotation, heating element, water pump, and control electronics. A washing machine cannot perform its basic function (washing clothes) without electrical power.',
            'image_is_eee'     => true,
            'image_reasoning'  => 'The image shows a front-loading washing machine with a circular porthole door, control dial, and display panel. Washing machines are large electrical appliances that require mains power to operate the motor, heating, and drum. Clearly EEE Category 4.',
            'correct'          => 'both',
            'note'             => 'Agreement case — both signals correct',
        ],
        [
            'item'             => 'Digital Piano',
            'text_is_eee'      => true,
            'text_reasoning'   => 'The title explicitly says "Digital Piano". A digital piano produces sound electronically through digital samples and requires electrical power for all its functions — it produces no sound at all without electricity. This is EEE (Category 6: Small IT and telecom, or Category 5: Small equipment).',
            'image_is_eee'     => false,
            'image_reasoning'  => 'The image shows a large upright piano with a wooden case and a traditional keyboard. The instrument has the appearance of an acoustic upright piano with hammers and strings visible. Acoustic pianos produce sound mechanically and do not require electricity.',
            'correct'          => 'text',
            'note'             => 'Image misidentifies a digital piano as acoustic from appearance; title is authoritative',
        ],
        [
            'item'             => 'Wheelchair',
            'text_is_eee'      => false,
            'text_reasoning'   => 'The title "Wheelchair" is ambiguous — it could be manual or powered. The post description says "lightweight manual wheelchair, foldable, never used". A manual wheelchair does not require electricity in any way to function. Non-EEE.',
            'image_is_eee'     => false,
            'image_reasoning'  => 'The image shows a standard manual wheelchair with push handles, large rear wheels with hand-rims, and a folding frame. There are no visible motor units, battery packs, or control electronics. This is a purely mechanical device.',
            'correct'          => 'both',
            'note'             => 'Agreement case — both signals correct; description provides extra clarity',
        ],
        [
            'item'             => 'Sofa',
            'text_is_eee'      => true,
            'text_reasoning'   => 'The post description states "3-seater recliner sofa with built-in USB charging ports and LED mood lighting under the base, requires mains power for the recliner motor." This sofa has a powered recliner mechanism and integrated charging — it depends on electricity for these functions.',
            'image_is_eee'     => false,
            'image_reasoning'  => 'The image shows a large three-seater upholstered sofa in grey fabric. Standard sofas are pieces of furniture that require no electricity. The item appears to be a conventional sofa.',
            'correct'          => 'text',
            'note'             => 'Description explicitly mentions powered recliner and USB charging; image cannot detect internal mechanisms',
        ],
        [
            'item'             => 'Fish Tank',
            'text_is_eee'      => false,
            'text_reasoning'   => 'The title "Fish Tank" refers to a glass aquarium container. The tank itself is a glass/acrylic vessel and does not require electricity to function as a container. Non-EEE.',
            'image_is_eee'     => true,
            'image_reasoning'  => 'The image shows a complete aquarium setup including a glass tank with a filter unit attached to the side, a submersible heater visible inside, and LED lighting mounted in the lid. The filter, heater, and lights are all electrical components that are included with this item.',
            'correct'          => 'ambiguous',
            'note'             => 'Genuinely contested: tank body is non-EEE but bundled accessories are EEE. Tests whether judge acknowledges ambiguity.',
        ],
        [
            'item'             => 'Petrol Lawnmower',
            'text_is_eee'      => false,
            'text_reasoning'   => 'The title explicitly states "Petrol Lawnmower". A petrol lawnmower is powered by a combustion engine running on petrol, not by electricity. Electronic ignition or blade-brake components, if present, are supplementary. The item is not dependent on electric current to work properly.',
            'image_is_eee'     => false,
            'image_reasoning'  => 'The image shows a red lawnmower with a large grass collection bag, pull-cord starter, and visible fuel filler cap on the engine housing. No power cable or battery compartment is visible. This is consistent with a petrol-powered garden tool.',
            'correct'          => 'both',
            'note'             => 'Agreement case — both correct; image provides corroborating visual evidence (fuel cap, pull-cord)',
        ],
        [
            'item'             => 'Electric Cooker',
            'text_is_eee'      => true,
            'text_reasoning'   => 'The title explicitly states "Electric Cooker". An electric cooker is entirely dependent on mains electricity to power its heating elements, oven, and controls. Without electricity it cannot function at all. EEE Category 4.',
            'image_is_eee'     => true,
            'image_reasoning'  => 'The image shows a freestanding cooker with smooth ceramic hob elements on the top surface and a large oven below. The hob has flat glass-ceramic surface zones consistent with electric radiant or induction heating. EEE Category 4.',
            'correct'          => 'both',
            'note'             => 'Agreement case — both correct',
        ],
    ];

    public function handle(): int
    {
        if (!config('freegle.eee.anthropic_api_key')) {
            $this->error('Anthropic API key not configured (EEE_ANTHROPIC_API_KEY).');
            return Command::FAILURE;
        }

        $this->info('EEE judge experiment — testing LLM-as-judge on synthetic rationale pairs');
        $this->info('Each case: text_only and image_only produce separate reasoning strings. Judge picks which is more epistemically reliable.');
        $this->newLine();

        $correct = 0;
        $total   = 0;
        $rows    = [];

        foreach (self::CASES as $case) {
            $result = $this->runJudge($case);
            $total++;

            $expected = $case['correct'];
            $verdict  = $result['dominant_signal'] ?? 'unknown';

            $match = match ($expected) {
                'both'      => in_array($verdict, ['both', 'text', 'image']), // agreement cases: any verdict acceptable
                'ambiguous' => true,                                           // no single right answer
                default     => $verdict === $expected,
            };

            if ($expected !== 'ambiguous') {
                $correct += $match ? 1 : 0;
            }

            $icon = $expected === 'ambiguous' ? '?' : ($match ? '✓' : '✗');
            $rows[] = [
                $icon,
                $case['item'],
                "text={$this->eeeLabel($case['text_is_eee'])} / image={$this->eeeLabel($case['image_is_eee'])}",
                $expected,
                $verdict,
                $match ? '<info>correct</info>' : '<fg=red>wrong</fg=red>',
            ];

            if ($this->option('show-reasoning')) {
                $this->line("  <comment>{$case['item']}</comment>");
                $this->line("  Judge reasoning: " . ($result['judge_reasoning'] ?? '(none)'));
                $this->newLine();
            }
        }

        $scorable = count(array_filter(self::CASES, fn($c) => $c['correct'] !== 'ambiguous'));
        $pct      = $scorable > 0 ? round(100 * $correct / $scorable) : 0;

        $this->table(['', 'Item', 'Signals', 'Expected', 'Judge picked', 'Result'], $rows);

        $this->newLine();
        $this->info("Score: {$correct}/{$scorable} ({$pct}%) on unambiguous cases");
        $this->line('(Ambiguous cases not scored — they test whether the judge acknowledges uncertainty)');

        return Command::SUCCESS;
    }

    protected function runJudge(array $case): array
    {
        $textVerdict  = $case['text_is_eee']  ? 'EEE'     : 'non-EEE';
        $imageVerdict = $case['image_is_eee'] ? 'EEE'     : 'non-EEE';
        $agree        = $case['text_is_eee'] === $case['image_is_eee'];

        $prompt = <<<PROMPT
You are arbitrating between two independent EEE classification assessments of the same item.

Item: {$case['item']}

Assessment A (based on item title and post description only — no image):
Verdict: {$textVerdict}
Reasoning: {$case['text_reasoning']}

Assessment B (based on the photo only — item title and description were not provided):
Verdict: {$imageVerdict}
Reasoning: {$case['image_reasoning']}

Your task: evaluate which assessment is more epistemically reliable for this specific item.
Consider:
- Does one reasoning cite DIRECT evidence (explicit power-source name in the title, directly
  observed physical feature like a power cord or gas valve) rather than inferring from
  general appearance or category base rates?
- Does one reasoning correctly apply the WEEE basic-function test, or does it conflate
  visual similarity with EEE status?
- If both assessments agree, note that — the correct answer is clear.
- If the evidence is genuinely ambiguous and neither has a strong evidential advantage, say so.

Return ONLY valid JSON:
{
  "signals_agree": true/false,
  "dominant_signal": "text" or "image" or "both" or "ambiguous",
  "judge_reasoning": "one or two sentences explaining which evidence is more direct and why",
  "confidence": 0.0-1.0
}
PROMPT;

        try {
            $response = Http::timeout(30)
                ->withHeaders([
                    'x-api-key'         => config('freegle.eee.anthropic_api_key'),
                    'anthropic-version' => '2023-06-01',
                    'content-type'      => 'application/json',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model'      => config('freegle.eee.claude_model', 'claude-sonnet-4-6'),
                    'max_tokens' => 256,
                    'messages'   => [['role' => 'user', 'content' => $prompt]],
                ]);

            if (!$response->successful()) {
                $this->warn("Judge call failed for '{$case['item']}': " . $response->status());
                return ['dominant_signal' => 'error', 'judge_reasoning' => null];
            }

            $text = trim($response->json('content.0.text', ''));
            // Strip markdown fences if present.
            if (preg_match('/```(?:json)?\s*(.*?)\s*```/s', $text, $m)) $text = $m[1];
            $start = strpos($text, '{');
            $end   = strrpos($text, '}');
            if ($start !== false && $end !== false) {
                $text = substr($text, $start, $end - $start + 1);
            }

            return json_decode($text, true) ?? ['dominant_signal' => 'parse_error', 'judge_reasoning' => $text];
        } catch (\Exception $e) {
            $this->warn("Exception for '{$case['item']}': " . $e->getMessage());
            return ['dominant_signal' => 'error', 'judge_reasoning' => null];
        }
    }

    protected function eeeLabel(bool $isEee): string
    {
        return $isEee ? 'EEE' : 'non-EEE';
    }
}
