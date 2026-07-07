<?php

namespace App\Services\Mail\Incoming;

/**
 * Strip quoted reply text and signatures from email bodies.
 *
 * Ported from iznik-server Message::stripQuoted() and Message::stripSigs().
 * Email quoting varies widely between clients; this handles the most common
 * patterns seen in Freegle traffic.
 */
class StripQuotedService
{
    /**
     * Strip quoted text, signatures, and boilerplate from an email body.
     */
    public function strip(string $textbody): string
    {
        // Convert unicode non-breaking spaces to ascii spaces.
        $textbody = str_replace("\xc2\xa0", "\x20", $textbody);

        // Remove basic quoting (lines starting with > or |).
        $textbody = trim(preg_replace('#(^(>|\|).*(\n|$))+#mi', '', $textbody));

        // eM Client: "------ Original Message ------" with headers block after it.
        $p = strpos($textbody, '------ Original Message ------');
        if ($p !== false) {
            $q = strpos($textbody, "\r\n\r\n", $p);
            $textbody = ($q !== false)
                ? (substr($textbody, 0, $p) . substr($textbody, $q))
                : substr($textbody, 0, $p);
        }

        // Various "original message" separator patterns (top-quoted).
        $separators = [
            '----Original message----',
            '--------------------------------------------',
            '-------- Mensagem original --------',
            '_________________________________________________________________',
            '-------- Original message --------',
            '----- Original Message -----',
            '_____',
            '-----Original Message-----',
            '________________________________',
            '~*~*~*~*~*~*',
        ];

        foreach ($separators as $sep) {
            $p = strpos($textbody, $sep);
            if ($p) {
                $textbody = substr($textbody, 0, $p);
            }
        }

        // From: headers referencing Freegle or TN domains.
        if (preg_match('/(.*)^From\:.*?ilovefreegle\.org$(.*)/ms', $textbody, $matches)) {
            $textbody = $matches[1] . $matches[2];
        }
        if (preg_match('/(.*)^From\:.*?trashnothing\.com$(.*)/ms', $textbody, $matches)) {
            $textbody = $matches[1] . $matches[2];
        }

        // Our own reply prompt.
        $p = strpos($textbody, 'You can respond by just replying to this email');
        if ($p) {
            $textbody = substr($textbody, 0, $p);
        }

        // Gmail-style "On ... wrote:" pattern (assumes top-posting).
        if (preg_match('/(.*)^\s*On.*?wrote\:(\s*)/ms', $textbody, $matches)) {
            $textbody = $matches[1];
        }

        // Yahoo Groups separators.
        if (preg_match('/(.*)^To\:.*yahoogroups.*$.*__,_._,___(.*)/ms', $textbody, $matches)) {
            $textbody = $matches[1] . $matches[2];
        }
        if (preg_match('/(.*?)__,_._,___(.*)/ms', $textbody, $matches)) {
            $textbody = $matches[1];
        }
        if (preg_match('/(.*?)__._,_.___(.*)/ms', $textbody, $matches)) {
            $textbody = $matches[1];
        }

        // Stray header lines (possibly indented).
        $textbody = preg_replace('/[\r\n](\s*)To:.*?$/is', '', $textbody);
        $textbody = preg_replace('/[\r\n](\s*)From:.*?$/is', '', $textbody);
        $textbody = preg_replace('/[\r\n](\s*)Sent:.*?$/is', '', $textbody);
        $textbody = preg_replace('/[\r\n](\s*)Date:.*?$/is', '', $textbody);
        $textbody = preg_replace('/[\r\n](\s*)Subject:.*?$/is', '', $textbody);

        // Strip device/app signatures.
        $textbody = $this->stripSigs($textbody);

        // Yahoo Mail app CSS junk.
        $textbody = str_replace(
            'blockquote, div.yahoo_quoted { margin-left: 0 !important; border-left:1px #715FFA solid !important; padding-left:1ex !important; background-color:white !important; }',
            '',
            $textbody
        );
        $textbody = preg_replace('/\#yiv.*\}\}/', '', $textbody);

        // Strip login keys from our own URLs.
        $userSite = preg_quote(config('freegle.user_site', 'www.ilovefreegle.org'), '/');
        $textbody = preg_replace('/(https:\/\/' . $userSite . '\S*)(k=\S*)/', '$1', $textbody);

        // Redundant line breaks.
        $textbody = preg_replace('/(?:(?:\r\n|\r|\n)\s*){2}/s', "\r\n\r\n", $textbody);

        // Our own footer text.
        $textbody = preg_replace('/This message was from user .*?, and this mail was sent to .*?$/mi', '', $textbody);
        $textbody = preg_replace('/Freegle is registered as a charity.*?nice\./mi', '', $textbody);
        $textbody = preg_replace('/This mail was sent to.*?/mi', '', $textbody);
        $textbody = preg_replace('/You can change your settings by clicking here.*?/mi', '', $textbody);

        // Pipe character used as quote marker.
        $textbody = preg_replace('/^\|/mi', '', $textbody);
        $textbody = trim($textbody);
        if (str_ends_with($textbody, '|')) {
            $textbody = substr($textbody, 0, -1);
        }

        // Inline image references.
        $textbody = preg_replace('/\[cid\:.*?\]/', '', $textbody);

        // TN auto-wording.
        $textbody = str_replace(
            '[Note: This is an automated response from trashnothing.com on behalf of the post author]',
            '',
            $textbody
        );

        // Strip trailing underscores and dashes from quoting artifacts.
        return trim($textbody, " \t\n\r\0\x0B_-");
    }

    /**
     * Strip common device/app signature lines.
     */
    private function stripSigs(string $textbody): string
    {
        $patterns = [
            '/^Get Outlook for Android.*/ims',
            '/^Get Outlook for IOS.*/ims',
            '/^Sent from my Xperia.*/ims',
            '/^Sent from the all-new AOL app.*/ims',
            '/^Sent from my BlueMail/ims',
            '/^Sent using the mail\.com mail app.*/ims',
            '/^Sent from my phone.*/ims',
            '/^Sent from my iPad.*/ims',
            '/^Sent from my .*smartphone\./ims',
            '/^Sent from my iPhone.*/ims',
            '/Sent.* from my iPhone/i',
            '/Sent via BT Email App/i',
            '/^Sent from EE.*/ims',
            '/^Sent from my Samsung device.*/ims',
            '/^Sent from my Galaxy.*/ims',
            '/^Sent from my Samsung Galaxy smartphone.*/ims',
            '/^Sent from my Windows Phone.*/ims',
            '/^Sent from the trash nothing! Mobile App.*/ims',
            '/^Sent from my account on trashnothing\.com.*/ims',
            '/^Save time browsing & posting to.*/ims',
            '/^Sent on the go from.*/ims',
            '/^Sent from Yahoo Mail.*/ims',
            '/^Sent from Windows Mail.*/ims',
            '/^Sent from Mail.*/ims',
            '/^Sent from my BlackBerry.*/ims',
            '/^Sent from my Huawei Mobile.*/ims',
            '/^Sent from my Huawei phone.*/ims',
            '/^Sent from myMail for iOS.*/ims',
            '/^Von meinem Samsung Galaxy Smartphone gesendet.*/ims',
            '/^Sent from Samsung Mobile.*/ims',
            '/^Sent using penguin power from my iPhone.*/ims',
        ];

        foreach ($patterns as $pattern) {
            $textbody = preg_replace($pattern, '', $textbody);
        }

        return $textbody;
    }
}
