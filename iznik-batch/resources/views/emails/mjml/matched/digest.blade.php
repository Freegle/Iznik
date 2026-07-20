<mjml>
  @include('emails.mjml.partials.head', ['preview' => ($count === 1 ? 'A post near you matching what you posted' : $count . ' posts near you matching what you posted')])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.header')

    <mj-section background-color="#ffffff" padding="20px 20px 6px">
      <mj-column>
        <mj-text font-size="20px" font-weight="bold" color="#333333" padding="0 0 6px">
          @if($count === 1)
            A post near you matching what you posted
          @else
            {{ $count }} posts near you matching what you posted
          @endif
        </mj-text>
        <mj-text font-size="14px" color="#555555" padding="0">
          Based on what you've offered or asked for, here's what freeglers nearby have posted. Reply to anything that takes your fancy.
        </mj-text>
      </mj-column>
    </mj-section>

    @foreach ($posts as $post)
      @include('emails.mjml.matched._card', ['post' => $post, 'offerColor' => $offerColor, 'wantedColor' => $wantedColor, 'hero' => $count === 1])
    @endforeach

    <mj-section background-color="#ffffff" padding="14px 20px 20px">
      <mj-column>
        <mj-button href="{{ $browseUrl }}" background-color="#338808" border-radius="4px" font-size="16px" font-weight="700">
          See more on Freegle
        </mj-button>
      </mj-column>
    </mj-section>

    <mj-section background-color="#f4f4f4" padding="4px 20px 0">
      <mj-column>
        <mj-text font-size="12px" color="#999999" align="center" padding="0">
          Don't want these suggestions? <a href="{{ $optOutUrl }}" style="color: #999999; text-decoration: underline;">Turn off matched-posts emails</a>.
        </mj-text>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.footer', ['email' => $email, 'settingsUrl' => $settingsUrl])

  </mj-body>
</mjml>
