<!doctype html>
<html><head><meta charset="utf-8"><title>{{ $title }}</title></head>
<body style="margin:0;padding:24px;background:#f4f6f9;font-family:Segoe UI,Arial,sans-serif;color:#1f2937">
<table role="presentation" width="100%" style="max-width:560px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:8px">
<tr><td style="padding:20px 24px;border-bottom:1px solid #e5e7eb;font-size:13px;color:#6b7280">{{ $business }} · LeadFlow CRM</td></tr>
<tr><td style="padding:24px">
<h1 style="font-size:18px;margin:0 0 8px">{{ $title }}</h1>
@if($body)<p style="font-size:14px;line-height:1.5;margin:0 0 20px;color:#374151">{{ $body }}</p>@endif
@if($link)<a href="{{ $link }}" style="display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:10px 16px;border-radius:6px;font-size:14px">Open in CRM</a>@endif
</td></tr>
<tr><td style="padding:16px 24px;border-top:1px solid #e5e7eb;font-size:12px;color:#9ca3af">You receive this because of your notification settings. <a href="{{ $prefs }}" style="color:#6b7280">Change preferences</a></td></tr>
</table></body></html>
