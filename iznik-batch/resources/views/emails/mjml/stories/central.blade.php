<mjml>
  @include('emails.mjml.partials.head', ['preview' => $previewText ?? 'Freegle Stories — please vote!'])
  <mj-body background-color="#f4f4f4">

    {{-- Header --}}
    <mj-section mj-class="bg-success" padding="0">
      <mj-column width="65%" vertical-align="middle">
        <mj-text font-size="20px" font-weight="bold" color="#ffffff" padding="15px 25px">
          Freegle Stories
        </mj-text>
      </mj-column>
      <mj-column width="35%" vertical-align="middle">
        <mj-image
          width="80px"
          src="{{ config('freegle.branding.logo_url', 'https://www.ilovefreegle.org/icon.png') }}"
          alt="Freegle"
          align="right"
          padding="15px 20px"
        />
      </mj-column>
    </mj-section>

    {{-- Intro + vote button --}}
    <mj-section background-color="#FFF8DC" padding="20px 0 0">
      <mj-column>
        <mj-text font-size="14px" color="#1D6607" line-height="1.5" padding="10px 25px">
          We mail out stories to members. Please vote for the ones you think are best for members to see. This helps improve the mail we send.
        </mj-text>
        <mj-button
          mj-class="btn-success"
          href="{{ $voteUrl }}"
          border-radius="3px"
          font-size="14px"
          align="center"
          padding="15px 25px"
        >
          Vote for stories
        </mj-button>
        <mj-divider border-color="#ccc" border-width="1px" padding="15px 25px 0" />
      </mj-column>
    </mj-section>

    {{-- Story cards --}}
    @foreach ($stories as $story)

    <mj-section background-color="#FFF8DC" padding="15px 0 0">
      <mj-column>
        <mj-text font-size="18px" font-weight="bold" mj-class="text-header" padding="0 25px 5px">
          {{ $story['headline'] }}
        </mj-text>
        @if (!empty($story['groupname']))
        <mj-text font-size="12px" color="#888888" padding="0 25px 10px">
          From a freegler on {{ $story['groupname'] }}
        </mj-text>
        @endif
      </mj-column>
    </mj-section>

    @if (!empty($story['photo']))
    <mj-section background-color="#FFF8DC" padding="0">
      <mj-column width="60%" vertical-align="top">
        <mj-text font-size="14px" color="#333333" line-height="1.6" padding="0 15px 15px 25px">
          {!! nl2br(e($story['story'])) !!}
        </mj-text>
      </mj-column>
      <mj-column width="40%" vertical-align="top">
        <mj-image
          src="{{ $story['photo'] }}"
          alt="Story photo"
          border-radius="5px"
          padding="0 25px 15px 0"
          align="right"
        />
      </mj-column>
    </mj-section>
    @else
    <mj-section background-color="#FFF8DC" padding="0">
      <mj-column>
        <mj-text font-size="14px" color="#333333" line-height="1.6" padding="0 25px 15px">
          {!! nl2br(e($story['story'])) !!}
        </mj-text>
      </mj-column>
    </mj-section>
    @endif

    <mj-section background-color="#FFF8DC" padding="0">
      <mj-column>
        <mj-divider border-color="#ccb86b" border-width="1px" padding="0 25px" />
      </mj-column>
    </mj-section>

    @endforeach

    {{-- Closing vote reminder --}}
    <mj-section background-color="#FFF8DC" padding="15px 0 20px">
      <mj-column>
        <mj-button
          mj-class="btn-success"
          href="{{ $voteUrl }}"
          border-radius="3px"
          font-size="14px"
          align="center"
        >
          Vote for stories
        </mj-button>
      </mj-column>
    </mj-section>

  </mj-body>
</mjml>
