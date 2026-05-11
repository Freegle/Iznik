<mjml>
  @include('emails.mjml.partials.head', ['preview' => 'Make your group more welcoming'])

  <mj-body background-color="#f4f4f4">

    @include('emails.mjml.components.modtools-header')

    <mj-section background-color="#ffffff" padding="20px">
      <mj-column>
        <mj-text font-size="20px" font-weight="bold" mj-class="text-modtools">
          Make {{ $groupName }} more welcoming
        </mj-text>
        <mj-text>
          You can make your Freegle group look more local and friendly for your members by customising how it appears on Freegle Direct.
        </mj-text>
        <mj-text>
          Here are some things you could add:
        </mj-text>
        <mj-text>
          {!! nl2br(e($missing)) !!}
        </mj-text>
      </mj-column>
    </mj-section>

    <mj-section background-color="#ffffff" padding="0 20px 20px">
      <mj-column>
        <mj-button href="{{ $modSite }}/modtools/settings" mj-class="btn-modtools" border-radius="3px" font-size="16px">
          Go to Group Settings
        </mj-button>
        <mj-divider border-color="#eeeeee" border-width="1px" />
        <mj-text font-size="13px" color="#666666">
          If you need help with this, please contact <a href="mailto:{{ $mentorsAddr }}">{{ $mentorsAddr }}</a>. This is an automated message sent once a month.
        </mj-text>
      </mj-column>
    </mj-section>

    @include('emails.mjml.partials.modtools-footer', ['email' => $email])

  </mj-body>
</mjml>
