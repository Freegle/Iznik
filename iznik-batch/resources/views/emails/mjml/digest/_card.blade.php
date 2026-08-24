{{-- Shared daily post card (MJML).

     Used by BOTH the live "Post cards" loop and the greyed "Came and went"
     loop so the two can never drift apart. The came-and-went variant is the
     exact same geometry — square thumb + 30/70 mj-group + mobile reflow — just
     (a) without the Reply button and (b) greyed/muted to signal the item's gone.

     Required vars (the post array from preparePosts()/prepareCard()):
       $post         — the prepared card array
       $offerColor   — DigestStyle::OFFER_GREEN
       $wantedColor  — DigestStyle::WANTED_BLUE
     Optional flags:
       $showReply    — bool, default true. When false, omit the Reply button.
       $muted        — bool, default false. When true, grey the card and use the
                       #e9e9e9 section background (the came-and-went look).

     For muted cards the meta line reads "Taken/Received · <date>" via
     $post['metaText'] instead of the live distance · arrival pairing. --}}
@php
    $isOffer = $post['type'] === 'Offer';
    $showReply = $showReply ?? true;
    $muted = $muted ?? false;

    // Muted ("came and went") uses the greyed palette the V1 $unavailable
    // section used (#777/#999 text on an #e9e9e9 band); live cards keep the
    // full-contrast palette. Driving every colour off these tokens means the
    // identical markup below renders either look from the one partial.
    $sectionBg   = $muted ? '#e9e9e9' : '#ffffff';
    $titleColor  = $muted ? '#777777' : '#212529';
    $bodyColor   = $muted ? '#999999' : '#555555';
    $metaColor   = $muted ? '#999999' : '#888888';
    $pillColor   = $muted ? '#999999' : ($isOffer ? $offerColor : $wantedColor);

    // Meta line, precomputed to avoid an inline @else@if (Blade leaves the
    // nested @if literal but compiles its @endif -> "unexpected endif").
    // Muted cards show "Taken/Received · <date>"; live cards show
    // 📍 distance · 🕒 arrival (pin omitted when there's no distance).
    $metaHtml = $muted
        ? e($post['metaText'])
        : (($post['distanceText'] ? '&#x1F4CD; ' . e($post['distanceText']) . ' &middot; ' : '') . '&#x1F552; ' . e($post['arrivalFormatted']));
@endphp
<mj-section background-color="{{ $sectionBg }}" padding="12px 20px">
    <mj-group>
        <mj-column width="30%" vertical-align="top" padding="0 12px 0 0">
            {{-- thumbImageUrl is the square (fit=cover) 240x240 crop, so
                 mj-image renders square at the rendered size. width="100%"
                 makes the image fill the column — it scales naturally as
                 the column shrinks on mobile, no fluid-on-mobile blow-up. --}}
            <mj-image
                src="{{ $post['thumbImageUrl'] }}"
                href="{{ $post['viewUrl'] }}"
                alt="{{ $post['itemName'] }}"
                width="100%"
                border-radius="4px"
                padding="0"
                @if($muted) css-class="fd-dim" @endif
            />
        </mj-column>
        <mj-column width="70%" vertical-align="top">
            <mj-text padding="0" font-size="14px" color="{{ $titleColor }}">
                <a id="msg-{{ $post['message']->id }}"></a>
                {{-- Pill on its own row, title + location below — same
                     stacking order as the AMP variant's .post-type-row /
                     .post-title block. --}}
                <div style="margin-bottom: 6px;">
                    <span style="display: inline-block; background-color: {{ $pillColor }}; color: #ffffff; font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 3px; line-height: 1; letter-spacing: 0.3px; white-space: nowrap;">{{ $isOffer ? 'OFFER' : 'WANTED' }}</span>
                </div>
                <div style="padding-bottom: 4px;">
                    <a href="{{ $post['viewUrl'] }}" style="color: {{ $titleColor }}; text-decoration: none; font-weight: 600; font-size: 16px; line-height: 1.4;">{{ $post['itemName'] }}</a>
                    @if($post['locationName'])
                    <br/><span style="color: {{ $titleColor }}; font-size: 12px; font-weight: 500;">{{ $post['locationName'] }}</span>
                    @endif
                </div>
                {{-- Desktop (in-column) copy of desc + meta + byline + Reply.
                     INLINE display:none = hidden by default, so mobile and
                     <style>-stripping clients (Gmail mobile web) fall back to
                     the full-width .fd-narrow-only copy below. The min-width
                     @media reveals this on wide screens that keep <style>. --}}
                <div class="fd-wide-only" style="display: none;">
                    @if(!empty($post['bulkSummary']) || $post['messageText'] || $post['postedToText'])
                    <div style="padding-top: 2px;">
                        @if(!empty($post['bulkSummary']))
                        <span class="fd-desc" style="color: {{ $bodyColor }}; font-size: 14px; font-weight: 400; line-height: 1.5;">{{ $post['bulkSummary'] }}</span>
                        @elseif($post['messageText'])
                        <span class="fd-desc" style="color: {{ $bodyColor }}; font-size: 14px; font-weight: 400; line-height: 1.5;">{{ \Illuminate\Support\Str::limit($post['messageText'], 100, '...') }}</span>
                        @endif
                        @if($post['postedToText'])
                        <br/><span style="color: #999999; font-size: 11px; font-style: italic;">{{ $post['postedToText'] }}</span>
                        @endif
                    </div>
                    @endif
                    <div style="margin-top: 6px; color: {{ $metaColor }}; font-size: 12px;">
                        {!! $metaHtml !!}
                    </div>
                    @if(!$muted && (!empty($post['posterName']) || !empty($post['groupName'])))
                    <div style="margin-top: 8px; color: #888888; font-size: 12px; line-height: 22px;">
                        @if(!empty($post['posterAvatarUrl']))
                        <img src="{{ $post['posterAvatarUrl'] }}" alt="" width="22" height="22" style="display: inline-block; width: 22px; height: 22px; border-radius: 50%; vertical-align: middle; margin-right: 8px;" />
                        @endif
                        @if(!empty($post['posterName']))
                        Posted by <strong style="color: #555555;">{{ \Illuminate\Support\Str::limit($post['posterName'], 30) }}</strong>
                        @endif
                        @if(!empty($post['groupName']))
                        on @if(!empty($post['groupUrl']))<a href="{{ $post['groupUrl'] }}" style="color: #555555; text-decoration: underline; font-weight: bold;">{{ $post['groupName'] }}</a>@else<strong style="color: #555555;">{{ $post['groupName'] }}</strong>@endif
                        @endif
                    </div>
                    @endif
                    @if(!$muted && !empty($post['firstPostedFormatted']))
                    <div style="margin-top: 4px; font-size: 11px; color: #999999;">
                        First posted&nbsp;{{ $post['firstPostedFormatted'] }}
                    </div>
                    @endif
                    @if($showReply && empty($post['isOwnPost']))
                    <div style="margin-top: 10px;">
                        <a href="{{ $post['messageUrl'] }}" style="display: inline-block; background-color: {{ $isOffer ? $offerColor : $wantedColor }}; color: #ffffff; font-size: 13px; font-weight: 700; padding: 9px 26px; border-radius: 4px; text-decoration: none;">Reply</a>
                    </div>
                    @endif
                </div>
            </mj-text>
        </mj-column>
    </mj-group>
</mj-section>

{{-- Mobile-only: full-width row below the 2-column block carrying
     meta + byline + first-posted + Reply. Hidden by default; the
     @media (max-width: 480px) block reveals it. Outlook desktop
     (no @media) keeps it hidden — the in-column copy above stays
     visible there. --}}
{{-- The whole reflow copy carries INLINE display:none so clients that
     strip <style> (Gmail mobile web) keep it hidden — no duplicate of
     the in-column copy. The @media block (kept only by clients that
     preserve <style>) flips it to block !important and hides the
     in-column copy instead, giving the full-width mobile reflow there.
     mj-section padding=0 + the padding on the inner div means the
     collapsed (display:none) state leaves no gap on Gmail. --}}
<mj-section background-color="{{ $sectionBg }}" padding="0">
    <mj-column>
        <mj-text padding="0" font-size="14px" color="{{ $titleColor }}">
            <div class="fd-narrow-only" style="padding: 0 20px 8px;">
                @if(!empty($post['bulkSummary']) || $post['messageText'] || $post['postedToText'])
                <div style="padding-bottom: 6px;">
                    @if(!empty($post['bulkSummary']))
                    <span class="fd-desc" style="color: {{ $bodyColor }}; font-size: 14px; font-weight: 400; line-height: 1.5;">{{ $post['bulkSummary'] }}</span>
                    @elseif($post['messageText'])
                    <span class="fd-desc" style="color: {{ $bodyColor }}; font-size: 14px; font-weight: 400; line-height: 1.5;">{{ \Illuminate\Support\Str::limit($post['messageText'], 100, '...') }}</span>
                    @endif
                    @if($post['postedToText'])
                    <br/><span style="color: #999999; font-size: 11px; font-style: italic;">{{ $post['postedToText'] }}</span>
                    @endif
                </div>
                @endif
                <div style="color: {{ $metaColor }}; font-size: 12px;">
                    {!! $metaHtml !!}
                </div>
                @if(!$muted && (!empty($post['posterName']) || !empty($post['groupName'])))
                <div style="margin-top: 8px; color: #888888; font-size: 12px; line-height: 22px;">
                    @if(!empty($post['posterAvatarUrl']))
                    <img src="{{ $post['posterAvatarUrl'] }}" alt="" width="22" height="22" style="display: inline-block; width: 22px; height: 22px; border-radius: 50%; vertical-align: middle; margin-right: 8px;" />
                    @endif
                    @if(!empty($post['posterName']))
                    Posted by <strong style="color: #555555;">{{ \Illuminate\Support\Str::limit($post['posterName'], 30) }}</strong>
                    @endif
                    @if(!empty($post['groupName']))
                    on @if(!empty($post['groupUrl']))<a href="{{ $post['groupUrl'] }}" style="color: #555555; text-decoration: underline; font-weight: bold;">{{ $post['groupName'] }}</a>@else<strong style="color: #555555;">{{ $post['groupName'] }}</strong>@endif
                    @endif
                </div>
                @endif
                @if(!$muted && !empty($post['firstPostedFormatted']))
                <div style="margin-top: 4px; font-size: 11px; color: #999999;">
                    First posted&nbsp;{{ $post['firstPostedFormatted'] }}
                </div>
                @endif
                @if($showReply)
                <div style="margin-top: 10px;">
                    <a href="{{ $post['messageUrl'] }}" style="display: inline-block; background-color: {{ $isOffer ? $offerColor : $wantedColor }}; color: #ffffff; font-size: 13px; font-weight: 700; padding: 9px 26px; border-radius: 4px; text-decoration: none;">Reply</a>
                </div>
                @endif
            </div>
        </mj-text>
    </mj-column>
</mj-section>
