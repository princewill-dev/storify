@props(['label' => null])
<div style="margin:0 0 4px;font-size:32px;line-height:38px;font-weight:700;letter-spacing:-0.5px;color:#0B0B0E;">{{ $slot }}</div>
@if($label)
    <div style="margin:0 0 16px;font-size:12px;line-height:18px;color:#64748B;">{{ $label }}</div>
@endif
