<mjml>
  @include('emails.mjml.partials.head', ['preview' => $count . ' chitchat post' . ($count !== 1 ? 's' : '') . ' from your members'])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.modtools-header')

    <mj-section background-color="#ffffff" padding="20px 20px 10px">
      <mj-column>
        <mj-text font-size="20px" font-weight="bold" mj-class="text-modtools">
          {{ $count }} chitchat post{{ $count !== 1 ? 's' : '' }} from your members
        </mj-text>
        <mj-text font-size="14px" color="#555555">
          Recent chitchat activity in your groups on Freegle.
        </mj-text>
      </mj-column>
    </mj-section>

    @foreach ($posts as $post)
    <mj-section background-color="#F7F6EC" padding="12px 20px">
      <mj-column>
        <mj-text font-size="11px" font-weight="bold" color="#888888" padding="0 0 4px">
          {{ strtoupper($post['label']) }}
        </mj-text>
        <mj-text font-size="14px" color="#333333" padding="0 0 6px">
          {{ $post['preview'] }}
        </mj-text>
        <mj-text font-size="11px" color="#999999" padding="0">
          {{ $post['added'] }}
        </mj-text>
      </mj-column>
      <mj-column width="110px" vertical-align="middle">
        <mj-button mj-class="btn-modtools" href="{{ $userSite }}/chitchat/{{ $post['id'] }}" font-size="13px" inner-padding="8px 14px">
          View
        </mj-button>
      </mj-column>
    </mj-section>
    <mj-section padding="0" background-color="#ffffff">
      <mj-column>
        <mj-divider border-color="#e0e0e0" border-width="1px" padding="0" />
      </mj-column>
    </mj-section>
    @endforeach

    <mj-section background-color="#ffffff" padding="10px 20px 20px">
      <mj-column>
        <mj-button href="{{ $userSite }}/chitchat" mj-class="btn-modtools" border-radius="3px" font-size="16px">
          View all chitchat
        </mj-button>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.modtools-footer', ['email' => $email])

  </mj-body>
</mjml>
