<?php

namespace App\Services\Ux\Sources;

use App\Services\Ux\ExposureSource;
use Carbon\Carbon;

/**
 * The experiment krystal-hosting-ad, counted from the database. An exposure is the moment the Go API
 * decided, for a signed-in member on the web whose email domain hosts a WordPress site not on Krystal,
 * whether to show the hosting ad (GET /user/hostingad writes it in both arms; the subject is the
 * domain_hosting row). The ad arm is shown the modal, control is shown nothing. A member is decided
 * once, so each is counted once.
 *
 * Converted: the member clicked through to Krystal within the window. The click is the number
 * detail.clicked on the exposure row, the unix time POST /user/hostingad wrote when the member pressed
 * the button. Control has no button, so its rate is the floor and the ad arm's rate is the click rate.
 *
 * The guardrail is coming back: the share of counted members whose last access is not at least an hour
 * after the exposure must not rise by more than 2 points. An interrupt that sends people away is not
 * worth its clicks.
 *
 * Nothing records an exposure before the first run, so volume() has no history and returns null.
 */
class HostingAdClickSource extends ExposureSource
{
    public const NOT_BACK_MARGIN_POINTS = 2.0;

    public function types(): array
    {
        return ['hosting_ad'];
    }

    public function words(object $exp): array
    {
        $window = self::windowText($this->windowOf($exp));

        return [
            'who' => 'members',
            'started' => 'were counted',
            'finished' => 'clicked through to Krystal within '.$window,
            'tab_started' => 'Counted',
            'tab_finished' => 'Clicked within '.$window,
            'note' => 'Each member is counted once, when the site decided whether to show them the hosting ad, and only when that was at least '.$window.' ago. '
                .'Only the ad arm sees the modal. Clicked means they pressed its button within '.$window.'.',
        ];
    }

    public function guardrails(object $exp): array
    {
        return ['not_back' => ['label' => 'the share of members who have not been back since', 'margin_points' => self::NOT_BACK_MARGIN_POINTS]];
    }

    protected function joins(object $exp): string
    {
        return 'INNER JOIN users u ON u.id = c.userid';
    }

    protected function converted(object $exp): string
    {
        return "JSON_EXTRACT(c.detail, '$.clicked') IS NOT NULL"
            ." AND FROM_UNIXTIME(JSON_EXTRACT(c.detail, '$.clicked')) < c.exposed_at + INTERVAL :window HOUR";
    }

    protected function bad(object $exp): array
    {
        return ['not_back' => 'u.lastaccess < c.exposed_at + INTERVAL 1 HOUR'];
    }

    public function volume(object $exp, Carbon $now): ?array
    {
        return null;
    }
}
