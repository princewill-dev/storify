@props(['label', 'value' => null])
<tr>
    <td style="padding:0 18px 12px 0;width:40%;font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;vertical-align:top;">{{ $label }}</td>
    <td style="padding:0 0 12px;font-size:14px;line-height:20px;color:#0F172A;text-align:right;vertical-align:top;">{{ $value ?? $slot }}</td>
</tr>
