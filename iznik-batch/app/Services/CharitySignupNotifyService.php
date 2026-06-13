<?php

namespace App\Services;

use App\Mail\Alert\AlertMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Notify the geeks team of new Charity Partner signups.
 *
 * Charity signups land in the `charities` table (status Pending) via the Go API,
 * which emails partnerships@ — but nobody watches them (the one live row has been
 * Pending since April). This emails geeks@ about any signups not yet reported, so
 * they get picked up, and marks them notified so each is reported exactly once.
 */
class CharitySignupNotifyService
{
    /**
     * @return array{notified:int}
     */
    public function process(bool $dryRun = false): array
    {
        $stats = ['notified' => 0];

        $new = DB::table('charities')
            ->where('geeknotified', 0)
            ->orderBy('id')
            ->get();

        if ($new->isEmpty()) {
            return $stats;
        }

        $count = $new->count();
        $geeks = config('freegle.mail.geeks_addr') ?: 'geeks@ilovefreegle.org';
        $subject = $count === 1
            ? 'New Freegle Charity Partner signup'
            : "{$count} new Freegle Charity Partner signups";

        [$html, $text] = $this->buildBody($new);

        if (!$dryRun) {
            app(EmailSpoolerService::class)->spool(new AlertMail(
                recipientEmail: $geeks,
                recipientName: 'Freegle Geeks',
                fromAddress: config('freegle.mail.noreply_addr'),
                fromName: config('freegle.branding.name'),
                subjectLine: $subject,
                htmlBody: $html,
                textBody: $text,
            ));

            DB::table('charities')
                ->whereIn('id', $new->pluck('id')->all())
                ->update(['geeknotified' => 1]);
        }

        $stats['notified'] = $count;
        Log::info("CharitySignupNotify: notified geeks of {$count} new charity signup(s)", ['dry_run' => $dryRun]);

        return $stats;
    }

    /**
     * @return array{0:string,1:string} [htmlBody, textBody]
     */
    private function buildBody($charities): array
    {
        $rows = [];
        $textLines = [];
        foreach ($charities as $c) {
            $type = $c->orgtype === 'registered' ? 'Registered charity' : 'Other organisation';
            $num = $c->charitynumber ? " (charity no. {$c->charitynumber})" : '';
            $contactName = $c->contactname ?: '';

            $cell = '<strong>' . e($c->orgname) . '</strong>' . e($num) . '<br>'
                . e($type) . ' — status ' . e($c->status) . '<br>'
                . 'Contact: ' . e(trim($contactName . ' <' . $c->contactemail . '>'));
            if ($c->website) {
                $cell .= '<br>Website: ' . e($c->website);
            }
            if ($c->userid) {
                $cell .= '<br>User ID: ' . (int) $c->userid;
            }
            if ($c->description) {
                $cell .= '<br>' . e($c->description);
            }
            $rows[] = '<tr><td style="padding:6px 10px;border-bottom:1px solid #eee;">' . $cell . '</td></tr>';

            $textLines[] = "- {$c->orgname}{$num} — {$type}, status {$c->status}, contact "
                . trim($contactName . ' <' . $c->contactemail . '>');
        }

        $html = '<p>New Charity Partner signup(s) recorded in the <code>charities</code> table — these need reviewing/approving:</p>'
            . '<table style="border-collapse:collapse;">' . implode('', $rows) . '</table>'
            . '<p>They remain <strong>Pending</strong> until approved.</p>';
        $text = "New Charity Partner signup(s) recorded — these need reviewing/approving:\n\n"
            . implode("\n", $textLines) . "\n\nThey remain Pending until approved.";

        return [$html, $text];
    }
}
