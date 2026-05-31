<mjml>
    @if($mode === 'immediate')
    @php $post = $posts->first(); $isOffer = $post['type'] === 'Offer'; $accentColor = $isOffer ? '#3c763d' : '#2196A6'; @endphp
    @include('emails.mjml.partials.head', ['preview' => $post['subject']])
    @else
    @include('emails.mjml.partials.head', ['preview' => $postCount . ' new post' . ($postCount === 1 ? '' : 's') . ' near you'])
    @endif

    <mj-body background-color="#f0f0f0">
        @php
            $offerColor = '#3c763d';
            $wantedColor = '#2196A6';
        @endphp

        @if($mode === 'immediate')
        {{-- ═══════════════════════════════════════════════════════════════ --}}
        {{-- IMMEDIATE MODE: single-post card matching browse page style    --}}
        {{-- ═══════════════════════════════════════════════════════════════ --}}

        {{-- Hero: the item photo IS the hero (V1 single.html parity — V1's
             immediate single.mjml used a full-width image). heroImageUrl is a
             600x400 cover-crop from the delivery proxy, so it fills the 600px
             email width on desktop and is fluid-on-mobile, while the crop
             bounds the height (a tall portrait photo can't dominate). The
             whole image is clickable through to the post via href.
             Uses the direct delivery URL (not the tracking-proxy wrapper):
             Gmail's image proxy 404'd on the cross-domain 302 from
             api→delivery. Click tracking still fires via the <a href>. --}}
        <mj-section background-color="#e8e8e8" padding="0">
            <mj-column padding="0" vertical-align="top">
                <mj-image
                    href="{{ $post['messageUrl'] }}"
                    src="{{ $post['heroImageUrl'] }}"
                    alt="{{ $post['itemName'] }}"
                    padding="0"
                    fluid-on-mobile="true"
                    container-background-color="#e8e8e8"
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

        <mj-section mj-class="bg-success" padding="12px 20px">
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
                <mj-text padding="0" line-height="1">
                    @php $maxThumbItems = 8; @endphp
                    <table cellpadding="0" cellspacing="0" border="0" role="presentation" style="border-collapse: collapse;">
                        <tr>
                            @foreach(collect($posts)->take($maxThumbItems) as $thumbPost)
                            @php $thumbIsOffer = $thumbPost['type'] === 'Offer'; @endphp
                            <td style="padding: 0 3px 0 0; vertical-align: middle;">
                                <a href="#msg-{{ $thumbPost['message']->id }}" style="display: block; line-height: 0;">
                                    {{-- Direct delivery URL (not tracked) so Gmail's image proxy
                                         doesn't 404 on the cross-domain 302 from the tracking endpoint. --}}
                                    <img src="{{ $thumbPost['displayImageUrl'] }}" alt="{{ $thumbPost['itemName'] }}" width="44" height="44" style="display: block; width: 44px; height: 44px; object-fit: cover; border-radius: 4px; border: 2px solid {{ $thumbIsOffer ? '#6ab04c' : '#74b9ff' }};" />
                                </a>
                            </td>
                            @endforeach
                            @if(count($posts) > $maxThumbItems)
                            <td style="vertical-align: middle; padding-left: 4px;">
                                <a href="{{ $browseUrl }}" style="color: #ffffff; text-decoration: none; font-size: 12px; font-weight: bold; line-height: 1.3;">+{{ count($posts) - $maxThumbItems }}<br/>more</a>
                            </td>
                            @endif
                        </tr>
                    </table>
                </mj-text>
            </mj-column>
        </mj-section>

        @foreach($posts as $index => $post)
        @php $isOffer = $post['type'] === 'Offer'; @endphp

        @if($index > 0)
        <mj-section padding="0" background-color="#ffffff">
            <mj-column>
                <mj-divider border-color="#e9ecef" border-width="1px" padding="0 20px" />
            </mj-column>
        </mj-section>
        @endif

        <mj-section background-color="#ffffff" padding="0">
            <mj-column width="38%" padding="0" vertical-align="top">
                {{-- Direct delivery URL (not tracked) — see hero comment above
                     for why; same Gmail meips 404 applies here. --}}
                <mj-image
                    href="{{ $post['messageUrl'] }}"
                    src="{{ $post['displayImageUrl'] }}"
                    alt="{{ $post['itemName'] }}"
                    padding="0"
                    fluid-on-mobile="true"
                />
            </mj-column>
            <mj-column width="62%" padding="12px 16px 12px 12px" vertical-align="top">
                <mj-text padding="0 0 6px 0" font-size="13px">
                    <span style="display: inline-block; background-color: {{ $isOffer ? $offerColor : $wantedColor }}; color: #ffffff; font-size: 12px; font-weight: 700; padding: 2px 9px; border-radius: 3px;">{{ $isOffer ? 'OFFER' : 'WANTED' }}</span>
                </mj-text>
                <mj-text padding="0 0 3px 0" font-size="16px" font-weight="700" color="#212529" line-height="1.25">
                    <a href="{{ $post['messageUrl'] }}" style="color: #212529; text-decoration: none;">{{ $post['itemName'] }}</a>
                </mj-text>
                @if($post['locationName'])
                <mj-text padding="0 0 6px 0" font-size="12px" color="#666666">{{ $post['locationName'] }}</mj-text>
                @endif
                @if($post['messageText'])
                <mj-text padding="0 0 8px 0" font-size="12px" color="#555555" line-height="1.45">{{ \Illuminate\Support\Str::limit($post['messageText'], 80, '…') }}</mj-text>
                @endif
                <mj-text padding="0" font-size="12px" color="#888888">
                    @if($post['distanceText'])<span style="margin-right: 10px;">&#x1F4CD; {{ $post['distanceText'] }}</span>@endif
                    <span>&#x1F552; {{ $post['arrivalFormatted'] }}</span>
                </mj-text>
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
        @endif

        @include('emails.mjml.partials.footer', ['email' => $user->email_preferred, 'settingsUrl' => $settingsUrl, 'unsubscribeUrl' => $unsubscribeUrl])

        @if(isset($trackingPixelMjml))
        {!! $trackingPixelMjml !!}
        @endif
    </mj-body>
</mjml>