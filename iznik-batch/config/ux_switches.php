<?php

/**
 * Every UX experiment that batch code reads a member's arm for with UxArm::for(), and that this
 * code contains the switch for. An experiment that is not listed is control for every member,
 * whatever the `ux_experiments` config row says (App\Services\Ux\UxArm). The Go API has its own list,
 * ServerSwitches in iznik-server-go/ux/serverarm.go, and the browser has
 * iznik-nuxt3/ux-experiments.manifest.json; none can see the others.
 *
 * Add the entry in the same change as the UxArm::for() call, and take both out together when the
 * experiment is cleaned up. tests/Unit/Ux/UxSwitchManifestTest.php fails when a call has no entry,
 * when an entry has no call, when a name is not a string literal (the scan cannot see it), and when
 * an entry is more than 30 days old unless keep_until is still ahead. It warns past 14 days. A server
 * run adopted at day 14 lives about 35 days, so set keep_until when it is adopted.
 *
 * ux:experiment start refuses a mail or push experiment that has no entry here, or whose arms or
 * metric source differ from the experiment's, because that means the code is not deployed yet.
 *
 * An entry:
 *   [
 *       'name' => 'digest-subject',                 // the experiment's name, as in UxArm::for($id, 'digest-subject')
 *       'opened' => '2026-10-20',                   // YYYY-MM-DD
 *       'pr' => 'https://github.com/Freegle/Iznik/pull/NNNN',
 *       'keep_until' => null,                       // YYYY-MM-DD, with the reason in the PR
 *       'channel' => 'email',                       // email, push or server
 *       'type' => 'UnifiedDigestDaily',             // the email_type or the push kind
 *       'varies' => 'subject',                      // subject, body, title, timing, order or step
 *       'arms' => ['control', 'items'],
 *       'metric' => ['source' => 'email_click', 'window_hours' => 24],
 *   ]
 *
 * docs/developers/reference/ux-experiments.md, "Experiments in mail, push and the server".
 */
return [
];
