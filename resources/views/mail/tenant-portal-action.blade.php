<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1" name="viewport">
    <title>{{ $content['subject'] }}</title>
</head>
<body style="margin:0;background:#f5f5f5;font-family:Arial,sans-serif;color:#1f2937;">
<table width="100%" cellpadding="0" cellspacing="0" style="padding:24px 0;">
    <tr>
        <td align="center">
            <table width="100%" cellpadding="0" cellspacing="0" style="max-width:620px;background:#ffffff;border-radius:18px;overflow:hidden;">
                <tr>
                    <td style="background:#0e2e32;padding:28px 32px;color:#ffffff;">
                        <h1 style="margin:0;font-size:28px;line-height:1.2;">{{ $content['title'] ?? getOption('app_name') }}</h1>
                    </td>
                </tr>
                <tr>
                    <td style="padding:32px;">
                        <p style="margin:0 0 18px;font-size:16px;line-height:1.6;">
                            {!! nl2br(e($content['message'] ?? '')) !!}
                        </p>

                        @if(!empty($content['email']))
                            <p style="margin:0 0 12px;font-size:14px;line-height:1.6;">
                                <strong>{{ __('Email') }}:</strong> {{ $content['email'] }}
                            </p>
                        @endif

                        @if(!empty($content['meta']) && is_array($content['meta']))
                            <table width="100%" cellpadding="0" cellspacing="0" style="margin:18px 0 24px;border-collapse:collapse;">
                                @foreach($content['meta'] as $label => $value)
                                    <tr>
                                        <td style="padding:8px 0;border-bottom:1px solid #e5e7eb;font-size:14px;color:#6b7280;">{{ $label }}</td>
                                        <td style="padding:8px 0;border-bottom:1px solid #e5e7eb;font-size:14px;text-align:right;color:#111827;">{{ $value }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        @endif

                        @if(!empty($content['button_url']))
                            <p style="margin:24px 0 0;">
                                <a href="{{ $content['button_url'] }}"
                                   style="display:inline-block;padding:14px 24px;background:#b43524;color:#ffffff;text-decoration:none;border-radius:999px;font-weight:700;">
                                    {{ $content['button_label'] ?? __('Open') }}
                                </a>
                            </p>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
