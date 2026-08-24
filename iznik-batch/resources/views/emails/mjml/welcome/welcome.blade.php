<mjml>
  @include('emails.mjml.partials.head', ['preview' => $firstName ? 'Welcome, ' . $firstName . '! Give stuff away, browse, or ask for what you need for free.' : 'Welcome to Freegle! Give stuff away, browse, or ask for what you need for free.'])
  <mj-body>
    <mj-wrapper padding="0px" full-width="full-width">
      {{-- Welcome header --}}
      <mj-section mj-class="bg-success" padding="30px 20px">
        <mj-column>
          <mj-text font-size="36px" font-weight="bold" align="center" color="#ffffff" font-family="Georgia, serif">
            Welcome{{ $firstName ? ', ' . $firstName : '' }}!
          </mj-text>
          <mj-text font-size="18px" align="center" color="#ffffff" padding-top="10px">
            You're now part of the {{ config('freegle.branding.name') }} community
          </mj-text>
        </mj-column>
      </mj-section>

      {{-- Hero image with responsive srcset --}}
      <mj-section padding="0">
        <mj-column>
          <mj-image src="{{ $heroImage['src'] }}" srcset="{{ $heroImage['srcset'] }}" alt="Welcome to Freegle" width="600px" padding="0"></mj-image>
        </mj-column>
      </mj-section>

      @if($password)
      {{-- Password section - PROMINENT --}}
      <mj-section background-color="#fff3cd" padding="25px 20px" border="2px solid #ffc107">
        <mj-column>
          <mj-text font-size="16px" align="center" color="#856404">
            <strong>🔑 IMPORTANT: Your password</strong>
          </mj-text>
          <mj-text font-size="24px" font-weight="bold" align="center" color="#333333" padding-top="10px" font-family="Courier New, monospace">
            {{ $password }}
          </mj-text>
          <mj-text font-size="13px" align="center" color="#856404" padding-top="10px">
            Save this password - you'll need it to log in.
          </mj-text>
        </mj-column>
      </mj-section>
      @endif

      {{-- Main CTA section --}}
      <mj-section background-color="#ffffff" padding="30px 20px">
        <mj-column>
          <mj-text font-size="22px" font-weight="bold" align="center" mj-class="text-success" padding-bottom="20px">
            Ready to start freegling?
          </mj-text>
        </mj-column>
      </mj-section>

      {{-- Big CTA Buttons --}}
      <mj-section background-color="#ffffff" padding="0 20px 15px 20px">
        <mj-column>
          <mj-button href="{{ $giveUrl }}" mj-class="btn-success" font-size="18px" padding="18px 50px" width="280px">
            🎁 Give stuff away
          </mj-button>
        </mj-column>
      </mj-section>
      <mj-section background-color="#ffffff" padding="0 20px 15px 20px">
        <mj-column>
          <mj-button href="{{ $browseUrl }}" mj-class="btn-secondary" font-size="18px" padding="18px 50px" width="280px">
            👀 Browse what's on offer
          </mj-button>
        </mj-column>
      </mj-section>
      <mj-section background-color="#ffffff" padding="0 20px 30px 20px">
        <mj-column>
          <mj-button href="{{ $askUrl }}" mj-class="btn-secondary" font-size="18px" padding="18px 50px" width="280px">
            🛒 Ask for what you need
          </mj-button>
        </mj-column>
      </mj-section>

      {{-- Simple rules with icons --}}
      <mj-section mj-class="bg-green-light" padding="25px 20px 10px 20px">
        <mj-column>
          <mj-text font-size="18px" font-weight="bold" align="center" color="#333333">
            Three simple rules
          </mj-text>
        </mj-column>
      </mj-section>
      <mj-section mj-class="bg-green-light" padding="10px 20px 25px 20px">
        <mj-column width="33%">
          <mj-text align="center" padding-bottom="5px">
            <a href="{{ $termsUrl }}" style="text-decoration: none;">
              <img src="{{ $ruleFreeImage['src'] }}" srcset="{{ $ruleFreeImage['srcset'] }}" alt="Free" width="80" style="display: block; margin: 0 auto;" />
            </a>
          </mj-text>
          <mj-text align="center" padding-top="0">
            <a href="{{ $termsUrl }}" style="color: #555555; text-decoration: none;">
              <span style="font-size: 13px;">Everything must be<br/><strong>free and legal</strong></span>
            </a>
          </mj-text>
        </mj-column>
        <mj-column width="33%">
          <mj-text align="center" padding-bottom="5px">
            <a href="{{ $helpUrl }}" style="text-decoration: none;">
              <img src="{{ $ruleNiceImage['src'] }}" srcset="{{ $ruleNiceImage['srcset'] }}" alt="Be nice" width="80" style="display: block; margin: 0 auto;" />
            </a>
          </mj-text>
          <mj-text align="center" padding-top="0">
            <a href="{{ $helpUrl }}" style="color: #555555; text-decoration: none;">
              <span style="font-size: 13px;">Be <strong>nice</strong> to<br/>other freeglers</span>
            </a>
          </mj-text>
        </mj-column>
        <mj-column width="33%">
          <mj-text align="center" padding-bottom="5px">
            <a href="{{ $safetyUrl }}" style="text-decoration: none;">
              <img src="{{ $ruleSafeImage['src'] }}" srcset="{{ $ruleSafeImage['srcset'] }}" alt="Stay safe" width="80" style="display: block; margin: 0 auto;" />
            </a>
          </mj-text>
          <mj-text align="center" padding-top="0">
            <a href="{{ $safetyUrl }}" style="color: #555555; text-decoration: none;">
              <span style="font-size: 13px;">Stay <strong>safe</strong><br/>when meeting up</span>
            </a>
          </mj-text>
        </mj-column>
      </mj-section>

      {{-- Closing message --}}
      <mj-section mj-class="bg-success" padding="20px">
        <mj-column>
          <mj-text font-size="18px" font-weight="bold" align="center" color="#ffffff" font-family="Georgia, serif">
            Happy freegling! 🌱
          </mj-text>
        </mj-column>
      </mj-section>

      @include('emails.mjml.partials.footer', ['email' => $email, 'settingsUrl' => $settingsUrl])

      {{-- Tracking pixel --}}
      @if($trackingPixelMjml)
      <mj-section padding="0">
        <mj-column>
          {!! $trackingPixelMjml !!}
        </mj-column>
      </mj-section>
      @endif
    </mj-wrapper>
  </mj-body>
</mjml>
