{{-- One matched-post card for the matched-posts email.

     Required vars:
       $post         — prepared card array from MatchedPosts::prepareCard()
       $offerColor   — DigestStyle::OFFER_GREEN
       $wantedColor  — DigestStyle::WANTED_BLUE
     Optional:
       $hero         — bool, default false. Single-match layout: full-width photo
                       on top, details below. Otherwise a compact image-left card. --}}
@php
    $isOffer = $post['type'] === 'Offer';
    $hero = $hero ?? false;
    $accent = $isOffer ? $offerColor : $wantedColor;
    // "Matches your wanted: bike" — reasonType is the recipient's own post type
    // (always the opposite of this card's type).
    $reasonLabel = 'Matches your ' . strtolower($post['reasonType']) . ': ' . $post['reasonItem'];
    $metaHtml = ($post['distanceText'] ? '&#x1F4CD; ' . e($post['distanceText']) . ' &middot; ' : '')
        . ($post['groupName'] ? 'on ' . e($post['groupName']) : '');
@endphp

{{-- Reason strip: why this post is in the email. --}}
<mj-section background-color="#f0f7ea" padding="10px 20px 0">
    <mj-column>
        <mj-text font-size="12px" color="{{ $accent }}" font-weight="700" padding="0">
            {{ $reasonLabel }}
        </mj-text>
    </mj-column>
</mj-section>

@if($hero)
<mj-section background-color="#ffffff" padding="8px 20px 4px">
    <mj-column>
        <mj-image src="{{ $post['heroImageUrl'] }}" href="{{ $post['viewUrl'] }}" alt="{{ $post['itemName'] }}" border-radius="6px" padding="0" />
    </mj-column>
</mj-section>
<mj-section background-color="#ffffff" padding="8px 20px 16px">
    <mj-column>
        <mj-text padding="0">
            <div style="margin-bottom: 6px;">
                <span style="display: inline-block; background-color: {{ $accent }}; color: #ffffff; font-size: 12px; font-weight: 700; padding: 4px 10px; border-radius: 3px; line-height: 1; letter-spacing: 0.3px;">{{ $isOffer ? 'OFFER' : 'WANTED' }}</span>
            </div>
            <a href="{{ $post['viewUrl'] }}" style="color: #212529; text-decoration: none; font-weight: 600; font-size: 18px; line-height: 1.4;">{{ $post['itemName'] }}</a>
            @if($post['locationName'])
            <br/><span style="color: #212529; font-size: 13px; font-weight: 500;">{{ $post['locationName'] }}</span>
            @endif
            <div style="margin-top: 6px; color: #888888; font-size: 12px;">{!! $metaHtml !!}</div>
        </mj-text>
        <mj-button href="{{ $post['messageUrl'] }}" background-color="{{ $accent }}" color="#ffffff" border-radius="4px" font-size="14px" font-weight="700" align="left" padding="12px 0 0">
            Reply
        </mj-button>
    </mj-column>
</mj-section>
@else
<mj-section background-color="#ffffff" padding="6px 20px 14px">
    <mj-group>
        <mj-column width="32%" vertical-align="top" padding="0 12px 0 0">
            <mj-image src="{{ $post['thumbImageUrl'] }}" href="{{ $post['viewUrl'] }}" alt="{{ $post['itemName'] }}" width="100%" border-radius="4px" padding="0" />
        </mj-column>
        <mj-column width="68%" vertical-align="top">
            <mj-text padding="0" color="#212529">
                <div style="margin-bottom: 6px;">
                    <span style="display: inline-block; background-color: {{ $accent }}; color: #ffffff; font-size: 11px; font-weight: 700; padding: 3px 9px; border-radius: 3px; line-height: 1; letter-spacing: 0.3px; white-space: nowrap;">{{ $isOffer ? 'OFFER' : 'WANTED' }}</span>
                </div>
                <a href="{{ $post['viewUrl'] }}" style="color: #212529; text-decoration: none; font-weight: 600; font-size: 16px; line-height: 1.3;">{{ $post['itemName'] }}</a>
                @if($post['locationName'])
                <br/><span style="color: #212529; font-size: 12px; font-weight: 500;">{{ $post['locationName'] }}</span>
                @endif
                <div style="margin-top: 6px; color: #888888; font-size: 12px;">{!! $metaHtml !!}</div>
                <div style="margin-top: 10px;">
                    <a href="{{ $post['messageUrl'] }}" style="display: inline-block; background-color: {{ $accent }}; color: #ffffff; font-size: 13px; font-weight: 700; padding: 8px 24px; border-radius: 4px; text-decoration: none;">Reply</a>
                </div>
            </mj-text>
        </mj-column>
    </mj-group>
</mj-section>
@endif

<mj-section padding="0" background-color="#ffffff">
    <mj-column>
        <mj-divider border-color="#ececec" border-width="1px" padding="0 20px" />
    </mj-column>
</mj-section>
