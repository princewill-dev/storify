<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light only">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $title ?? $heading ?? $brandName }}</title>
    <style>
        @media only screen and (max-width: 620px) {
            .email-card { width: 100% !important; border-radius: 0 !important; border-left: 0 !important; border-right: 0 !important; }
            .email-pad { padding-left: 20px !important; padding-right: 20px !important; }
            .email-body { padding: 24px 20px !important; }
            .email-outer { padding: 16px 0 !important; }
        }
        a { color: #2563EB; }
    </style>
</head>
<body style="margin:0;padding:0;background:#F2F4F7;">
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;color:#F2F4F7;">{{ $preheader }}</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F2F4F7;">
    <tr>
        <td align="center" class="email-outer" style="padding:32px 12px;">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" class="email-card" style="width:600px;max-width:600px;background:#FFFFFF;border:1px solid #E5E7EB;border-radius:12px;overflow:hidden;">
                <tr>
                    <td style="height:5px;line-height:5px;font-size:0;background:#2563EB;">&nbsp;</td>
                </tr>
                <tr>
                    <td class="email-pad" style="padding:24px 32px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td align="left" style="vertical-align:middle;">
                                    @if($brandLogo)
                                        <img src="{{ $brandLogo }}" alt="{{ $brandName }}" style="display:block;height:26px;max-height:26px;width:auto;border:0;outline:none;text-decoration:none;">
                                    @else
                                        <span style="font-size:18px;line-height:24px;font-weight:700;color:#0B0B0E;">{{ $brandName }}</span>
                                    @endif
                                </td>
                                @if($badge)
                                    <td align="right" style="vertical-align:middle;">
                                        <span style="display:inline-block;background:{{ \App\Support\EmailTone::badgeBg($tone) }};color:{{ \App\Support\EmailTone::badgeText($tone) }};font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;padding:5px 10px;border-radius:999px;">{{ $badge }}</span>
                                    </td>
                                @endif
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="border-top:1px solid #E5E7EB;line-height:0;font-size:0;">&nbsp;</td>
                </tr>
                <tr>
                    <td class="email-body" style="padding:32px;">
                        @if($eyebrow)
                            <div style="margin:0 0 10px;font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;">{{ $eyebrow }}</div>
                        @endif
                        @if($heading)
                            <h1 style="margin:0 0 8px;font-size:30px;line-height:36px;font-weight:700;letter-spacing:-0.6px;color:#0B0B0E;mso-line-height-rule:exactly;">{{ $heading }}</h1>
                        @endif
                        @if($subtitle)
                            <p style="margin:0 0 24px;font-size:14px;line-height:22px;color:#64748B;">{{ $subtitle }}</p>
                        @endif
                        {{ $slot }}
                    </td>
                </tr>
                <tr>
                    <td class="email-pad" style="padding:24px 32px;border-top:1px solid #E5E7EB;">
                        <div style="font-size:12px;line-height:18px;color:#64748B;">
                            <div style="font-weight:600;color:#334155;">{{ $brandName }}</div>
                            @if($brandEmail)
                                <div>
                                    <a href="mailto:{{ $brandEmail }}" style="color:#2563EB;text-decoration:none;">{{ $brandEmail }}</a>@if($brandPhone) &middot; {{ $brandPhone }}@endif
                                </div>
                            @elseif($brandPhone)
                                <div>{{ $brandPhone }}</div>
                            @endif
                            @if($brandAddress)
                                <div>{{ $brandAddress }}</div>
                            @endif
                            @if($brandBranchAddress)
                                <div>{{ $brandBranchAddress }}</div>
                            @endif
                        </div>
                        @if($poweredBy)
                            <div style="margin-top:14px;font-size:12px;line-height:18px;color:#64748B;">
                                Powered by <a href="{{ $platformUrl }}" style="color:#2563EB;text-decoration:none;">{{ $platformName }}</a>
                            </div>
                        @endif
                        <div style="margin-top:14px;font-size:12px;line-height:18px;color:#94A3B8;">
                            {{ $footerCopy }}
                        </div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
