<mjml>
    @php
        $mediaStyles = '
@media only screen and (max-width: 480px) {
  .fd-pill-title-wrap { display: block !important; width: 100% !important; }
  .fd-reply-time-wrap { display: block !important; width: 100% !important; }
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
            $offerColor = '#3c763d';
            $wantedColor = '#4895DD';
        @endphp

        @if($mode === 'immediate')
        {{-- ═══════════════════════════════════════════════════════════════ --}}
        {{-- IMMEDIATE MODE: single-post card matching browse page style    --}}
        {{-- ═══════════════════════════════════════════════════════════════ --}}

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

        {{-- Jobs section (V1 single.html parity — "Jobs near you" + CTA).
             Mirrors the ChatNotification jobs section so the layout and link
             tracking shape match. Falls back to a standalone "Donating helps
             too!" button when the user has no nearby jobs so we always keep
             the donation CTA the V1 template carried. --}}
        @if(isset($jobAds) && $jobAds->isNotEmpty())
        <mj-section background-color="#F7F6EC" padding="20px 20px 10px 20px" border-top="1px solid #e9ecef">
            <mj-column>
                <mj-text font-size="16px" font-weight="bold" color="#333333" align="center" padding-bottom="10px">
                    Jobs near you
                </mj-text>
            </mj-column>
        </mj-section>
        {{-- Mobile-tight: 12px side padding, 4px vertical row padding, 1.25 line-height
             on the title, and a 44px icon column (40px image + 4px gap to text). --}}
        <mj-section background-color="#F7F6EC" padding="5px 12px">
            <mj-column>
                <mj-table cellpadding="0" cellspacing="0" width="100%">
                    @foreach($jobAds as $job)
                    <tr>
                        @if($job->image_url ?? null)
                        <td style="width: 44px; padding: 4px 4px 4px 0; vertical-align: middle;">
                            <a href="{{ $job->tracked_url }}">
                                <img src="{{ $job->image_url }}" width="40" height="40" alt="" style="border-radius: 4px; display: block;" />
                            </a>
                        </td>
                        <td style="padding: 4px 0; vertical-align: middle;">
                        @else
                        {{-- No image: span both columns so the title fills the row instead of
                             being squeezed into the right column reserved by sibling rows. --}}
                        <td colspan="2" style="padding: 4px 0; vertical-align: middle;">
                        @endif
                            <a href="{{ $job->tracked_url }}" style="color: #338808; font-weight: bold; text-decoration: none; font-size: 14px; line-height: 1.25;">
                                {{ $job->title }}
                            </a>
                            @if($job->location ?? null)
                            <br/><span style="color: #666666; font-size: 12px; line-height: 1.3;">{{ $job->location }}</span>
                            @endif
                        </td>
                    </tr>
                    @endforeach
                </mj-table>
            </mj-column>
        </mj-section>
        <mj-section background-color="#F7F6EC" padding="0 20px 10px 20px">
            <mj-column>
                <mj-text font-size="12px" color="#666666" line-height="1.4">
                    If you are interested and click, it will raise a little to help keep Freegle running and free to use.
                </mj-text>
            </mj-column>
        </mj-section>
        <mj-section background-color="#F7F6EC" padding="0 20px 20px 20px">
            <mj-column width="50%">
                <mj-button href="{{ $jobsUrl }}" background-color="{{ $accentColor }}" color="#ffffff" font-size="14px" inner-padding="10px 25px" border-radius="5px" width="90%">
                    View more jobs
                </mj-button>
            </mj-column>
            <mj-column width="50%">
                <mj-button href="{{ $donateUrl }}" background-color="{{ $accentColor }}" color="#ffffff" font-size="14px" inner-padding="10px 25px" border-radius="5px" width="90%">
                    Donating helps too!
                </mj-button>
            </mj-column>
        </mj-section>
        @else
        <mj-section background-color="#ffffff" padding="0 20px 20px">
            <mj-column>
                <mj-divider border-color="#eeeeee" border-width="1px" padding="0 0 16px 0" />
                <mj-button
                    href="{{ $donateUrl ?? 'https://freegle.in/paypal1510' }}"
                    background-color="{{ $accentColor }}"
                    color="#ffffff"
                    font-size="14px"
                    font-weight="600"
                    inner-padding="10px 25px"
                    border-radius="5px"
                    align="center"
                >
                    Donating helps too!
                </mj-button>
            </mj-column>
        </mj-section>
        @endif

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
        @php
            $headerPosts = collect($posts)->filter(fn ($p) => empty($p['isPlaceholder']))->values();
            $headerThumbs = $headerPosts->take($maxHeaderItems);
            $headerOverflow = max(0, $headerPosts->count() - $maxHeaderItems);
        @endphp
        @if($headerThumbs->isNotEmpty())
        <mj-section mj-class="bg-success" padding="12px 16px">
            {{-- mj-group keeps logo + thumb row side-by-side on mobile (without
                 it MJML's <480px breakpoint stacks them). --}}
            <mj-group>
                <mj-column width="20%" vertical-align="middle">
                    <mj-image
                        width="50px"
                        src="{{ config('freegle.branding.logo_url') }}"
                        alt="Freegle"
                        align="left"
                        padding="0"
                    />
                </mj-column>
                <mj-column width="80%" vertical-align="middle">
                    {{-- mj-table is MJML's table primitive — compiles to a real
                         <table>/<tr>/<td> structure that renders consistently
                         across Gmail desktop, Outlook and mobile. Replaces the
                         earlier inline-block <a> chain inside <mj-text font-size:0>
                         which Gmail desktop wasn't rendering (Edward 2026-06-11). --}}
                    <mj-table padding="0" cellpadding="0" cellspacing="0">
                        <tr>
                            @foreach($headerThumbs as $post)
                            @php
                                $thumbSrc = $post['trackedImageUrl'] ?? $post['displayImageUrl'] ?? '';
                                $isOfferThumb = $post['type'] === 'Offer';
                                $pillBg = $isOfferThumb ? $offerColor : $wantedColor;
                            @endphp
                            <td style="width: 48px; padding: 0 4px 0 0; vertical-align: top;">
                                <a href="#msg-{{ $post['message']->id }}" style="text-decoration: none; display: block;">
                                    <img src="{{ $thumbSrc }}"
                                         alt="{{ $post['itemName'] }}"
                                         width="44"
                                         height="44"
                                         style="display: block; width: 44px; height: 44px; object-fit: cover; border-radius: 4px; border: 2px solid rgba(255,255,255,0.5);"
                                    />
                                    {{-- 7px keeps "WANTED" within 44px thumbnail width. --}}
                                    <span style="display: block; background-color: {{ $pillBg }}; color: #ffffff; font-size: 7px; font-weight: bold; text-align: center; padding: 1px 0; border-radius: 0 0 3px 3px; line-height: 1.3;">{{ $isOfferThumb ? 'OFFER' : 'WANTED' }}</span>
                                </a>
                            </td>
                            @endforeach
                            @if($headerOverflow > 0)
                            <td style="width: 48px; padding: 0; vertical-align: top;">
                                <a href="{{ $browseUrl }}" style="display: block; width: 44px; height: 52px; background-color: rgba(255,255,255,0.2); border-radius: 4px; text-align: center; line-height: 52px; color: #ffffff; font-size: 11px; font-weight: bold; text-decoration: none;">+{{ $headerOverflow }}</a>
                            </td>
                            @endif
                        </tr>
                    </mj-table>
                </mj-column>
            </mj-group>
        </mj-section>
        @else
        {{-- All posts in this digest are placeholder-only; show just the logo
             so the brand-green strip still anchors the email visually. --}}
        <mj-section mj-class="bg-success" padding="12px 16px">
            <mj-column vertical-align="middle">
                <mj-image
                    width="50px"
                    src="{{ config('freegle.branding.logo_url') }}"
                    alt="Freegle"
                    align="left"
                    padding="0"
                />
            </mj-column>
        </mj-section>
        @endif

        {{-- Post cards --}}
        @foreach($posts as $index => $post)
        @php $isOffer = $post['type'] === 'Offer'; @endphp

        {{-- Card separator --}}
        @if($index > 0)
        <mj-section padding="0" background-color="#ffffff">
            <mj-column>
                <mj-divider border-color="#e9ecef" border-width="1px" padding="0 20px" />
            </mj-column>
        </mj-section>
        @endif

        <mj-section background-color="#ffffff" padding="12px 20px">
            <mj-column>
                <mj-text padding="0" font-size="14px" color="#212529">
                    <a id="msg-{{ $post['message']->id }}"></a>
                    {{-- Outer card: image on the left (fixed 120px), content fills the rest.
                         The image td uses rowspan=2 so it spans both content and bottom meta rows.
                         On desktop both content columns sit side-by-side.  On narrow mobile
                         the image column stays fixed (120px) and the right column wraps. --}}
                    <table cellpadding="0" cellspacing="0" border="0" role="presentation" style="border-collapse: collapse; width: 100%;">
                        {{-- Top row: image (rowspan) + pill/title/description --}}
                        <tr>
                            <td rowspan="2" style="vertical-align: top; width: 120px; padding-right: 12px;">
                                <a href="{{ $post['messageUrl'] }}">
                                    <img src="{{ $post['trackedImageUrl'] ?? $post['displayImageUrl'] }}" alt="{{ $post['itemName'] }}" width="120" height="120" style="display: block; width: 120px; height: 120px; object-fit: cover; border-radius: 4px;" />
                                </a>
                            </td>
                            <td style="vertical-align: top; padding-bottom: 4px;">
                                {{-- Pill + title/location: fluid-hybrid inline-block cells.
                                     On wide screens both sit on one line; on narrow screens
                                     (< ~320px usable) the title wraps to a new line naturally.
                                     The media-query block above forces the title cell to block
                                     on ≤ 480px as a progressive enhancement for clients that
                                     honour @media (Apple Mail, Gmail mobile app account types
                                     that preserve <style> tags). --}}
                                <!--[if mso]><table cellpadding="0" cellspacing="0"><tr><td style="vertical-align:top;width:85px;padding-right:8px;"><![endif]-->
                                <div class="fd-pill-title-wrap" style="display: inline-block; vertical-align: top; width: 85px; max-width: 85px; padding-right: 8px; padding-bottom: 4px;">
                                    <span style="display: inline-block; background-color: {{ $isOffer ? $offerColor : $wantedColor }}; color: #ffffff; font-size: 13px; font-weight: bold; padding: 6px 11px; border-radius: 4px; line-height: 1; white-space: nowrap;">{{ $isOffer ? 'OFFER' : 'WANTED' }}</span>
                                </div><!--[if mso]></td><td style="vertical-align:top;"><![endif]-->
                                <div class="fd-pill-title-wrap" style="display: inline-block; vertical-align: top; width: calc(100% - 97px); max-width: calc(100% - 97px); padding-bottom: 4px;">
                                    <a href="{{ $post['messageUrl'] }}" style="color: #212529; text-decoration: none; font-weight: 600; font-size: 15px; line-height: 1.4;">{{ $post['itemName'] }}</a>
                                    @if($post['locationName'])
                                    <br/><span style="color: #212529; font-size: 12px; font-weight: 500;">{{ $post['locationName'] }}</span>
                                    @endif
                                </div><!--[if mso]></td></tr></table><![endif]-->
                                @if($post['messageText'] || $post['postedToText'])
                                <div style="padding-top: 2px;">
                                    @if($post['messageText'])
                                    <span style="color: #808080; font-size: 13px; font-weight: 500; line-height: 1.4;">{{ \Illuminate\Support\Str::limit($post['messageText'], 100, '...') }}</span>
                                    @endif
                                    @if($post['postedToText'])
                                    <br/><span style="color: #999999; font-size: 11px; font-style: italic;">{{ $post['postedToText'] }}</span>
                                    @endif
                                </div>
                                @endif
                            </td>
                        </tr>
                        {{-- Bottom row: Reply button + distance/time meta.
                             Fluid-hybrid: Reply and meta each use inline-block with max-width so
                             on a narrow screen the meta wraps below Reply rather than squishing it.
                             white-space:nowrap on the Reply anchor prevents single-character wrapping.
                             The media-query block forces the meta cell to a new line on ≤ 480px
                             clients that support @media. --}}
                        <tr>
                            <td style="vertical-align: bottom; padding-top: 4px;">
                                <!--[if mso]><table cellpadding="0" cellspacing="0"><tr><td style="vertical-align:middle;padding-right:8px;"><![endif]-->
                                <div class="fd-reply-time-wrap" style="display: inline-block; vertical-align: middle; padding-right: 8px; padding-bottom: 2px;">
                                    <a href="{{ $post['messageUrl'] }}" style="display: inline-block; background-color: {{ $isOffer ? $offerColor : $wantedColor }}; color: #ffffff; font-size: 13px; font-weight: 600; padding: 6px 16px; border-radius: 4px; text-decoration: none; white-space: nowrap;">Reply</a>
                                </div><!--[if mso]></td><td style="vertical-align:middle;"><![endif]-->
                                <div class="fd-reply-time-wrap" style="display: inline-block; vertical-align: middle; color: #999999; font-size: 12px; white-space: nowrap; padding-bottom: 2px;">
                                    @if($post['distanceText'])<span style="margin-right: 8px;">{{ $post['distanceText'] }}</span>@endif{{ $post['arrivalFormatted'] }}
                                </div><!--[if mso]></td></tr></table><![endif]-->
                            </td>
                        </tr>
                    </table>
                </mj-text>
            </mj-column>
        </mj-section>
        @endforeach

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
