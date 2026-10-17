@props(['padding' => '20px'])
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;background:#F6F7FB;border:1px solid #E5E7EB;border-radius:10px;border-collapse:separate;">
    <tr>
        <td style="padding:{{ $padding }};">{{ $slot }}</td>
    </tr>
</table>
