<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Thanks for putting up a poster!'])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.header')

    <mj-section background-color="#ffffff" padding="20px">
      <mj-column>
        <mj-text font-size="20px" font-weight="bold" mj-class="text-success">
          Thanks for putting up a poster!
        </mj-text>
        <mj-text>
          That's really helpful — we can't afford expensive advertising!
        </mj-text>
        <mj-text>
          Putting up posters and telling everyone about Freegle really helps in getting the word out there and reducing the amount of waste.
        </mj-text>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.footer', ['email' => $email])

  </mj-body>
</mjml>
