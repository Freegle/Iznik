<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject }}</title>
    <style>
        body { font-family: Arial, sans-serif; font-size: 14px; line-height: 1.5; color: #333; background: #f7f5eb; margin: 0; padding: 20px; }
        .wrapper { max-width: 600px; margin: 0 auto; background: #fff; padding: 20px; }
        .logo { text-align: center; margin-bottom: 20px; }
        .logo img { width: 100px; height: 100px; border-radius: 3px; }
        .content { padding: 10px 0; }
        .footer { border-top: 1px solid #ddd; margin-top: 20px; padding-top: 10px; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="wrapper">
        <div class="logo">
            <a href="{{ config('freegle.sites.user') }}">
                <img src="{{ config('freegle.images.logo') }}" alt="{{ config('freegle.branding.name') }}" width="100" height="100">
            </a>
        </div>
        <h2>{{ $subject }}</h2>
        <div class="content">
            {!! $htmlBody !!}
        </div>
@if ($textBody)
        <div class="footer">
            <p>{{ $textBody }}</p>
        </div>
@endif
    </div>
</body>
</html>
