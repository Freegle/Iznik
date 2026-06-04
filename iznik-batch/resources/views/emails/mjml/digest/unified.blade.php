<mjml>
    {{-- An immediate (-1) digest uses the full-width hero single-post layout
         (V1 single.html parity). The multi-post daily digest uses the compact
         multi-post layout below. --}}
    {{-- $accentColor defaults to the Freegle green so the daily layout (which
         spans many posts/types) has a defined button colour; the immediate
         single-post branch overrides it with the post's offer/wanted accent. --}}
    @php $isSingle = $mode === 'immediate'; $accentColor = '#338808'; @endphp
    @if($isSingle)
    @php $post = $posts->first(); $isOffer = $post['type'] === 'Offer'; $accentColor = $isOffer ? '#3c763d' : '#2196A6'; @endphp
    @include('emails.mjml.partials.head', ['preview' => $post['subject']])
    @else
    @include('emails.mjml.partials.head', ['preview' => $postCount . ' new post' . ($postCount === 1 ? '' : 's') . ' near you', 'mediaStyles' => '@media only screen and (max-width:479px){ .dpost-content { padding-top:14px !important; } }'])
    @endif

    <mj-body background-color="#f0f0f0">
        @php
            $offerColor = '#3c763d';
            $wantedColor = '#2196A6';
        @endphp

        @if($isSingle)
        {{-- ═══════════════════════════════════════════════════════════════ --}}
        {{-- SINGLE-POST: full-width hero card (immediate, or group w/ 1 post) --}}
        {{-- ═══════════════════════════════════════════════════════════════ --}}

        {{-- Hero: the item photo IS the hero (V1 single.html parity — V1's
             immediate single.mjml used a full-width image). heroImageUrl is a
             600x400 cover-crop from the delivery proxy, so the crop bounds
             the height (a tall portrait photo can't dominate). The whole
             image is clickable through to the post via href.
             Side padding (16px L/R) keeps the image off the email's left/right
             edges; flush-edge bleed looked harsh in Gmail.
             Uses the direct delivery URL (not the tracking-proxy wrapper):
             Gmail's image proxy 404'd on the cross-domain 302 from
             api→delivery. Click tracking still fires via the <a href>. --}}
        <mj-section background-color="#ffffff" padding="16px 16px 0 16px">
            <mj-column padding="0" vertical-align="top">
                <mj-image
                    href="{{ $post['messageUrl'] }}"
                    src="{{ $post['heroImageUrl'] }}"
                    alt="{{ $post['itemName'] }}"
                    padding="0"
                    border-radius="4px"
                    fluid-on-mobile="true"
                />
            </mj-column>
        </mj-section>
        {{-- Content sits below the hero --}}
        <mj-section background-color="#ffffff" padding="16px 20px 4px 20px">
            <mj-column padding="0" vertical-align="top">
                {{-- OFFER / WANTED pill --}}
                <mj-text padding="0 0 8px 0" font-size="13px">
                    <span style="display: inline-block; background-color: {{ $accentColor }}; color: #ffffff; font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 3px; letter-spacing: 0.3px;">{{ $isOffer ? 'OFFER' : 'WANTED' }}</span>
                </mj-text>
                {{-- Title --}}
                <mj-text padding="0 0 4px 0" font-size="22px" font-weight="700" color="#212529" line-height="1.25">
                    <a href="{{ $post['messageUrl'] }}" style="color: #212529; text-decoration: none;">{{ $post['itemName'] }}</a>
                </mj-text>
                {{-- Location --}}
                @if($post['locationName'])
                <mj-text padding="0 0 8px 0" font-size="13px" color="#666666">
                    {{ $post['locationName'] }}
                </mj-text>
                @endif
                {{-- The full description is rendered in its own section
                     below; no snippet here, since the immediate digest is
                     one post and a 120-char preview duplicates the body. --}}
                {{-- Distance + time row --}}
                <mj-text padding="0" font-size="12px" color="#888888">
                    @if($post['distanceText'])
                    <span style="margin-right: 12px;">&#x1F4CD; {{ $post['distanceText'] }}</span>
                    @endif
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
                    Posted by <strong style="color: #555555;">{{ \Illuminate\Support\Str::limit($post['posterName'], 40) }}</strong>@if(!empty($post['groupName'])) on <a href="{{ $post['groupUrl'] }}" style="color: #555555; text-decoration: underline;">{{ $post['groupName'] }}</a>@endif
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
        {{-- MULTI-POST: the daily "What's New" roll-up                     --}}
        {{-- ═══════════════════════════════════════════════════════════════ --}}

        {{-- Header band: logo + a horizontal thumbnail strip preview.
             Gmail's desktop view rendered this band wider than the body and
             wrapped the thumbnail strip to a second line. Trim the strip to
             6 items at 40px (was 8 at 44px) so the logo column + thumbs +
             "+N more" reliably fits in Gmail's content width. --}}
        <mj-section mj-class="bg-success" padding="12px 20px">
            <mj-column width="16%" vertical-align="middle">
                <mj-image
                    width="44px"
                    src="{{ config('freegle.branding.logo_url') }}"
                    alt="Freegle"
                    align="left"
                    padding="0"
                />
            </mj-column>
            <mj-column width="84%" vertical-align="middle">
                <mj-text padding="0" line-height="1">
                    {{-- Only thumbnail posts that actually have a photo — a
                         placeholder (blank grey) tile in the nav strip reads
                         as a broken image. --}}
                    @php $maxThumbItems = 6; $thumbPosts = collect($posts)->reject(fn($p) => $p['isPlaceholder'])->values(); @endphp
                    <table cellpadding="0" cellspacing="0" border="0" role="presentation" style="border-collapse: collapse;">
                        <tr>
                            @foreach($thumbPosts->take($maxThumbItems) as $thumbPost)
                            @php $thumbIsOffer = $thumbPost['type'] === 'Offer'; @endphp
                            <td style="padding: 0 3px 0 0; vertical-align: middle;">
                                {{-- Link to the post itself: in-email #fragment
                                     anchors don't work (Gmail strips element ids),
                                     so the thumbnail opens the post like its card. --}}
                                <a href="{{ $thumbPost['messageUrl'] }}" style="display: block; line-height: 0;">
                                    {{-- Direct delivery URL (not tracked) so Gmail's image proxy
                                         doesn't 404 on the cross-domain 302 from the tracking endpoint. --}}
                                    <img src="{{ $thumbPost['displayImageUrl'] }}" alt="{{ $thumbPost['itemName'] }}" width="40" height="40" style="display: block; width: 40px; height: 40px; object-fit: cover; border-radius: 4px; border: 2px solid {{ $thumbIsOffer ? '#6ab04c' : '#74b9ff' }};" />
                                </a>
                            </td>
                            @endforeach
                            @if($thumbPosts->count() > $maxThumbItems)
                            <td style="vertical-align: middle; padding-left: 4px;">
                                <a href="{{ $browseUrl }}" style="color: #ffffff; text-decoration: none; font-size: 12px; font-weight: bold; line-height: 1.3;">+{{ $thumbPosts->count() - $maxThumbItems }}<br/>more</a>
                            </td>
                            @endif
                        </tr>
                    </table>
                </mj-text>
            </mj-column>
        </mj-section>

        {{-- Greeting + the communities represented in this digest. The daily
             roll-up spans several groups, so name them (each links to /explore)
             — V1/Neil parity: "each of the groups featured within the email". --}}
        <mj-section background-color="#ffffff" padding="16px 20px 0 20px">
            <mj-column>
                <mj-text font-size="16px" font-weight="bold" color="#333333" padding="0 0 4px 0">
                    Hi {{ $user->firstname ?? 'there' }},
                </mj-text>
                @if(($digestGroups ?? collect())->isNotEmpty())
                <mj-text font-size="13px" color="#666666" line-height="1.5" padding="0">
                    New posts from your communities:
                    @foreach($digestGroups as $dg)@if($dg['url'])<a href="{{ $dg['url'] }}" style="color: #338808; text-decoration: underline;">{{ $dg['name'] }}</a>@else<span>{{ $dg['name'] }}</span>@endif{!! $loop->last ? '' : ' &middot; ' !!}@endforeach
                </mj-text>
                @endif
            </mj-column>
        </mj-section>

        {{-- The "What's New (N posts)" title is carried by the subject line,
             so no in-body heading here. --}}
        @foreach($posts as $index => $post)
        @php $isOffer = $post['type'] === 'Offer'; @endphp

        @if($index > 0)
        <mj-section padding="0" background-color="#ffffff">
            <mj-column>
                {{-- Generous vertical padding around the rule so consecutive
                     posts have clear breathing room rather than butting up. --}}
                <mj-divider border-color="#e9ecef" border-width="1px" padding="18px 20px" />
            </mj-column>
        </mj-section>
        @endif

        {{-- First card gets a top gap from the thumbnail band above; later
             cards get their gap from the divider's padding.
             Left/right padding (16px) on the section so the card image isn't
             flush against the email's left edge. --}}
        {{-- Image column gets vertical-align="bottom" so the photo hugs the
             same baseline as the Reply button at the bottom of the content
             column. The two columns share a table-row height (the taller of
             the two), so anchoring image-bottom and content-bottom to that
             row's bottom edge gives a visual baseline match without needing
             to know the description's wrapped line count up front. --}}
        <mj-section background-color="#ffffff" padding="{{ $index === 0 ? '16px' : '0' }} 16px 16px 16px">
            {{-- Only show the photo column when the post actually has a photo.
                 A photo-less post previously rendered a full-width blank grey
                 placeholder tile (jarring on mobile, where the columns stack
                 and it fills the screen); drop the image entirely and let the
                 content span the full width instead. --}}
            @if(!$post['isPlaceholder'])
            <mj-column width="34%" padding="0" vertical-align="bottom">
                {{-- Square thumbnail (240x240 server-side cover-crop, displayed
                     at the column's width). Square matches the typical content
                     column height closely. --}}
                <mj-image
                    href="{{ $post['messageUrl'] }}"
                    src="{{ $post['thumbImageUrl'] }}"
                    alt="{{ $post['itemName'] }}"
                    width="200px"
                    height="200px"
                    padding="0"
                    border-radius="4px"
                    fluid-on-mobile="true"
                />
            </mj-column>
            @endif
            {{-- css-class "dpost-content" carries a mobile-only top padding (see
                 mediaStyles in the head include): when the columns stack on
                 mobile it lets the OFFER/WANTED pill clear the photo above
                 instead of butting against it. Photo-less cards are full width
                 with no image above, so they don't need it. --}}
            <mj-column width="{{ $post['isPlaceholder'] ? '100%' : '66%' }}" css-class="{{ $post['isPlaceholder'] ? '' : 'dpost-content' }}" padding="0 0 0 {{ $post['isPlaceholder'] ? '0' : '14px' }}" vertical-align="top">
                <mj-text padding="0 0 6px 0" font-size="13px">
                    <span style="display: inline-block; background-color: {{ $isOffer ? $offerColor : $wantedColor }}; color: #ffffff; font-size: 12px; font-weight: 700; padding: 2px 9px; border-radius: 3px;">{{ $isOffer ? 'OFFER' : 'WANTED' }}</span>
                </mj-text>
                {{-- Title: bold, largest of the card text. --}}
                <mj-text padding="0 0 3px 0" font-size="16px" font-weight="700" color="#212529" line-height="1.25">
                    <a href="{{ $post['messageUrl'] }}" style="color: #212529; text-decoration: none;">{{ $post['itemName'] }}</a>
                </mj-text>
                {{-- User-supplied description: 14px / #333 / regular so it
                     reads as content but stays secondary to the title.
                     The inline -webkit-line-clamp:2 wrapper clamps the
                     description to two lines so every card has a uniform
                     height even when the original text wraps to four or
                     five — supported in Gmail / Apple Mail / most modern
                     email clients (others just see the truncated PHP
                     output, which still caps at ~100 chars). --}}
                @if($post['messageText'])
                <mj-text padding="0 0 8px 0" font-size="14px" color="#333333" line-height="1.5"><span style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">{{ \Illuminate\Support\Str::limit($post['messageText'], 160, '…') }}</span></mj-text>
                @endif
                {{-- Metadata: location on its own line, then distance + time on
                     the next. A long location name (e.g. a full postcode area)
                     made the old single inline "loc · dist · time" strip wrap
                     awkwardly mid-time on narrow mobile screens. --}}
                @if($post['locationName'])
                <mj-text padding="0 0 1px 0" font-size="12px" color="#888888">{{ $post['locationName'] }}</mj-text>
                @endif
                <mj-text padding="0" font-size="12px" color="#888888">
                    @if($post['distanceText'])<span style="margin-right: 12px;">&#x1F4CD; {{ $post['distanceText'] }}</span>@endif
                    <span>&#x1F552; {{ $post['arrivalFormatted'] }}</span>
                </mj-text>
                {{-- Avatar byline (V1 MultipleDigest parity). --}}
                <mj-text padding="6px 0 10px 0" font-size="12px" color="#888888">
                    <img src="{{ $post['posterAvatarUrl'] }}" alt="" width="22" height="22" style="display: inline-block; width: 22px; height: 22px; border-radius: 50%; vertical-align: middle; margin-right: 6px;" />
                    Posted by <strong style="color: #555555;">{{ \Illuminate\Support\Str::limit($post['posterName'], 30) }}</strong>@if(!empty($post['groupName'])) on <a href="{{ $post['groupUrl'] }}" style="color: #555555; text-decoration: underline;">{{ $post['groupName'] }}</a>@endif
                </mj-text>
                {{-- Reply button: stays INSIDE the content column at its
                     bottom edge so it visually pairs with the image's
                     bottom (which is bottom-aligned in the sibling column).
                     align="left" + sized inner-padding hugs the text. --}}
                <mj-button
                    href="{{ $post['messageUrl'] }}"
                    background-color="{{ $isOffer ? $offerColor : $wantedColor }}"
                    color="#ffffff"
                    font-size="13px"
                    font-weight="600"
                    inner-padding="10px 28px"
                    border-radius="4px"
                    align="left"
                    padding="0"
                >
                    Reply
                </mj-button>
            </mj-column>
        </mj-section>
        @endforeach

        <mj-section background-color="#ffffff" padding="16px 20px 20px 20px">
            <mj-column>
                <mj-divider border-color="#e9ecef" border-width="1px" padding="0 0 16px 0" />
                <mj-button href="{{ $browseUrl }}" mj-class="btn-success" font-size="16px" inner-padding="12px 40px" border-radius="4px">
                    Browse All Posts
                </mj-button>
            </mj-column>
        </mj-section>

        @endif

        {{-- Jobs near you (daily mode). V1 parity: every digest recipient gets
             nearby jobs, not just immediate. The immediate (single-post) layout
             has its own jobs block above; this covers the daily multi-post
             digest. --}}
        @if(!$isSingle && isset($jobAds) && $jobAds->isNotEmpty())
        <mj-section background-color="#F7F6EC" padding="20px 20px 10px 20px" border-top="1px solid #e9ecef">
            <mj-column>
                <mj-text font-size="16px" font-weight="bold" color="#333333" align="center" padding-bottom="10px">
                    Jobs near you
                </mj-text>
            </mj-column>
        </mj-section>
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
        @endif

        {{-- Sponsors (both modes) --}}
        @if(isset($sponsors) && $sponsors->isNotEmpty())
        <mj-section background-color="#ffffff" padding="10px 20px">
            <mj-column>
                <mj-divider border-color="#eeeeee" padding-bottom="5px" />
                <mj-text font-size="12px" color="#888888" font-style="italic" padding-bottom="5px">Sponsored by:</mj-text>
            </mj-column>
        </mj-section>
        @foreach($sponsors as $sponsor)
        <mj-section background-color="#ffffff" padding="0 20px 10px">
            <mj-column width="80px" vertical-align="middle">
                @if($sponsor->imageurl)
                <mj-image width="60px" src="{{ $sponsor->imageurl }}" alt="{{ $sponsor->name }}" href="{{ $sponsor->linkurl }}" border-radius="5px" />
                @endif
            </mj-column>
            <mj-column vertical-align="middle">
                <mj-text font-size="13px">
                    @if($sponsor->linkurl)<a href="{{ $sponsor->linkurl }}" style="color: #338808; text-decoration: none; font-weight: bold;">{{ $sponsor->name }}</a>@else<strong>{{ $sponsor->name }}</strong>@endif
                    @if($sponsor->tagline)<br /><span style="font-size: 11px; color: #666;">{{ $sponsor->tagline }}</span>@endif
                </mj-text>
            </mj-column>
        </mj-section>
        @endforeach
        @endif

        {{-- Per-group / per-frequency line (V1 single.html parity).
             Only useful in immediate mode where the email is about one
             specific group's post — daily digests cover all groups. --}}
        @if(($primaryGroupName ?? null) && ($frequencyText ?? null))
        <mj-section background-color="#f5f5f5" padding="10px 20px 0 20px">
            <mj-column>
                <mj-text font-size="11px" color="#666666" align="center" line-height="1.5">
                    You're a member of <strong>{{ $primaryGroupName }}</strong> and asked to receive updates <strong>{{ $frequencyText }}</strong>.
                </mj-text>
            </mj-column>
        </mj-section>
        @elseif(!$isSingle)
        {{-- Daily roll-up spans several groups, so name the cadence rather than
             one group. --}}
        <mj-section background-color="#f5f5f5" padding="10px 20px 0 20px">
            <mj-column>
                <mj-text font-size="11px" color="#666666" align="center" line-height="1.5">
                    You're receiving this <strong>daily</strong> digest of new posts from your Freegle communities.
                </mj-text>
            </mj-column>
        </mj-section>
        @endif

        @include('emails.mjml.partials.footer', ['email' => $user->email_preferred, 'settingsUrl' => $settingsUrl, 'unsubscribeUrl' => $unsubscribeUrl])

        @if(isset($trackingPixelMjml))
        {!! $trackingPixelMjml !!}
        @endif
    </mj-body>
</mjml>