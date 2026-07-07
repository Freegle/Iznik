<?php

namespace App\Mail\AI;

use App\Mail\MjmlMailable;
use App\Mail\Traits\LoggableEmail;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

class AIImageReviewDigestMail extends MjmlMailable
{
    use LoggableEmail;

    public function __construct(
        public int $todayVerdicts,
        public int $totalReviewed,
        public int $totalImages,
        public int $needsImproving,
        public array $topProblems,
    ) {
        parent::__construct();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.geeks_addr'),
                config('freegle.branding.name')
            ),
            subject: $this->getSubject(),
        );
    }

    protected function getSubject(): string
    {
        $pct = $this->totalImages > 0
            ? round(($this->totalReviewed / $this->totalImages) * 100, 1)
            : 0;

        return "AI Image Review: {$this->todayVerdicts} today, {$pct}% reviewed, {$this->needsImproving} need improving";
    }

    public function build(): static
    {
        $pct = $this->totalImages > 0
            ? round(($this->totalReviewed / $this->totalImages) * 100, 1)
            : 0;

        return $this->mjmlView(
            'emails.mjml.admin.ai-image-review-digest',
            [
                'todayVerdicts' => $this->todayVerdicts,
                'totalReviewed' => $this->totalReviewed,
                'totalImages' => $this->totalImages,
                'percentReviewed' => $pct,
                'needsImproving' => $this->needsImproving,
                'topProblems' => $this->topProblems,
            ],
            'emails.text.admin.ai-image-review-digest'
        )->to(config('freegle.mail.geeks_addr'))
            ->applyLogging('AIImageReviewDigest');
    }
}
