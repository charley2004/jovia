<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f1fa;font-family:Arial,Helvetica,sans-serif;color:#1c1336;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f1fa;padding:24px 12px;">
  <tr><td align="center">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;">
      <tr><td style="background:#0c0819;padding:22px 28px;">
        <img src="{{ $site }}/images/favicon-96.png" width="36" height="36" alt="" style="vertical-align:middle;border-radius:8px;">
        <span style="vertical-align:middle;margin-left:10px;color:#ffffff;font-size:20px;font-weight:bold;">Jovia Network</span>
      </td></tr>
      <tr><td style="padding:32px 28px 8px;">
        <h1 style="margin:0 0 18px;font-size:24px;line-height:1.3;color:#1c1336;">{{ $heading }}</h1>
        <p style="margin:0 0 14px;font-size:16px;line-height:1.6;">Hi {{ $firstName }},</p>
        @foreach ($lines as $line)
          <p style="margin:0 0 14px;font-size:16px;line-height:1.6;">{{ $line }}</p>
        @endforeach
      </td></tr>
      <tr><td align="center" style="padding:12px 28px 30px;">
        <a href="{{ $signInUrl }}" style="display:inline-block;background:#f5b80f;color:#1c1336;text-decoration:none;font-weight:bold;font-size:16px;padding:14px 30px;border-radius:999px;">Sign in and activate</a>
      </td></tr>
      <tr><td style="padding:0 28px 28px;font-size:14px;line-height:1.6;color:#5b5275;">
        Questions? Reply to {{ $supportEmail }} or use our <a href="{{ $helpUrl }}" style="color:#7c3aed;">contact page</a>.
        We will never ask for your password.
      </td></tr>
      <tr><td style="background:#f8f6fc;padding:18px 28px;font-size:12px;line-height:1.6;color:#8a82a3;">
        You’re receiving this because you created an account at joviaplatform.ng and haven’t activated it yet.
        <a href="{{ $unsubscribeUrl }}" style="color:#8a82a3;">Unsubscribe from these reminders</a>.
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>
