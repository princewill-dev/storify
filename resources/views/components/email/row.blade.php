@props(['label', 'value' => null, 'strong' => false, 'mono' => false, 'tone' => null, 'top' => false])
<tr>
    <td style="padding:6px 0;width:46%;font-size:13px;line-height:20px;color:#64748B;vertical-align:{{ $top ? 'top' : 'middle' }};">{{ $label }}</td>
    <td style="padding:6px 0;text-align:right;font-size:14px;line-height:20px;color:{{ $tone ? \App\Support\EmailTone::text($tone) : '#0F172A' }};font-weight:{{ $strong ? '600' : '400' }};{{ $mono ? 'font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:13px;' : '' }}vertical-align:{{ $top ? 'top' : 'middle' }};">{{ $value ?? $slot }}</td>
</tr>
