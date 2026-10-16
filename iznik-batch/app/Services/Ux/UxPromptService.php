<?php

namespace App\Services\Ux;

use App\Support\EnvSetting;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The questions microvolunteers are asked about Freegle itself: a survey of the parts of Freegle
 * (shown four at a time, one pick and an optional second), awareness questions ("Do you know how
 * to promise an item to someone in a chat?") and free-text questions that collect problems and
 * what members value. Each question fits one phone screen, so the wording has hard limits.
 * docs/developers/reference/ux-member-surveys.md.
 *
 * Answers are a reaction gauge from members with a known status-quo bias. The survey says where
 * members feel friction; an awareness question says whether a feature has been found at all,
 * which separates "not found" from "not wanted".
 *
 * An OPEN prompt asks members what problems they have, what they value or what they would change,
 * in their own words. analyse() has a model with no tools code those words into themes. A problem
 * is a ux_problems row of kind problem, filed under one of PROBLEM_GROUPS; a value is a row of kind
 * value; a member's suggested fix is a row of kind suggestion whose parent_id is the problem it
 * answers; what fits no theme is one row of kind other. followup() then asks other members to pick
 * the biggest of the top problems, and the tick turns those picks into a weight. The members'
 * words stay here, and are blanked 90 days after they were written.
 */
class UxPromptService
{
    /** The areas the standard survey asks about: the funnels, in the words members use. */
    public const SURVEY_AREAS = [
        ['key' => 'give', 'label' => 'Offering something'],
        ['key' => 'ask', 'label' => 'Asking for something'],
        ['key' => 'reply', 'label' => 'Replying to a post'],
        ['key' => 'chat_promise', 'label' => 'Arranging a collection in chat'],
        ['key' => 'chat_outcome', 'label' => 'Marking a post taken or received'],
        ['key' => 'login', 'label' => 'Signing in or joining'],
        ['key' => 'settings', 'label' => 'Settings and email choices'],
    ];

    /**
     * An awareness question is a plain yes / no / not sure about whether Freegle can do something.
     * "Did you know" is not used: it leads, teaches, and costs nothing to agree with.
     */
    public const AWARENESS_OPTIONS = [
        ['key' => 'yes', 'label' => 'Yes'],
        ['key' => 'no', 'label' => 'No'],
        ['key' => 'not_sure', 'label' => 'Not sure'],
    ];

    /** An awareness question's link is a path on our own site, never an outside address. */
    private const LINK_PATTERN = '#^/(?!/)[A-Za-z0-9\-._~/%?=&:+]{0,198}$#D';

    /** The area survey shows this many areas at a time. */
    public const SHOW_AT_A_TIME = 4;

    /** The follow-up shows this many problems at a time. */
    public const FOLLOWUP_SHOW = 3;

    /**
     * The three open questions: one topic, neutral words, a true way out. The question is graded
     * (PlainWords) and held to the phone limits by a test; the placeholder is the hint inside the
     * box, and the way out is the first of the two buttons under it.
     */
    public const OPEN_PRESETS = [
        'problems' => [
            'question' => 'Did you have a problem the last time you used Freegle?',
            'placeholder' => 'If yes, what happened?',
            'nothing' => 'I had no problems',
        ],
        'values' => [
            'question' => 'What has worked well for you on Freegle?',
            'placeholder' => 'What made it good?',
            'nothing' => "I can't think of one",
        ],
        'improve' => [
            'question' => 'What one thing would you change about Freegle?',
            'placeholder' => 'What would that help you do?',
            'nothing' => "I can't think of one",
        ],
    ];

    /** The second way out of every open question. */
    public const DONT_UNDERSTAND = "I don't understand";

    /**
     * The groups a problem is filed under, in the order they are described to the coder. The list
     * lives here and nowhere else: it is sent to the coder with each request, and the tab shows
     * whatever names are stored. The last entry is named Other, which the routes sort last by name.
     * Renaming a group is this constant plus one UPDATE; adding or dropping one is this constant
     * plus `ux:prompt regroup`.
     */
    public const PROBLEM_GROUPS = [
        ['name' => 'Photos and posting', 'hint' => 'Putting an offer or a wanted post up: photos, the description, the postcode, reposting, how long a post lasts.'],
        ['name' => 'Collection and no-shows', 'hint' => 'Arranging and doing the handover: promising an item, times, people who do not turn up or stop replying, marking an item as taken, safety when meeting.'],
        ['name' => 'Messages and chat', 'hint' => 'Replying to a post, reading and finding chats, too many replies, chat features.'],
        ['name' => 'Email and notifications', 'hint' => 'Emails and notifications: too many, too few, late or unwanted.'],
        ['name' => 'Moderation and approval', 'hint' => 'A post waiting for volunteers to approve it, a post refused or removed by the system or by volunteers, community rules, what members are told about it.'],
        ['name' => 'Finding things', 'hint' => 'Searching and browsing for items: search results, distance, the map, alerts.'],
        ['name' => 'Account and settings', 'hint' => 'Signing up and in, passwords, joining and leaving communities, profile, privacy, settings.'],
        ['name' => 'Other', 'hint' => 'Anything that fits none of these.'],
    ];

    /** The group a problem goes in when the coder names none, or names one that is not in the list. */
    public const OTHER_GROUP = 'Other';

    /** The follow-up ranks this many problems at most, the ones most members raised. */
    public const FOLLOWUP_PROBLEMS = 8;

    /**
     * Below about this many analysed answers the next answer still often adds a theme, so the
     * themes are a list of what people said and are not ranked.
     */
    public const FOLLOWUP_MIN_ANSWERS = 100;

    /**
     * The title the coder gives to what fits no other theme. It is a residue, not a problem a
     * member can recognise, so it is stored as kind other: never ranked and never a problem.
     */
    public const CATCH_ALL_THEME = 'Other';

    /** A theme is kept only when at least this many members raised it; one voice is an anecdote. */
    public const THEME_MIN_RESPONDENTS = 2;

    /** Most checked quotes kept per theme, and the longest one. */
    public const THEME_MAX_QUOTES = 8;

    public const QUOTE_MAX_CHARS = 300;

    /** A theme raised by fewer members than this is not put in front of other members. */
    public const FOLLOWUP_MIN_MENTIONS = 3;

    /** Most answers an open prompt collects. */
    public const OPEN_MAX = 300;

    /** A problem title is one short sentence, cut at a word end to this many characters. */
    public const TITLE_MAX = 60;

    /**
     * The reading grade above which a problem title is marked for the person who checks the titles.
     * It never stops a title being filed: the formula charges for every syllable, so a short plain
     * title of long words scores high (PlainWords).
     */
    public const TITLE_MAX_GRADE = 6;

    /** What fits one phone screen: an awareness action, its reveal, and the finished question. */
    public const ACTION_MAX = 50;

    public const REVEAL_MAX = 160;

    public const QUESTION_MAX_WORDS = 14;

    public const QUESTION_MAX_CHARS = 72;

    /** The first words of a decoy's reveal, so the member is told at once that it was not real. */
    public const DECOY_LINE = 'Freegle does not do this.';

    /** Member free text (answers and comments) is kept this long, for coding, and then blanked. */
    public const KEEP_ANSWERS_DAYS = 90;

    /** One open survey at a time: opening another closes the old one. */
    public function openSurvey(int $max = 200): int
    {
        $now = Carbon::now('UTC');
        DB::table('ux_prompts')->where('kind', 'survey')->where('status', 'open')
            ->whereNull('settings->followup_of')
            ->update(['status' => 'closed', 'updated_at' => $now]);

        return DB::table('ux_prompts')->insertGetId([
            'kind' => 'survey',
            'flow' => null,
            'question' => 'Which part of Freegle works best for you?',
            'body' => '',
            'options' => json_encode(self::SURVEY_AREAS),
            'images' => null,
            'settings' => json_encode([
                'show' => self::SHOW_AT_A_TIME,
                'worst_label' => 'Which other part most needs improving? (optional)',
                'none_label' => 'None of these',
                'randomise' => true,
            ]),
            'status' => 'open',
            'max_responses' => max(10, $max),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Open an awareness question: "Do you know how to <action>?", answered yes, no or not sure.
     * It is a question, not a tip, so the usual one has no reveal. A reveal (one or two sentences,
     * and an optional link to the feature) is shown after the answer when there is one. A decoy
     * asks about something Freegle does not do, so its right answer is no; the share of members
     * who say yes to a decoy is how readily this sample says yes to anything, and is shown next to
     * the real questions so a reader can discount them. About one question in five should be a
     * decoy. A decoy must have a reveal: "Do you know how to ..." suggests the thing exists, so the
     * member has to be told afterwards that it does not. The stored reveal of a decoy starts with
     * DECOY_LINE, so the card needs no decoy check of its own.
     *
     * The action is at most ACTION_MAX characters, the reveal at most REVEAL_MAX, and the finished
     * question at most QUESTION_MAX_WORDS words and QUESTION_MAX_CHARS characters: that is what
     * one phone screen holds.
     *
     * @throws \InvalidArgumentException
     */
    public function openAwareness(string $flow, string $action, ?string $reveal = null, ?string $link = null, bool $decoy = false, int $max = 200): int
    {
        $action = trim(preg_replace('/\s+/u', ' ', $action) ?? '');
        $action = rtrim($action, " ?\t");
        $reveal = trim(preg_replace('/\s+/u', ' ', (string) $reveal) ?? '');
        if ($action === '') {
            throw new \InvalidArgumentException('An awareness prompt needs an action in plain words, such as "promise an item to someone in a chat".');
        }
        if (mb_strlen($action) > self::ACTION_MAX) {
            throw new \InvalidArgumentException('The action is '.mb_strlen($action).' characters. A phone screen fits '.self::ACTION_MAX.'.');
        }
        $question = 'Do you know how to '.$action.'?';
        $words = count(preg_split('/\s+/u', $question, -1, PREG_SPLIT_NO_EMPTY) ?: []);
        if ($words > self::QUESTION_MAX_WORDS) {
            throw new \InvalidArgumentException("The question would be $words words. Use at most ".self::QUESTION_MAX_WORDS.'.');
        }
        if (mb_strlen($question) > self::QUESTION_MAX_CHARS) {
            throw new \InvalidArgumentException('The question would be '.mb_strlen($question).' characters. Use at most '.self::QUESTION_MAX_CHARS.'.');
        }
        if (mb_strlen($reveal) > self::REVEAL_MAX) {
            throw new \InvalidArgumentException('The reveal is '.mb_strlen($reveal).' characters. A phone screen fits '.self::REVEAL_MAX.'.');
        }
        if ($decoy && $reveal === '') {
            throw new \InvalidArgumentException('A decoy needs a reveal: "Do you know how to ..." suggests Freegle does this, so the member must be told afterwards that it does not.');
        }
        if ($flow !== '' && ! preg_match('/^[A-Za-z0-9_-]{1,32}$/', $flow)) {
            throw new \InvalidArgumentException('--flow must be a short name such as give, using letters, digits, _ and -.');
        }
        $link = $link === null ? null : trim($link);
        if ($link === '') {
            $link = null;
        }
        if ($link !== null && ! preg_match(self::LINK_PATTERN, $link)) {
            throw new \InvalidArgumentException('link must be a path on this site, starting with a single /, such as /settings.');
        }
        if ($link !== null && $reveal === '') {
            // The link is shown with the reveal, so one without the other would be stored and never seen.
            throw new \InvalidArgumentException('A link is shown with the reveal after the answer, so give a reveal too or leave the link out.');
        }
        if ($decoy) {
            $reveal = self::DECOY_LINE.' '.$reveal;
        }
        $now = Carbon::now('UTC');

        return DB::table('ux_prompts')->insertGetId([
            'kind' => 'awareness',
            'flow' => $flow ?: null,
            'question' => $question,
            'body' => '',
            'options' => json_encode(self::AWARENESS_OPTIONS),
            'images' => null,
            'settings' => json_encode($reveal === '' ? ['link' => $link, 'decoy' => $decoy] : ['reveal' => $reveal, 'link' => $link, 'decoy' => $decoy]),
            'status' => 'open',
            'max_responses' => max(10, $max),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Open a free-text question: preset is problems, values or improve. Members answer in their
     * own words or pick a way out (nothing, or don't understand). One open prompt per preset at a
     * time, and no more than OPEN_MAX answers.
     */
    public function openOpen(string $preset, int $max = self::OPEN_MAX): int
    {
        $p = self::OPEN_PRESETS[$preset] ?? null;
        if ($p === null) {
            throw new \InvalidArgumentException('preset must be one of '.implode(', ', array_keys(self::OPEN_PRESETS)));
        }
        $now = Carbon::now('UTC');
        DB::table('ux_prompts')->where('kind', 'open')->where('status', 'open')
            ->where('settings->preset', $preset)
            ->update(['status' => 'closed', 'updated_at' => $now]);

        return DB::table('ux_prompts')->insertGetId([
            'kind' => 'open',
            'flow' => null,
            'question' => $p['question'],
            'body' => '',
            'options' => json_encode([['key' => 'nothing', 'label' => $p['nothing']], ['key' => 'dont_understand', 'label' => self::DONT_UNDERSTAND]]),
            'images' => null,
            'settings' => json_encode(['preset' => $preset, 'randomise' => false, 'placeholder' => $p['placeholder']]),
            'status' => 'open',
            'max_responses' => min(self::OPEN_MAX, max(10, $max)),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function close(int $id): void
    {
        $n = DB::table('ux_prompts')->where('id', $id)->update(['status' => 'closed', 'updated_at' => Carbon::now('UTC')]);
        if (! $n) {
            throw new \RuntimeException("No prompt $id");
        }
    }

    /**
     * Prompts that have collected their quota are closed, so a question is never asked more
     * than it was meant to be. Called from the hourly tick. A skip is not an answer and does not
     * count towards the quota.
     */
    public function closeFull(): int
    {
        $closed = 0;
        foreach (DB::table('ux_prompts')->where('status', 'open')->get() as $p) {
            $n = $this->answers($p->id)->count();
            if ($n >= $p->max_responses) {
                $this->close($p->id);
                $closed++;
            }
        }

        return $closed;
    }

    // ------------------------------------------------------------------ the groups

    /** The names of the groups a problem can be filed under. */
    public static function groupNames(): array
    {
        return array_column(self::PROBLEM_GROUPS, 'name');
    }

    /**
     * Cut a title at a word end to $max characters: the whole words that fit, with no comma, dash or
     * space left on the end. A title with no space in it is cut where it stands.
     */
    private static function cutTitle(string $title, int $max = self::TITLE_MAX): string
    {
        $title = trim(preg_replace('/\s+/u', ' ', $title) ?? '');
        if (mb_strlen($title) <= $max) {
            return $title;
        }
        $head = mb_substr($title, 0, $max);
        if (mb_substr($title, $max, 1) !== ' ') {
            $space = mb_strrpos($head, ' ');
            if ($space !== false) {
                $head = mb_substr($head, 0, $space);
            }
        }

        return preg_replace('/[ ,\-–—]+$/u', '', $head) ?? $head;
    }

    // ------------------------------------------------------------------ analysing open answers

    /**
     * Have the helper code an open prompt's answers into themes and file them.
     *
     * Free text goes to an AI service for this, so it is refused until the privacy gate has been
     * opened on purpose (UX_PRIVACY_CLEARED). Only the answer id and its text (scrubbed of emails,
     * phone numbers and postcodes) go out, never a user id. The helper codes them with no tools.
     * Whatever comes back is checked here: every answer id must be one that was sent, a theme with
     * fewer than THEME_MIN_RESPONDENTS members or no checkable quote is dropped, titles are cut to
     * TITLE_MAX characters at a word end, a flow that is not a real funnel becomes "not tied to one
     * funnel", and a group that is not in PROBLEM_GROUPS becomes Other.
     *
     * A problem is filed whatever its reading grade; the grade is kept in its evidence for the
     * person who checks the titles. A suggestion is filed only under a problem that was filed, so
     * one whose problem was not is dropped and counted in suggestions_dropped. A prompt analysed
     * once is refused again unless $force, which replaces its themes.
     *
     * @return array{problems: int, values: int, solutions: int, unclear: int, answers: int, suggestions_dropped: int}
     *
     * @throws \RuntimeException
     */
    public function analyse(int $promptId, bool $force = false): array
    {
        $this->requirePrivacyCleared();
        $prompt = DB::table('ux_prompts')->where('id', $promptId)->first();
        if (! $prompt || $prompt->kind !== 'open') {
            throw new \RuntimeException("No open prompt $promptId");
        }
        if (! $force && DB::table('ux_problems')->where('prompt_id', $promptId)->exists()) {
            throw new \RuntimeException("Prompt $promptId has been analysed already; use --force to replace its themes");
        }

        $answers = [];
        foreach ($this->answers($promptId)->get() as $r) {
            $text = trim((string) (json_decode($r->answer, true)['text'] ?? ''));
            if ($text !== '') {
                $answers[$r->id] = UxScrub::text($text, 1000);
            }
        }
        if (! $answers) {
            throw new \RuntimeException("Prompt $promptId has no written answers to analyse");
        }

        $preset = json_decode($prompt->settings ?? '', true)['preset'] ?? 'problems';
        $themes = $this->askHelper('ux-themes', [
            'question' => $prompt->question,
            'preset' => $preset,
            'flows' => UxRollupService::FLOWS,
            'groups' => self::PROBLEM_GROUPS,
            'answers' => array_map(fn ($id, $t) => ['id' => $id, 'text' => $t], array_keys($answers), array_values($answers)),
        ]);
        if (! isset($themes['problems']) || ! is_array($themes['problems'])) {
            throw new \RuntimeException('UX themes helper answered with no problems list');
        }

        return $this->fileThemes($promptId, $themes, $answers, $force);
    }

    /**
     * Ask the coder again which group each problem of an analysed prompt belongs to, after the
     * list of groups has changed. Only the group list and each problem's id, title and description
     * go out: no answer, no quote, no user id. Only group_name changes, and nothing is asked again
     * of the members. The same privacy gate and secret apply as for analyse().
     *
     * @return array{problems: int, changed: int}
     *
     * @throws \RuntimeException
     */
    public function regroup(int $promptId): array
    {
        $this->requirePrivacyCleared();
        $prompt = DB::table('ux_prompts')->where('id', $promptId)->first();
        if (! $prompt || $prompt->kind !== 'open') {
            throw new \RuntimeException("No open prompt $promptId");
        }
        $problems = DB::table('ux_problems')->where('prompt_id', $promptId)->where('kind', 'problem')
            ->orderBy('id')->get(['id', 'title', 'description', 'group_name']);
        if ($problems->isEmpty()) {
            throw new \RuntimeException("Prompt $promptId has no problems to group; run ux:prompt analyse first");
        }

        $reply = $this->askHelper('ux-groups', [
            'groups' => self::PROBLEM_GROUPS,
            'problems' => $problems->map(fn ($p) => ['id' => (int) $p->id, 'title' => $p->title, 'description' => (string) ($p->description ?? '')])->all(),
        ]);
        if (! isset($reply['assignments']) || ! is_array($reply['assignments'])) {
            throw new \RuntimeException('UX groups helper answered with no assignments');
        }

        $current = $problems->pluck('group_name', 'id')->all();
        $names = self::groupNames();
        $changed = 0;
        $now = Carbon::now('UTC');
        foreach ($reply['assignments'] as $a) {
            $id = is_array($a) ? (int) ($a['id'] ?? 0) : 0;
            if (! array_key_exists($id, $current)) {
                continue;
            }
            $group = is_string($a['group'] ?? null) && in_array($a['group'], $names, true) ? $a['group'] : self::OTHER_GROUP;
            if ($group !== $current[$id]) {
                DB::table('ux_problems')->where('id', $id)->update(['group_name' => $group, 'updated_at' => $now]);
                $changed++;
            }
        }

        return ['problems' => $problems->count(), 'changed' => $changed];
    }

    /** Free text and problem titles go to an AI service for coding, which needs the gate opened on purpose. */
    private function requirePrivacyCleared(): void
    {
        if (! EnvSetting::flag(config('freegle.ux.prompts.privacy_cleared', false))) {
            throw new \RuntimeException('Free-text answers go to an AI service for coding. Set UX_PRIVACY_CLEARED=1 only after the public privacy wording has been checked to cover that.');
        }
    }

    /**
     * One call to the helper's internal route, with the shared secret. A connection that was cut
     * (the helper restarting, a proxy resetting it) is tried once more; one that ran out the whole
     * wait is not, because the helper is stuck and a second wait would only double the time lost.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    private function askHelper(string $route, array $body): array
    {
        $cfg = (array) config('freegle.ux.prompts', []);
        $secret = (string) ($cfg['internal_secret'] ?? '');
        if ($secret === '') {
            throw new \RuntimeException('UX_INTERNAL_SECRET is not set, so the helper would refuse the request');
        }
        $timeout = (int) ($cfg['themes_timeout'] ?? 660);
        $url = rtrim((string) ($cfg['helper_url'] ?? 'http://ai-support-helper:3000'), '/').'/internal/'.$route;

        $response = null;
        for ($attempt = 1; $response === null; $attempt++) {
            $began = microtime(true);
            try {
                $response = Http::withHeaders(['X-Freegle-Internal' => '1', 'X-Freegle-Internal-Secret' => $secret])
                    ->timeout($timeout)->acceptJson()->asJson()->post($url, $body);
            } catch (ConnectionException $e) {
                if ($attempt >= 2 || microtime(true) - $began >= $timeout - 5) {
                    throw new \RuntimeException('UX helper could not be reached: '.$e->getMessage(), 0, $e);
                }
            }
        }
        if (! $response->successful()) {
            throw new \RuntimeException('UX helper answered '.$response->status().': '.mb_substr((string) $response->body(), 0, 300));
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw new \RuntimeException('UX helper answered with something that is not JSON');
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $themes
     * @param  array<int, string>  $texts  the scrubbed text of each answer that was sent, by answer id
     */
    private function fileThemes(int $promptId, array $themes, array $texts, bool $replace): array
    {
        $sentIds = array_keys($texts);
        $sent = array_flip($sentIds);
        $normalised = [];
        $ids = function ($raw) use ($sent): array {
            return array_values(array_unique(array_filter(array_map('intval', is_array($raw) ? $raw : []), fn ($i) => isset($sent[$i]))));
        };
        // The helper checks each quote against the answer it names; it is checked again here, so
        // a theme can never carry words nobody wrote. Case and runs of white space are ignored.
        $norm = fn ($t) => trim(preg_replace('/\s+/u', ' ', mb_strtolower((string) $t)) ?? '');
        $quotes = function ($raw, array $answerIds) use ($texts, &$normalised, $norm): array {
            $out = [];
            $seen = [];
            foreach (is_array($raw) ? $raw : [] as $q) {
                $aid = is_array($q) ? (int) ($q['answer_id'] ?? 0) : 0;
                $span = is_array($q) ? trim((string) ($q['span'] ?? '')) : '';
                if ($span === '' || ! in_array($aid, $answerIds, true) || isset($seen[$aid.'|'.$span])) {
                    continue;
                }
                $normalised[$aid] ??= $norm($texts[$aid]);
                $needle = $norm($span);
                if ($needle === '' || ! str_contains($normalised[$aid], $needle)) {
                    continue;
                }
                $seen[$aid.'|'.$span] = true;
                $out[] = ['answer_id' => $aid, 'span' => mb_substr($span, 0, self::QUOTE_MAX_CHARS)];
                if (count($out) >= self::THEME_MAX_QUOTES) {
                    break;
                }
            }

            return $out;
        };
        $names = self::groupNames();
        $now = Carbon::now('UTC');
        $counts = ['problems' => 0, 'values' => 0, 'solutions' => 0, 'unclear' => count($ids($themes['unclear_ids'] ?? [])), 'answers' => count($sentIds), 'suggestions_dropped' => 0];

        DB::transaction(function () use ($promptId, $themes, $ids, $quotes, $names, $now, $replace, &$counts) {
            // Every row of the prompt goes, its suggestions and its leftover row with it.
            if ($replace) {
                DB::table('ux_problems')->where('prompt_id', $promptId)->delete();
            }

            // A theme counts respondents, not statements: its distinct answer ids. It is kept when
            // at least THEME_MIN_RESPONDENTS members raised it and at least one quote is verified.
            $theme = function ($t) use ($ids, $quotes): ?array {
                if (! is_array($t)) {
                    return null;
                }
                $answerIds = $ids($t['answer_ids'] ?? []);
                $name = self::cutTitle((string) ($t['title'] ?? ''));
                $checked = $quotes($t['quotes'] ?? [], $answerIds);
                if ($name === '' || count($answerIds) < self::THEME_MIN_RESPONDENTS || ! $checked) {
                    return null;
                }

                return ['title' => $name, 'answer_ids' => $answerIds, 'quotes' => $checked];
            };

            // The themes are drawn from exactly these answers. Answers that arrive later are not in
            // them, so the follow-up gate and the "of N who answered" base use this number and not
            // a fresh count.
            $settings = json_decode((string) DB::table('ux_prompts')->where('id', $promptId)->value('settings'), true) ?: [];
            $settings['analysed_answers'] = $counts['answers'];
            DB::table('ux_prompts')->where('id', $promptId)->update(['settings' => json_encode($settings), 'updated_at' => $now]);

            $problemIds = [];
            $problemFlows = [];
            foreach (array_values($themes['problems']) as $i => $p) {
                $t = $theme($p);
                if ($t === null) {
                    continue;
                }
                $flow = $p['flow'] ?? null;
                $flow = is_string($flow) && in_array($flow, UxRollupService::FLOWS, true) ? $flow : null;
                $evidence = ['answer_ids' => $t['answer_ids'], 'quotes' => $t['quotes']];
                $row = [
                    'flow' => $flow,
                    'title' => $t['title'],
                    'description' => mb_substr(trim((string) ($p['description'] ?? '')), 0, 1000) ?: null,
                    'prompt_id' => $promptId,
                    'mentions' => count($t['answer_ids']),
                    'status' => 'open',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if (strcasecmp($t['title'], self::CATCH_ALL_THEME) === 0) {
                    // What fits no theme: kept for its count, and never a problem or a group.
                    DB::table('ux_problems')->insert($row + ['kind' => 'other', 'group_name' => null, 'evidence' => json_encode($evidence)]);

                    continue;
                }
                // The grade is advice for the person who checks the titles; it never drops a problem.
                $evidence['grade'] = round(PlainWords::grade($t['title']), 2);
                $group = is_string($p['group'] ?? null) && in_array($p['group'], $names, true) ? $p['group'] : self::OTHER_GROUP;
                $problemIds[$i] = DB::table('ux_problems')->insertGetId($row + ['kind' => 'problem', 'group_name' => $group, 'evidence' => json_encode($evidence)]);
                $problemFlows[$i] = $flow;
                $counts['problems']++;
            }

            foreach ($themes['values'] ?? [] as $v) {
                $t = $theme($v);
                if ($t === null) {
                    continue;
                }
                DB::table('ux_problems')->insert([
                    'flow' => null, 'kind' => 'value', 'group_name' => null, 'title' => $t['title'], 'prompt_id' => $promptId,
                    'mentions' => count($t['answer_ids']), 'evidence' => json_encode(['answer_ids' => $t['answer_ids'], 'quotes' => $t['quotes']]),
                    'status' => 'open', 'created_at' => $now, 'updated_at' => $now,
                ]);
                $counts['values']++;
            }

            // A member's suggested fix sits under the problem it answers, so one member is enough
            // and it needs no quote. One whose problem was not filed has no parent to sit under and
            // would be listed nowhere, so it is not stored; it is counted instead.
            foreach ($themes['solutions'] ?? [] as $sol) {
                $answerIds = is_array($sol) ? $ids($sol['answer_ids'] ?? []) : [];
                $title = is_array($sol) ? self::cutTitle((string) ($sol['title'] ?? '')) : '';
                if ($title === '' || ! $answerIds) {
                    continue;
                }
                $index = (int) ($sol['problem_index'] ?? -1);
                if (! isset($problemIds[$index])) {
                    $counts['suggestions_dropped']++;

                    continue;
                }
                DB::table('ux_problems')->insert([
                    'flow' => $problemFlows[$index], 'kind' => 'suggestion', 'group_name' => null, 'title' => $title,
                    'prompt_id' => $promptId, 'parent_id' => $problemIds[$index],
                    'mentions' => count($answerIds),
                    'evidence' => json_encode(['answer_ids' => $answerIds, 'quotes' => $quotes($sol['quotes'] ?? [], $answerIds)]),
                    'status' => 'open', 'created_at' => $now, 'updated_at' => $now,
                ]);
                $counts['solutions']++;
            }
        });

        return $counts;
    }

    // ------------------------------------------------------------------ the follow-up ranking

    /**
     * Open a "which is the biggest problem for you" survey over the problems most members raised in an
     * open prompt's answers, shown three at a time, one pick. The options are keyed problem:<id>,
     * which is how the tick finds them to weigh the problems. One follow-up per analysed prompt at
     * a time; an older one for the same prompt is closed.
     *
     * Refused below FOLLOWUP_MIN_ANSWERS analysed answers: before that the next answer still often
     * adds a theme, so ranking would rank an unfinished list. The count is the one stored when
     * the prompt was analysed, because the themes come from those answers; answers that arrived
     * since do not count until it is analysed again.
     *
     * This is the one place member words reach other members: a problem's title is written by the
     * coder from what members said, and names are not scrubbed from it. So only themes several
     * members raised are used (a single member's complaint about a named person never gets here),
     * and the titles are listed first for the person running the command, who checks two things:
     * that none names a person, a place or an account, and that each is one short sentence in plain
     * words. A title that fails either is left out with $skip. It stays open, and stays on the tab;
     * it is only not asked about. Nothing is pulled in to take its place, because the person
     * checked the titles that were listed and no others.
     *
     * @param  int[]  $skip  ids of listed problems to leave out
     *
     * @throws \RuntimeException when there is nothing to rank, or the titles have not been confirmed
     */
    public function followup(int $promptId, int $max = 200, bool $confirmed = false, array $skip = []): int
    {
        $settings = json_decode((string) DB::table('ux_prompts')->where('id', $promptId)->value('settings'), true) ?: [];
        $written = (int) ($settings['analysed_answers'] ?? 0);
        if ($written < self::FOLLOWUP_MIN_ANSWERS) {
            throw new \RuntimeException("Prompt $promptId was analysed over $written written answers. Below about ".self::FOLLOWUP_MIN_ANSWERS
                .' answers the next answer still often adds a theme, so the problems are not ranked yet; wait for more answers and analyse again (with --force).');
        }
        $listed = DB::table('ux_problems')->where('prompt_id', $promptId)->where('kind', 'problem')->where('status', 'open')
            ->where('mentions', '>=', self::FOLLOWUP_MIN_MENTIONS)
            ->orderByDesc('mentions')->orderBy('id')->limit(self::FOLLOWUP_PROBLEMS)->get();
        if ($listed->count() < 2) {
            throw new \RuntimeException("Prompt $promptId has fewer than two problems raised by at least ".self::FOLLOWUP_MIN_MENTIONS.' members to rank; run ux:prompt analyse first');
        }
        $skip = array_values(array_unique(array_map('intval', $skip)));
        foreach ($skip as $id) {
            if (! $listed->contains('id', $id)) {
                throw new \RuntimeException("Problem $id is not one of the titles listed.");
            }
        }
        $problems = $listed->reject(fn ($p) => in_array((int) $p->id, $skip, true))->values();
        if ($problems->count() < 2) {
            throw new \RuntimeException("Prompt $promptId would have fewer than two problems left to rank after --skip.");
        }
        if (! $confirmed) {
            $mark = fn (float $grade) => $grade > self::TITLE_MAX_GRADE ? ' *' : '';
            throw new \RuntimeException("These titles would be shown to up to $max other members. Check two things, then run again with --confirm:\n"
                ."  1. None names a person, a place or an account.\n"
                ."  2. Each is one short sentence in plain words with no idiom (\"chat\", not \"conversation\"). Look at the ones marked * first: they read above grade ".self::TITLE_MAX_GRADE.".\n"
                ."A title that fails a check is left out with --skip=<ids>, for example --skip=12,15. It stays open and stays on the tab; it is only not asked about now.\n"
                .$problems->map(function ($p) use ($written, $mark) {
                    $grade = self::gradeOf($p);

                    return "  [{$p->id}] ({$p->mentions} of $written who answered) grade ".number_format($grade, 1, '.', '').$mark($grade)." {$p->title}";
                })->implode("\n"));
        }
        $now = Carbon::now('UTC');
        DB::table('ux_prompts')->where('kind', 'survey')->where('status', 'open')
            ->where('settings->followup_of', $promptId)
            ->update(['status' => 'closed', 'updated_at' => $now]);

        return DB::table('ux_prompts')->insertGetId([
            'kind' => 'survey',
            'flow' => null,
            'question' => 'Which of these has been your biggest problem in the last 3 months?',
            'body' => '',
            'options' => json_encode($problems->map(fn ($p) => ['key' => 'problem:'.$p->id, 'label' => $p->title])->all()),
            'images' => null,
            'settings' => json_encode([
                'show' => self::FOLLOWUP_SHOW,
                'none_label' => 'None of these',
                'followup_of' => $promptId,
            ]),
            'status' => 'open',
            'max_responses' => max(10, $max),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * A problem's reading grade: the one kept when it was filed, else worked out from its title and
     * rounded the way filing rounds it, so a title reads the same grade whichever way it is found.
     */
    private static function gradeOf(object $problem): float
    {
        $stored = json_decode((string) ($problem->evidence ?? ''), true)['grade'] ?? null;

        return is_numeric($stored) ? (float) $stored : round(PlainWords::grade((string) $problem->title), 2);
    }

    /**
     * Turn the follow-up picks into a weight on each problem: times picked as the biggest problem /
     * times shown. Only three of the options are in front of a member at once, so the divisor is
     * how often the option was actually shown, from the `shown` list each answer carries; an
     * answer from before `shown` existed saw every option. An answer is a pick or "none of these";
     * a skip or "I don't understand" is not one. The evidence keeps most and times_shown beside
     * the weight, so a reader can see how much it rests on. Called from the tick, so the weights
     * follow the answers as they come in. Returns how many problems were updated; one never shown
     * is not.
     */
    public function weighFollowups(): int
    {
        $updated = 0;
        $now = Carbon::now('UTC');
        foreach (DB::table('ux_prompts')->where('kind', 'survey')->whereNotNull('settings->followup_of')->get() as $prompt) {
            $keys = array_column(json_decode($prompt->options, true) ?: [], 'key');
            $most = [];
            $shownCount = [];
            foreach ($this->answers($prompt->id)->get() as $r) {
                $a = json_decode($r->answer, true) ?: [];
                $picked = isset($a['best']) || ($a['choice'] ?? null) === 'none';
                if (! $picked) {
                    continue;
                }
                $shown = isset($a['shown']) && is_array($a['shown']) ? array_values(array_unique($a['shown'])) : $keys;
                foreach ($shown as $k) {
                    $shownCount[$k] = ($shownCount[$k] ?? 0) + 1;
                }
                if (isset($a['best'])) {
                    $most[$a['best']] = ($most[$a['best']] ?? 0) + 1;
                }
            }
            foreach ($keys as $key) {
                if (! preg_match('/^problem:(\d+)$/', (string) $key, $m) || empty($shownCount[$key])) {
                    continue;
                }
                $id = (int) $m[1];
                $mostN = $most[$key] ?? 0;
                $evidence = json_decode((string) DB::table('ux_problems')->where('id', $id)->value('evidence'), true) ?: [];
                $evidence['ranking'] = ['prompt_id' => (int) $prompt->id, 'most' => $mostN, 'times_shown' => $shownCount[$key]];
                $updated += DB::table('ux_problems')->where('id', $id)->update([
                    'weight' => round($mostN / $shownCount[$key], 3),
                    'evidence' => json_encode($evidence),
                    'updated_at' => $now,
                ]);
            }
        }

        return $updated;
    }

    // ------------------------------------------------------------------ privacy

    /**
     * Blank member words older than $days, whatever the prompt's state: the free text of an open
     * question's answer, and the comment on any kind of answer (they are shown in ModTools and can
     * carry names the same way). Counts and picks stay. The clock is each answer's own timestamp,
     * so a prompt that never closes (an open question that did not fill) is blanked too, and a
     * later touch of the prompt row does not restart it. Returns how many answers were changed.
     */
    public function blankOldAnswers(?Carbon $now = null, int $days = self::KEEP_ANSWERS_DAYS): int
    {
        $now = $now ?: Carbon::now('UTC');
        $blanked = 0;
        DB::table('ux_prompt_responses')->where('timestamp', '<', $now->copy()->subDays($days))
            // On a JSON path the builder's not-null test also skips a JSON null, so text already
            // blanked to null is not blanked again.
            ->where(fn ($q) => $q->whereNotNull('comment')->orWhereNotNull('answer->text'))
            ->orderBy('id')->chunkById(500, function ($rows) use (&$blanked) {
                foreach ($rows as $r) {
                    $update = ['comment' => null];
                    $a = json_decode($r->answer, true) ?: [];
                    if (array_key_exists('text', $a) && $a['text'] !== null) {
                        $update['answer'] = json_encode(['text' => null, 'removed' => true]);
                    }
                    DB::table('ux_prompt_responses')->where('id', $r->id)->update($update);
                    $blanked++;
                }
            });

        return $blanked;
    }

    /**
     * Drop the verbatim spans of an old answer from the themes they were filed under. The themes
     * keep a title, a count and the answer ids; the words themselves live only as long as the
     * answers do. A span goes when its answer is older than $days or no longer exists (a member
     * forgotten, an account merged away). Returns how many theme rows were changed.
     */
    public function blankOldQuotes(?Carbon $now = null, int $days = self::KEEP_ANSWERS_DAYS): int
    {
        $cutoff = ($now ?: Carbon::now('UTC'))->copy()->subDays($days);
        $changed = 0;
        DB::table('ux_problems')->where('evidence', 'like', '%"span"%')->orderBy('id')
            ->chunkById(200, function ($rows) use ($cutoff, &$changed) {
                foreach ($rows as $row) {
                    $evidence = json_decode($row->evidence, true) ?: [];
                    $quotes = $evidence['quotes'] ?? [];
                    if (! $quotes) {
                        continue;
                    }
                    $live = DB::table('ux_prompt_responses')->whereIn('id', array_unique(array_column($quotes, 'answer_id')))
                        ->where('timestamp', '>=', $cutoff)->pluck('id')->all();
                    $kept = array_values(array_filter($quotes, fn ($q) => in_array($q['answer_id'] ?? null, $live)));
                    if (count($kept) !== count($quotes)) {
                        $evidence['quotes'] = $kept;
                        DB::table('ux_problems')->where('id', $row->id)->update(['evidence' => json_encode($evidence)]);
                        $changed++;
                    }
                }
            });

        return $changed;
    }

    /**
     * Take a member's answers out of the themes they fed, the suggestions among them: their spans
     * and their ids. Called before their answers are deleted (forget me), so the words do not
     * outlive the answers. Counts (`mentions`) stay: a count is not the member's words.
     *
     * @param  int[]  $answerIds
     */
    public static function forgetAnswers(array $answerIds): void
    {
        if (! $answerIds) {
            return;
        }
        $gone = fn ($id) => in_array($id, $answerIds);
        $prompts = DB::table('ux_prompt_responses')->whereIn('id', $answerIds)->pluck('prompt_id')->unique()->all();
        foreach (DB::table('ux_problems')->whereIn('prompt_id', $prompts)->get(['id', 'evidence']) as $row) {
            $evidence = json_decode($row->evidence ?? '', true);
            if (! is_array($evidence)) {
                continue;
            }
            $before = json_encode($evidence);
            if (isset($evidence['answer_ids'])) {
                $evidence['answer_ids'] = array_values(array_filter($evidence['answer_ids'], fn ($id) => ! $gone($id)));
            }
            if (isset($evidence['quotes'])) {
                $evidence['quotes'] = array_values(array_filter($evidence['quotes'], fn ($q) => ! $gone($q['answer_id'] ?? null)));
            }
            if (json_encode($evidence) !== $before) {
                DB::table('ux_problems')->where('id', $row->id)->update(['evidence' => json_encode($evidence)]);
            }
        }
    }

    // ------------------------------------------------------------------ helpers

    /** The answers that count: a skip is a record that the prompt was seen, not an answer. */
    private function answers(int $promptId)
    {
        return DB::table('ux_prompt_responses')->where('prompt_id', $promptId)
            ->whereNull('answer->skipped');
    }
}
