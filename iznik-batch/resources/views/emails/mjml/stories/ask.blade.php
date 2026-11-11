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
          We love to hear why you freegle and the difference it has made - what you've given or received, what it saved, who you've met. It helps show new freeglers what it's all about.
        </mj-text>
        <mj-text>
          Could you take a few minutes to tell us your story?
        </mj-text>
        <mj-text>
          Please add a photo if you can: the item, where it ended up, or just a selfie. Stories with a photo are the ones people stop and read, and the ones we can share to get more people freegling.
        </mj-text>
        <mj-button href="{{ $storiesUrl }}" mj-class="btn-success" border-radius="3px" font-size="16px">
          Tell us your story!
        </mj-button>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.footer', ['email' => $email, 'unsubscribeUrl' => $unsubscribeUrl])

  </mj-body>
</mjml>
