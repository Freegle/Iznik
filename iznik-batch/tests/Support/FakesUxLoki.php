<?php

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A fake Loki for the UX rollup tests.
 *
 * Http::fake() merges, and the first callback to answer wins (see the batch traps rule), so the
 * whole fake is ONE closure that looks the LogQL up in a rule list the test fills in. A query
 * no rule matches gets an empty, successful answer, which is what Loki says for "nothing".
 */
trait FakesUxLoki
{
    /** @var array<int, array{0: string, 1: callable|array}> */
    protected array $lokiRules = [];

    /** @var string[] Every LogQL query the code sent, decoded. */
    protected array $lokiQueries = [];

    protected bool $lokiDown = false;

    protected function fakeLoki(): void
    {
        config()->set('freegle.loki.query_url', 'http://loki:3100');
        $this->lokiRules = [];
        $this->lokiQueries = [];
        $this->lokiDown = false;

        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $logql = $q['query'] ?? '';
            $this->lokiQueries[] = $logql;

            if ($this->lokiDown) {
                return Http::response('down', 503);
            }

            foreach ($this->lokiRules as [$pattern, $answer]) {
                if (preg_match($pattern, $logql)) {
                    $result = is_callable($answer) ? $answer($q) : $answer;

                    if (is_array($result) && isset($result["__status"])) {
                        return Http::response($result["body"], $result["__status"]);
                    }

                    return Http::response($result, 200);
                }
            }

            return Http::response(['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => []]], 200);
        });
    }

    /**
     * When LogQL matches $pattern, answer with these series: [[labels, value], ...]. A value may be
     * a number (one point) or [unix seconds => number] (one point per hour, for a query that is
     * read by the hour).
     */
    protected function lokiMatrix(string $pattern, array $series): void
    {
        $result = [];
        foreach ($series as [$labels, $value]) {
            $points = [];
            if (is_array($value)) {
                foreach ($value as $ts => $v) {
                    $points[] = [$ts, (string) $v];
                }
            } else {
                $points[] = [1_700_000_000, (string) $value];
            }
            $result[] = ['metric' => $labels, 'values' => $points];
        }

        array_unshift($this->lokiRules, [$pattern, ['status' => 'success', 'data' => ['resultType' => 'matrix', 'result' => $result]]]);
    }

    /** When LogQL matches $pattern (callable $when optional), fail with this HTTP status and body. */
    protected function lokiError(string $pattern, int $status, string $body): void
    {
        array_unshift($this->lokiRules, [$pattern, ["__status" => $status, "body" => $body]]);
    }

    /** When LogQL matches $pattern, answer with these raw log lines (arrays, JSON-encoded here). */
    protected function lokiLines(string $pattern, array $lines): void
    {
        $values = [];
        foreach ($lines as $i => $line) {
            $values[] = [(string) (1_700_000_000_000_000_000 + $i), json_encode($line)];
        }

        array_unshift($this->lokiRules, [$pattern, ['status' => 'success', 'data' => ['resultType' => 'streams', 'result' => [['stream' => [], 'values' => $values]]]]]);
    }

    /** True when some query matches every one of the given fragments. */
    protected function lokiSent(string ...$fragments): bool
    {
        foreach ($this->lokiQueries as $query) {
            $all = true;
            foreach ($fragments as $f) {
                if (! str_contains($query, $f)) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return true;
            }
        }

        return false;
    }
}
