<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Council statistics ready</title>
</head>
<body style="font-family: 'Segoe UI', Arial, sans-serif; font-size: 15px; color: #222; line-height: 1.5;">
    <p>Hi,</p>

    <p>
        The council statistics for <strong>{{ $quarterLabel }}</strong> are ready.
        {{ $count }} spreadsheet{{ $count === 1 ? ' is' : 's are' }} attached to this email,
        one per council.
    </p>

    <p><strong>What to do next:</strong></p>
    <ol>
        <li>Have a quick look over the attached spreadsheets - in particular the
            user stories, in case there's one you'd rather not include.</li>
        <li>Update the quarterly template email (the dates, and the few topics you
            want to highlight this time).</li>
    </ol>

    <p>
        Once the template has been updated for {{ $quarterLabel }}, the emails will
        go out to the councils with their spreadsheets attached. If the template
        still shows a previous quarter, nothing is sent and you'll get a nudge.
    </p>

    <p>Thanks,<br>Freegle</p>
</body>
</html>
