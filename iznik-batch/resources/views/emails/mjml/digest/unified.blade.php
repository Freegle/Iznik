<mjml>
    @php
        $mediaStyles = '
@media only screen and (max-width: 480px) {
  /* Mobile only: clamp the body-text snippet to two lines so a long
     post does not push the Reply button or meta row out of view. */
  .fd-desc {
    display: -webkit-box !important;
    -webkit-line-clamp: 2 !important;
    -webkit-box-orient: vertical !important;
    overflow: hidden !important;
  }
  /* Mobile: hide header thumbs beyond the 5th so the visible thumbs stay
     large enough to read. Inline width from mj-group is overridden so the
     visible cells (logo + 5 thumbs = 6) split the row evenly. */
  .fd-header-thumb-5, .fd-header-thumb-6, .fd-header-thumb-7, .fd-header-thumb-8, .fd-header-thumb-9 {
    display: none !important;
  }
  .fd-header-col { width: 16.66% !important; }
}
/* MOBILE-FIRST default. Most recipients are on mobile, and Gmail mobile web
   strips embedded style blocks, so the DEFAULT (driven by inline styles) is
   the mobile layout: the full-width .fd-narrow-only meta/byline/Reply row
   shows, and the in-column .fd-wide-only copy is inline display:none. Only
   clients that keep embedded styles and are on a wide screen swap to the
   2-column desktop layout. */
@media only screen and (min-width: 481px) {
  .fd-wide-only { display: block !important; }
  .fd-narrow-only { display: none !important; }
}
        ';
    @endphp
    @include('emails.mjml.partials.head', [
        'preview' => $postCount . ' new post' . ($postCount === 1 ? '' : 's') . ' near you',
        'mediaStyles' => $mediaStyles ?? '',
    ])

    <mj-body background-color="#f4f4f4">
        @php
            $offers = collect($posts)->where('type', 'Offer');
            $wanteds = collect($posts)->where('type', 'Wanted');
            $maxHeaderItems = 6;
            // Shared tokens — keep in lockstep with the AMP variant via
            // DigestStyle; never inline accent colours in either template.
            $offerColor = \App\Mail\Digest\DigestStyle::OFFER_GREEN;
            $wantedColor = \App\Mail\Digest\DigestStyle::WANTED_BLUE;
        @endphp

        @if($mode === 'immediate')
        {{-- ═══════════════════════════════════════════════════════════════ --}}
        {{-- IMMEDIATE MODE: single-post card matching browse page style    --}}
        {{-- ═══════════════════════════════════════════════════════════════ --}}

        {{-- Jobs near you, at the top (V1 parity). Shared partial. --}}
        @include('emails.mjml.digest._jobs')

        {{-- Card: image left + content right (matches browse page layout) --}}
        <mj-section background-color="#ffffff" padding="0" border-radius="4px">
            {{-- Image column --}}
            {{-- Use displayImageUrl (direct delivery URL) rather than the
                 trackedImageUrl tracking-proxy wrapper. Gmail's image proxy
                 (ci3.googleusercontent.com/meips/...) was returning 404 for
                 the tracking URL — most likely it dislikes the cross-domain
                 302 from api.ilovefreegle.org to delivery.ilovefreegle.org,
                 or it cached a transient failure. Direct delivery is what
                 the AMP template already uses for the same reason. Click
                 tracking on the message link still fires via the <a href>;
                 only the image-view scroll-depth ping is lost on the hero. --}}
            <mj-column width="38%" padding="0" vertical-align="top">
                <mj-image
                    href="{{ $post['messageUrl'] }}"
                    src="{{ $post['displayImageUrl'] }}"
                    alt="{{ $post['itemName'] }}"
                    padding="0"
                    fluid-on-mobile="true"
                    container-background-color="#e8e8e8"
                />
            </mj-column>
            {{-- Content column --}}
            <mj-column width="62%" padding="16px 20px 12px 16px" vertical-align="top">
                {{-- OFFER / WANTED pill --}}
                <mj-text padding="0 0 8px 0" font-size="13px">
                    <span style="display: inline-block; background-color: {{ $accentColor }}; color: #ffffff; font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 3px; letter-spacing: 0.3px;">{{ $isOffer ? 'OFFER' : 'WANTED' }}</span>
                </mj-text>
                {{-- Title --}}
                <mj-text padding="0 0 4px 0" font-size="18px" font-weight="700" color="#212529" line-height="1.25">
                    <a href="{{ $post['messageUrl'] }}" style="color: #212529; text-decoration: none;">{{ $post['itemName'] }}</a>
                </mj-text>
                {{-- The full description is rendered in its own section
                     below; no snippet here, since the immediate digest is
                     one post and a 120-char preview duplicates the body. --}}
                {{-- Metadata row: location · distance · absolute time, all on
                     one line like the web card (location was on its own line,
                     which looked messy). Time is always absolute, not relative. --}}
                <mj-text padding="0" font-size="12px" color="#888888">
                    @if($post['locationName'])<span style="margin-right: 12px;">&#x1F4CD; {{ $post['locationName'] }}</span>@endif
                    @if($post['distanceText'])<span style="margin-right: 12px;">{{ $post['distanceText'] }}</span>@endif
                    <span>&#x1F552; {{ $post['arrivalFormatted'] }}</span>
                </mj-text>
            </mj-column>
        </mj-section>

        {{-- Full body text. nl2br(e(…)) so user-typed paragraph breaks
             render as <br> in HTML clients (which otherwise collapse \n to
             a space and run the whole description into one paragraph).
             e() still escapes user content before nl2br adds the <br>. --}}
        @if($post['messageText'])
        <mj-section background-color="#ffffff" padding="0 20px">
            <mj-column>
                <mj-divider border-color="#eeeeee" border-width="1px" padding="0" />
                <mj-text font-size="15px" color="#333333" line-height="1.65" padding="16px 0">
                    {!! nl2br(e($post['messageText'])) !!}
                </mj-text>
            </mj-column>
        </mj-section>
        @endif

        {{-- Posted by + reply --}}
        <mj-section background-color="#ffffff" padding="0 20px 20px">
            <mj-column>
                {{-- Posted by --}}
                <mj-text font-size="13px" color="#888888" padding="0 0 16px 0">
                    <img src="{{ $post['posterAvatarUrl'] }}" alt="" width="22" height="22" style="display: inline-block; width: 22px; height: 22px; border-radius: 50%; vertical-align: middle; margin-right: 6px;" />
                    Posted by <strong style="color: #555555;">{{ \Illuminate\Support\Str::limit($post['posterName'], 40) }}</strong>
                </mj-text>
                {{-- First posted (V1 single.html parity — only shown when the
                     message has actually been reposted to this group). Subtle
                     line under "Posted by …", no styling change to the
                     attribution row itself. --}}
                @if($post['firstPostedFormatted'] ?? null)
                <mj-text padding="0 0 16px 0" font-size="11px" color="#999999">
                    First posted&nbsp;{{ $post['firstPostedFormatted'] }}
                </mj-text>
                @endif
                {{-- Primary CTA. width="100%" + inner-padding="13px 0"
                     made Gmail render the button as a full-width green
                     bar with the "Reply" text left-aligned. Drop the
                     width override and give the button horizontal
                     inner-padding so it auto-sizes to text+padding and
                     align="center" centers it in the column. Same shape
                     in every client; text always sits in the middle. --}}
                <mj-button
                    href="{{ $post['messageUrl'] }}"
                    background-color="{{ $accentColor }}"
                    color="#ffffff"
                    font-size="16px"
                    font-weight="600"
                    inner-padding="13px 48px"
                    border-radius="5px"
                    align="center"
                    padding="0 0 10px 0"
                >
                    Reply
                </mj-button>
                {{-- Secondary actions --}}
                <mj-text align="center" font-size="13px" color="#888888" padding="0">
                    <a href="{{ $browseUrl }}" style="color: #555555; text-decoration: none;">Browse other posts</a>
                    &nbsp;&middot;&nbsp;
                    <a href="{{ $userSite }}/offer" style="color: #555555; text-decoration: none;">Post something</a>
                </mj-text>
            </mj-column>
        </mj-section>

        @else
        {{-- ═══════════════════════════════════════════════════════════════ --}}
        {{-- DAILY MODE: multi-post digest with thumbnail nav               --}}
        {{-- ═══════════════════════════════════════════════════════════════ --}}

        {{-- Header - Freegle brand green with logo + thumbnail nav --}}
        {{-- Thumbnails use trackedImageUrl (proxy) when available, falling back
             to displayImageUrl. Each thumbnail links to its card anchor. --}}
        {{-- Skip posts with placeholder images — only show real photos in the
             header strip so the thumbnail nav is a faithful preview of what
             follows, not a wall of default Offer/Wanted icons. Cards still
             show the placeholder for no-photo posts further down. --}}
        {{-- Render up to 10 thumbs; @media in the head hides 5-9 on mobile so
             the visible thumbs stay large enough to read on a 360px viewport.
             Other clients (eM Client, Apple Mail, Gmail desktop via AMP) keep
             the full row. --}}
        @php
            $headerThumbs = collect($posts)->filter(fn ($p) => empty($p['isPlaceholder']))->take(10)->values();
        @endphp
        @if($headerThumbs->isNotEmpty())
        <mj-section mj-class="bg-success" padding="12px 16px">
            {{-- One mj-column per item (logo + each thumb) inside an mj-group
                 so they don't stack on mobile. Each column gets an equal share
                 of the row, and each mj-image is width="100%" so it fills its
                 column — the strip auto-sizes to whatever space is available,
                 fewer thumbs = larger images, more thumbs = smaller. Sources
                 are pre-cropped square (240×240) by the delivery proxy so the
                 natural aspect ratio is 1:1; mj-image preserves it. --}}
            <mj-group>
                {{-- Every column gets the SAME left/right padding so each
                     takes exactly the same share of the row (an unbalanced
                     padding on the last column made it visibly wider in
                     K-9 Mail). 4px each side = 8px between adjacent thumbs.
                     css-class tags individual columns so @media can hide
                     header-thumb-5..9 on mobile. --}}
                <mj-column vertical-align="middle" padding-left="4px" padding-right="4px" padding-top="0" padding-bottom="0" css-class="fd-header-col">
                    <mj-image
                        src="{{ config('freegle.branding.logo_url') }}"
                        alt="Freegle"
                        width="100%"
                        padding="0"
                        align="center"
                    />
                </mj-column>
                @foreach($headerThumbs as $post)
                <mj-column vertical-align="middle" padding-left="4px" padding-right="4px" padding-top="0" padding-bottom="0" css-class="fd-header-col fd-header-thumb-{{ $loop->index }}">
                    <mj-image
                        src="{{ $post['thumbImageUrl'] }}"
                        href="#msg-{{ $post['message']->id }}"
                        alt="{{ $post['itemName'] }}"
                        width="100%"
                        padding="0"
                        border="2px solid rgba(255,255,255,0.6)"
                        border-radius="4px"
                    />
                </mj-column>
                @endforeach
            </mj-group>
        </mj-section>
        @endif

        {{-- ════════════════════════════════════════════════════════════════
             "In this digest" summary index. One line per post, each linking to
             the post's page on the website (NOT an in-email #anchor — V1's
             anchor approach rendered inconsistently across clients). The first
             DigestStyle::SUMMARY_VISIBLE_LINES lines always show; any overflow
             collapses into a <details>/<summary> "Show N more" disclosure.
             Apple Mail and modern webmail render the toggle; clients that ignore
             <details> show the overflow expanded — but it's one short line per
             post, so the digest never balloons. AMP clients get the native
             <amp-accordion> equivalent in the AMP MIME part. --}}
        @php
            $summaryPosts = collect($posts);
            $summaryVisible = \App\Mail\Digest\DigestStyle::SUMMARY_VISIBLE_LINES;
            $summaryHidden = $summaryPosts->slice($summaryVisible);
            // Each summary line is broken with a trailing <br> rather than
            // display:block on the <a>: Outlook's Word engine ignores display:block
            // on inline elements, so items ran together (Discourse t/9363/31). <br>
            // is the most space-efficient block break Outlook honours - about 5 bytes
            // per line vs ~40 for a <div> wrapper or a <table> row - which matters in
            // a digest that lists up to 200 posts, near Gmail's ~102KB clip. Vertical
            // spacing comes from the mj-text line-height.
            $summaryLink = 'color: ' . \App\Mail\Digest\DigestStyle::OFFER_GREEN . '; text-decoration: none;';
        @endphp
        @if($summaryPosts->count() >= 2)
        <mj-section background-color="#ffffff" padding="16px 20px 4px">
            <mj-column>
                <mj-text font-size="13px" color="#212529" padding="0 0 8px 0">
                    <strong style="text-transform: uppercase; letter-spacing: 0.3px;">In this digest</strong>
                </mj-text>
                <mj-text font-size="14px" color="#212529" line-height="1.6" padding="0">
                    @foreach($summaryPosts->take($summaryVisible) as $summaryPost)
                    <a href="{{ $summaryPost['summaryUrl'] }}" style="{{ $summaryLink }}">{{ $summaryPost['subject'] }}</a><br>
                    @endforeach
                    @if($summaryHidden->isNotEmpty())
                    <details style="margin-top: 2px;">
                        <summary style="cursor: pointer; color: {{ \App\Mail\Digest\DigestStyle::OFFER_GREEN }}; font-weight: 600;">Show {{ $summaryHidden->count() }} more</summary>
                        <div style="margin-top: 6px;">
                            @foreach($summaryHidden as $summaryPost)
                            <a href="{{ $summaryPost['summaryUrl'] }}" style="{{ $summaryLink }}">{{ $summaryPost['subject'] }}</a><br>
                            @endforeach
                        </div>
                    </details>
                    @endif
                </mj-text>
            </mj-column>
        </mj-section>
        @endif

        {{-- Jobs near you, at the top before the posts (V1 multiple.mjml
             parity). Shared partial — same block the immediate branch uses. --}}
        @include('emails.mjml.digest._jobs')

        {{-- Post cards --}}
        @foreach($posts as $index => $post)
        {{-- Card separator --}}
        @if($index > 0)
        <mj-section padding="0" background-color="#ffffff">
            <mj-column>
                <mj-divider border-color="#e9ecef" border-width="1px" padding="0 20px" />
            </mj-column>
        </mj-section>
        @endif

        {{-- Daily card — shared partial (live variant: Reply shown, not muted).
             The 2-column mj-group block STAYS 2-column on mobile and a
             full-width reflow section follows it; see _card.blade.php. --}}
        @include('emails.mjml.digest._card', [
            'post' => $post,
            'offerColor' => $offerColor,
            'wantedColor' => $wantedColor,
            'showReply' => true,
            'muted' => false,
        ])
        @endforeach

        {{-- "Came and went" — Taken/Received posts since the last digest, shown
             greyed out with a nudge to increase digest frequency (V1 parity,
             Digest.php $unavailable). Daily only; suppressed when empty. --}}
        @if(count($completedPosts ?? []) > 0)
        <mj-section background-color="#e9e9e9" padding="16px 20px 8px">
            <mj-column>
                <mj-text font-size="14px" font-weight="700" color="#444444" padding="0 0 4px 0">
                    Came and went
                </mj-text>
                <mj-text font-size="12px" color="#666666" padding="0 0 12px 0" line-height="1.5">
                    These were posted since your last email but have already gone. If you'd like to catch them in time, try a more frequent digest in <a href="{{ $settingsUrl }}" style="color: #3c763d; text-decoration: none; font-weight: bold;">Settings</a>.
                </mj-text>
            </mj-column>
        </mj-section>
        {{-- Same card partial as the live posts above — same geometry, just
             greyed ($muted) and without the Reply button ($showReply=false).
             The meta line reads "Taken/Received · <date>" via $cp['metaText']
             rather than the live distance · arrival pairing. --}}
        @foreach($completedPosts as $cp)
        @include('emails.mjml.digest._card', [
            'post' => $cp,
            'offerColor' => $offerColor,
            'wantedColor' => $wantedColor,
            'showReply' => false,
            'muted' => true,
        ])
        @endforeach
        @endif

        {{-- Browse all CTA --}}
        <mj-section background-color="#ffffff" padding="16px 20px 20px 20px">
            <mj-column>
                <mj-divider border-color="#e9ecef" border-width="1px" padding="0 0 16px 0" />
                <mj-button
                    href="{{ $browseUrl }}"
                    mj-class="btn-success"
                    font-size="16px"
                    inner-padding="12px 40px"
                    border-radius="4px"
                >
                    Browse All Posts
                </mj-button>
            </mj-column>
        </mj-section>

        @if(isset($sponsors) && $sponsors->isNotEmpty())
        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column>
                <mj-divider border-color="#eeeeee" padding-bottom="5px" />
                <mj-text font-size="12px" color="#888888" font-style="italic" padding-bottom="5px">
                    Sponsored by:
                </mj-text>
            </mj-column>
        </mj-section>
        @foreach($sponsors as $sponsor)
        <mj-section background-color="#ffffff" padding="0 20px 10px">
            <mj-column width="80px" vertical-align="middle">
                @if($sponsor->imageurl)
                <mj-image
                    width="60px"
                    src="{{ $sponsor->imageurl }}"
                    alt="{{ $sponsor->name }}"
                    href="{{ $sponsor->linkurl }}"
                    border-radius="5px"
                />
                @endif
            </mj-column>
            <mj-column vertical-align="middle">
                <mj-text font-size="13px">
                    @if($sponsor->linkurl)
                    <a href="{{ $sponsor->linkurl }}" style="color: #338808; text-decoration: none; font-weight: bold;">{{ $sponsor->name }}</a>
                    @else
                    <strong>{{ $sponsor->name }}</strong>
                    @endif
                    @if($sponsor->tagline)
                    <br /><span style="font-size: 11px; color: #666;">{{ $sponsor->tagline }}</span>
                    @endif
                </mj-text>
            </mj-column>
        </mj-section>
        @endforeach
        @endif

        @include('emails.mjml.partials.footer', ['email' => $user->email_preferred, 'settingsUrl' => $settingsUrl])

        @if(isset($trackingPixelMjml))
        {!! $trackingPixelMjml !!}
        @endif

        @endif
    </mj-body>
</mjml>
