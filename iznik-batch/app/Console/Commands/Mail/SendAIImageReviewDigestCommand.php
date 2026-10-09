<?php

namespace App\Console\Commands\Mail;

use App\Database\Expressions\Alias;
use App\Database\Expressions\Arithmetic;
use App\Database\Expressions\Comparison;
use App\Database\Expressions\Count;
use App\Database\Expressions\Sum;
use App\Database\Expressions\Value;
use App\Mail\AI\AIImageReviewDigestMail;
use App\Mail\Traits\FeatureFlags;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class SendAIImageReviewDigestCommand extends Command
{
    use FeatureFlags;

    protected $signature = 'mail:ai-image-review:digest {--spool} {--dry-run}';

    protected $description = 'Send daily AI image review digest to geeks';

    public function handle(): int
    {
        if (! self::isEmailTypeEnabled('AIImageReviewDigest')) {
            $this->info('AIImageReviewDigest emails not enabled');

            return Command::SUCCESS;
        }

        // 1. Count today's verdicts.
        $todayVerdicts = (int) DB::table('microactions')
            ->where('actiontype', 'AIImageReview')
            ->whereDate('timestamp', today())
            ->count();

        // 2. Total AI images.
        $totalImages = (int) DB::table('ai_images')
            ->whereNotNull('externaluid')
            ->where('externaluid', '!=', '')
            ->count();

        // 3. Find outlier voters (>90% same result across 10+ votes) to exclude.
        $outlierUserIds = $this->getOutlierVoterIds();

        // 4. Count images with quorum (5+ non-outlier votes).
        $totalReviewed = $this->countImagesWithQuorum($outlierUserIds);

        // 5. Get images where majority of non-outlier votes = Reject.
        $problemImages = $this->getProblemImages($outlierUserIds);
        $needsImproving = count($problemImages);
        $topProblems = array_slice($problemImages, 0, 20);

        $this->info("Today: {$todayVerdicts} verdicts. Reviewed: {$totalReviewed}/{$totalImages}. Problems: {$needsImproving}.");

        if ($this->option('dry-run')) {
            $this->info('Dry run — not sending email.');
            $this->table(
                ['Image', 'Uses', 'Good', 'Bad', 'People'],
                collect($topProblems)->map(fn ($img) => [
                    $img['name'], $img['usage_count'], $img['approve_count'], $img['reject_count'], $img['people_count'],
                ])->toArray()
            );

            return Command::SUCCESS;
        }

        $mail = new AIImageReviewDigestMail(
            $todayVerdicts,
            $totalReviewed,
            $totalImages,
            $needsImproving,
            $topProblems,
        );

        app(\App\Services\EmailSpoolerService::class)->spool($mail);

        $this->info('Digest email sent.');

        return Command::SUCCESS;
    }

    /**
     * Find user IDs of outlier voters: those who have voted 10+ times
     * and >90% of their votes are the same result (all approve or all reject).
     */
    public function getOutlierVoterIds(): array
    {
        return DB::table('microactions')
            ->select('userid')
            ->addSelect(new Alias(new Count(), 'total_votes'))
            ->addSelect(new Alias(new Sum(new Comparison('result', '=', Value::of('Approve'))), 'approve_count'))
            ->addSelect(new Alias(new Sum(new Comparison('result', '=', Value::of('Reject'))), 'reject_count'))
            ->where('actiontype', 'AIImageReview')
            ->groupBy('userid')
            ->having('total_votes', '>=', 10)
            ->having(function ($query) {
                $query->having(new Comparison(new Arithmetic('approve_count', '/', 'total_votes'), '>', 0.9))
                    ->orHaving(new Comparison(new Arithmetic('reject_count', '/', 'total_votes'), '>', 0.9));
            })
            ->pluck('userid')
            ->toArray();
    }

    /**
     * Count AI images that have reached quorum (5+ non-outlier votes).
     */
    private function countImagesWithQuorum(array $outlierUserIds): int
    {
        $query = DB::table('microactions')
            ->select('aiimageid')
            ->where('actiontype', 'AIImageReview');

        if (! empty($outlierUserIds)) {
            $query->whereNotIn('userid', $outlierUserIds);
        }

        return $query->groupBy('aiimageid')
            ->having(new Comparison(new Count(), '>=', 5))
            ->get()
            ->count();
    }

    /**
     * Get AI images where the majority of non-outlier votes are Reject,
     * ordered by usage_count DESC.
     */
    private function getProblemImages(array $outlierUserIds): array
    {
        $query = DB::table('microactions')
            ->join('ai_images', 'ai_images.id', '=', 'microactions.aiimageid')
            ->select(
                'ai_images.id',
                'ai_images.name',
                'ai_images.usage_count',
            )
            ->addSelect(new Alias(new Sum(new Comparison('microactions.result', '=', Value::of('Approve'))), 'approve_count'))
            ->addSelect(new Alias(new Sum(new Comparison('microactions.result', '=', Value::of('Reject'))), 'reject_count'))
            ->addSelect(new Alias(new Sum(new Comparison('microactions.containspeople', '=', 1)), 'people_count'))
            ->where('microactions.actiontype', 'AIImageReview');

        if (! empty($outlierUserIds)) {
            $query->whereNotIn('microactions.userid', $outlierUserIds);
        }

        return $query->groupBy('ai_images.id', 'ai_images.name', 'ai_images.usage_count')
            ->having(new Comparison(new Count(), '>=', 5))
            ->having(new Comparison(
                new Sum(new Comparison('microactions.result', '=', Value::of('Reject'))),
                '>',
                new Sum(new Comparison('microactions.result', '=', Value::of('Approve')))
            ))
            ->orderByDesc('ai_images.usage_count')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'usage_count' => (int) $row->usage_count,
                'approve_count' => (int) $row->approve_count,
                'reject_count' => (int) $row->reject_count,
                'people_count' => (int) ($row->people_count ?? 0),
            ])
            ->toArray();
    }
}
