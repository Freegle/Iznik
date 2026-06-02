<mjml>
  @include('emails.mjml.partials.head', [
    'preview' => 'Donations needing thanks: ' . count($cards) . ' — £' . number_format($total, 2),
    'styles'  => '
      .card { background: #ffffff; border: 1px solid #e6e6e6; border-radius: 6px; }
      .card-header { background: #f7f7f7; border-bottom: 1px solid #e6e6e6; }
      .card-header td { padding: 10px 14px; }
      .card-body td { padding: 4px 14px; font-size: 14px; line-height: 1.4; vertical-align: top; }
      .card-label { color: #666; width: 130px; white-space: nowrap; }
      .card-section-head { color: #444; font-weight: bold; padding: 12px 14px 4px; border-top: 1px solid #f0f0f0; }
      .flag-pill { display: inline-block; background: #fff3cd; color: #856404; border: 1px solid #ffeeba; padding: 1px 8px; border-radius: 10px; font-size: 12px; margin-right: 4px; }
      .flag-pill-good { background: #d4edda; color: #155724; border-color: #c3e6cb; }
      .flag-pill-warn { background: #f8d7da; color: #721c24; border-color: #f5c6cb; }
      .mini-row td { padding: 2px 14px; font-size: 13px; color: #555; }
      .link-row td { padding: 8px 14px; font-size: 12px; }
      .link-row a { margin-right: 12px; }
    ',
    'mediaStyles' => '
      @media only screen and (max-width: 480px) {
        .card-body td { padding-left: 8px !important; padding-right: 8px !important; font-size: 13px !important; }
        .card-label { width: 90px !important; font-size: 12px !important; }
      }
    ',
  ])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.modtools-header')

    <mj-section background-color="#ffffff" padding="20px 10px 6px">
      <mj-column>
        <mj-text font-size="20px" font-weight="bold" mj-class="text-modtools" padding="0 10px">
          Donations needing thanks: {{ count($cards) }} — total £{{ number_format($total, 2) }}
        </mj-text>
        <mj-text padding="0 10px 6px" font-size="13px" color="#666">
          Each card has the data you'd otherwise pull together by hand: donor identity,
          donation history, Gift Aid status, group memberships, recent mod notes, member↔mod
          chat snippets, and links into the relevant Modtools pages. Each donation appears
          in exactly one digest — it won't be repeated tomorrow, so please action every card
          before this email scrolls away.
        </mj-text>
      </mj-column>
    </mj-section>

    @foreach ($cards as $card)
      @php
        $d = $card['donation'];
        $u = $card['user'];
        $ga = $card['giftaid'];
        $hist = $card['donationHistory'];
        $mods = $card['modNotes'];
        $chats = $card['modChats'];
        $members = $card['memberships'];
        $aliases = $card['aliases'];
        $flags = $card['flags'];
        $links = $card['links'];

        $name = $u['displayName'] ?? ($d['payerName'] ?: $d['payer'] ?: 'Unknown donor');
        $amountFmt = '£' . number_format($d['amount'], 2);

        if ($ga) {
          if ($ga['declined']) {
            $gaLabel = 'On file: Declined';
            $gaClass = 'flag-pill-warn';
          } elseif ($ga['reviewed']) {
            $gaLabel = 'Active — ' . $ga['period'];
            $gaClass = 'flag-pill-good';
          } else {
            $gaLabel = 'Just declared — needs review (' . $ga['period'] . ')';
            $gaClass = '';
          }
        } else {
          $gaLabel = 'Never asked / not on file';
          $gaClass = 'flag-pill-warn';
        }
      @endphp

      <mj-section background-color="#f4f4f4" padding="10px 10px 0">
        <mj-column css-class="card">
          {{-- HEADER --}}
          <mj-raw>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="card-header">
              <tr>
                <td style="text-align:left;">
                  <div style="font-size:18px;font-weight:bold;color:#222;">
                    {{ $amountFmt }} &nbsp;to&nbsp; {{ $name }}
                  </div>
                  <div style="font-size:13px;color:#555;margin-top:2px;">
                    at {{ $d['time'] }} &nbsp;via&nbsp; {{ $d['source'] }}@if ($d['transaction']) &nbsp;&middot;&nbsp; ref <span style="font-family:monospace;font-size:12px;">{{ $d['transaction'] }}</span>@endif
                  </div>
                </td>
                <td style="text-align:right;font-size:12px;">
                  @foreach ($flags as $f)
                    <span class="flag-pill">{{ $f }}</span>
                  @endforeach
                  @if ($card['birthdayHint'])
                    <span class="flag-pill flag-pill-good">Birthday?</span>
                  @endif
                </td>
              </tr>
            </table>
          </mj-raw>

          {{-- IDENTITY --}}
          <mj-raw>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="card-body">
              <tr><td colspan="2" class="card-section-head">Donor</td></tr>
              <tr>
                <td class="card-label">Payer (bank)</td>
                <td>{{ $d['payer'] }}@if ($d['payerName'] && $d['payerName'] !== $d['payer']) &nbsp;<span style="color:#888;">(display: {{ $d['payerName'] }})</span>@endif</td>
              </tr>
              @if ($u)
                <tr>
                  <td class="card-label">Member</td>
                  <td>
                    {{ $u['displayName'] }}
                    &nbsp;<a href="{{ $links['modtoolsUser'] ?? '#' }}" style="font-size:12px;">#{{ $u['id'] }}</a>
                    @if ($u['deleted']) <span class="flag-pill flag-pill-warn">Deleted user</span>@endif
                    @if ($u['systemrole'] !== 'User') <span class="flag-pill">{{ $u['systemrole'] }}</span>@endif
                  </td>
                </tr>
                @if (!empty($aliases))
                  <tr>
                    <td class="card-label">Email</td>
                    <td style="word-break:break-all;">{{ $aliases[0] }}</td>
                  </tr>
                @endif
                @if ($u['added'])
                  <tr>
                    <td class="card-label">Member since</td>
                    <td>{{ $u['added']->format('M Y') }} ({{ $u['added']->diffForHumans(null, true) }} ago)</td>
                  </tr>
                @endif
              @else
                <tr>
                  <td class="card-label">Member</td>
                  <td><span class="flag-pill flag-pill-warn">Unmatched</span> — sleuthing required (PayPal/Stripe email may differ from Freegle email)</td>
                </tr>
              @endif
            </table>
          </mj-raw>

          {{-- GIFT AID --}}
          <mj-raw>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="card-body">
              <tr><td colspan="2" class="card-section-head">Gift Aid</td></tr>
              <tr>
                <td class="card-label">Status</td>
                <td><span class="flag-pill {{ $gaClass }}">{{ $gaLabel }}</span></td>
              </tr>
              @if ($ga && !$ga['declined'])
                <tr>
                  <td class="card-label">Postcode / house</td>
                  <td>
                    @if ($ga['postcode']) {{ $ga['postcode'] }}@else <span style="color:#a00;">missing</span>@endif
                    &nbsp;/&nbsp;
                    @if ($ga['housenameornumber']) {{ $ga['housenameornumber'] }}@else <span style="color:#a00;">missing</span>@endif
                  </td>
                </tr>
              @endif
              <tr>
                <td class="card-label">This donation</td>
                <td style="color:#555;">
                  @if ($d['giftaidClaimed'])
                    Claimed {{ $d['giftaidClaimed']->format('j M Y') }}
                  @elseif ($d['giftaidConsent'])
                    Consent on file, claim pending
                  @else
                    No consent yet
                  @endif
                </td>
              </tr>
            </table>
          </mj-raw>

          {{-- DONATION HISTORY --}}
          @if (!empty($hist))
            <mj-raw>
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="card-body">
                <tr><td colspan="3" class="card-section-head">Previous donations ({{ count($hist) }} shown)</td></tr>
                @foreach ($hist as $h)
                  <tr class="mini-row">
                    <td style="width:90px;">{{ $h['date'] }}</td>
                    <td style="width:80px;"><b>£{{ number_format($h['amount'], 2) }}</b></td>
                    <td style="color:#666;">{{ $h['source'] }}@if ($h['thanked']) &nbsp;&middot;&nbsp;<span style="color:#155724;">thanked {{ $h['thanked']->format('j M Y') }}</span>@endif</td>
                  </tr>
                @endforeach
              </table>
            </mj-raw>
          @endif

          {{-- MEMBERSHIPS --}}
          @if (!empty($members))
            <mj-raw>
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="card-body">
                <tr><td colspan="2" class="card-section-head">Groups ({{ count($members) }})</td></tr>
                @foreach ($members as $m)
                  <tr class="mini-row">
                    <td>{{ $m['name'] }} @if ($m['role'] !== 'Member') <span class="flag-pill">{{ $m['role'] }}</span>@endif</td>
                    <td style="color:#888;text-align:right;width:120px;">since {{ $m['memberSince'] }}</td>
                  </tr>
                @endforeach
              </table>
            </mj-raw>
          @endif

          {{-- MOD NOTES --}}
          @if (!empty($mods))
            <mj-raw>
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="card-body">
                <tr><td colspan="2" class="card-section-head">Mod notes ({{ count($mods) }})</td></tr>
                @foreach ($mods as $n)
                  <tr class="mini-row">
                    <td style="width:90px;color:#888;">{{ $n['date'] }}</td>
                    <td>@if ($n['flag']) <span class="flag-pill flag-pill-warn">Flagged</span> @endif{{ $n['snippet'] }}</td>
                  </tr>
                @endforeach
              </table>
            </mj-raw>
          @endif

          {{-- MOD CHATS --}}
          @if (!empty($chats))
            <mj-raw>
              <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="card-body">
                <tr><td colspan="2" class="card-section-head">Recent member↔mod chat</td></tr>
                @foreach ($chats as $c)
                  <tr class="mini-row">
                    <td style="width:90px;color:#888;">{{ $c['date'] }}</td>
                    <td>
                      <i style="color:#888;font-size:12px;">{{ $c['fromMember'] ? 'Member' : 'Mod' }}:</i>
                      {{ $c['snippet'] }}
                    </td>
                  </tr>
                @endforeach
              </table>
            </mj-raw>
          @endif

          {{-- QUICK LINKS --}}
          <mj-raw>
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" class="link-row">
              <tr><td>
                @if (!empty($links['modtoolsUser']))<a href="{{ $links['modtoolsUser'] }}">Open in Modtools support</a>@endif
                <a href="{{ $links['giftaidRegister'] }}">Gift Aid register</a>
              </td></tr>
            </table>
          </mj-raw>
        </mj-column>
      </mj-section>
    @endforeach

    @include('emails.mjml.partials.modtools-footer', ['email' => $email])

  </mj-body>
</mjml>
