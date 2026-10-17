@props(['href' => '#'])
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0 24px;">
    <tr>
        <td align="center" bgcolor="#2563EB" style="border-radius:6px;">
            <a href="{{ $href }}" style="display:inline-block;padding:13px 22px;font-size:14px;line-height:20px;font-weight:600;color:#FFFFFF;text-decoration:none;border-radius:6px;">{{ $slot }}</a>
        </td>
    </tr>
</table>
