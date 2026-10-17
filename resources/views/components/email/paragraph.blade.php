@props(['muted' => false])
<p style="margin:0 0 16px;font-size:15px;line-height:24px;color:{{ $muted ? '#64748B' : '#334155' }};">{{ $slot }}</p>
