@props(['tone' => 'info', 'title' => null])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;background:{{ \App\Support\EmailTone::bg($tone) }};border:1px solid {{ \App\Support\EmailTone::border($tone) }};border-radius:10px;">
    <tr>
        <td style="padding:14px 16px;font-size:14px;line-height:22px;color:{{ \App\Support\EmailTone::text($tone) }};">
            @if($title)
                <div style="margin:0 0 4px;font-weight:600;">{{ $title }}</div>
            @endif
            {{ $slot }}
        </td>
    </tr>
</table>
