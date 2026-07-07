<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Tell us your Freegle story!'])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.header')

    <mj-section background-color="#ffffff" padding="20px">
      <mj-column>
        <mj-text font-size="20px" font-weight="bold" mj-class="text-success">
          Tell us your story!
        </mj-text>
        <mj-text>
          Dear {{ $name }},
        </mj-text>
        <mj-text>
          We love to hear why you freegle and what your experiences have been — and it helps show new freeglers what it's all about.
        </mj-text>
        <mj-text>
          Could you take a few minutes to tell us your story? If you want to attach a photo, that's good too, but you don't have to.
        </mj-text>
        <mj-button href="{{ $storiesUrl }}" mj-class="btn-success" border-radius="3px" font-size="16px">
          Tell us your story!
        </mj-button>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.footer', ['email' => $email, 'unsubscribeUrl' => $unsubscribeUrl])

  </mj-body>
</mjml>
