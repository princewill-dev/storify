@props(['tone' => 'neutral'])
<span style="display:inline-block;background:{{ \App\Support\EmailTone::badgeBg($tone) }};color:{{ \App\Support\EmailTone::badgeText($tone) }};font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.04em;text-transform:uppercase;padding:3px 9px;border-radius:999px;">{{ $slot }}</span>
