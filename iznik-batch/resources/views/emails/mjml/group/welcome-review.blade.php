<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Please review your welcome mail'])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.modtools-header')

    <mj-section background-color="#ffffff" padding="20px">
      <mj-column>
        <mj-text font-size="20px" font-weight="bold" mj-class="text-modtools">
          Please review: Welcome to {{ $groupName }}
        </mj-text>
        <mj-text>
          This is the welcome mail that gets sent to members who join {{ $groupName }}.
        </mj-text>
        <mj-text>
          We send you this once a year so you can check it's up-to-date. If you'd like to edit it, you can do so in ModTools under Group Settings.
        </mj-text>
        <mj-divider border-color="#eeeeee" border-width="1px" />
        <mj-text>
          {!! nl2br(e($welcomeContent)) !!}
        </mj-text>
        <mj-divider border-color="#eeeeee" border-width="1px" />
      </mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="0 20px 20px">
      <mj-column>
        <mj-button href="{{ $modSite }}/modtools/settings/" mj-class="btn-modtools" border-radius="3px" font-size="16px">
          Edit Welcome Mail
        </mj-button>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.modtools-footer', ['email' => $email])

  </mj-body>
</mjml>
