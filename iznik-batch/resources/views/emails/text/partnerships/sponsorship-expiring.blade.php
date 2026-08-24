Sponsorship renewal due
=======================

{{ $partnershipName }} ({{ $authorityName }}) ends on {{ $endDate }}, which is {{ $daysLeft }} {{ Str::plural('day', $daysLeft) }} away.

Value of the current deal: £{{ number_format($amount, 2) }}
Freegle communities covered: {{ $groupCount }}
@if($contactName || $contactEmail)

Council contact: {{ trim(($contactName ?? '') . ' ' . ($contactEmail ? '<' . $contactEmail . '>' : '')) }}
@endif

The sponsor logo and tagline stop showing to members on the day it ends, so it is worth
starting the renewal conversation now.

Manage this partnership: {{ $modToolsUrl }}
