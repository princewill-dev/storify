<x-email.layout
    title="Settings Updated"
    preheader="The following settings were changed."
    eyebrow="Settings"
    heading="Settings updated"
    badge="Updated"
    tone="info">

    <x-email.paragraph>The following settings were changed:</x-email.paragraph>

    @foreach($changes as $key => $diff)
        <x-email.panel>
            <div style="margin:0 0 8px;font-size:11px;line-height:16px;font-weight:600;letter-spacing:0.08em;text-transform:uppercase;color:#64748B;">{{ ucwords(str_replace('_', ' ', $key)) }}</div>
            @if(is_array($diff) && array_key_exists('before', $diff) && array_key_exists('after', $diff))
                <div style="margin:0 0 4px;font-size:12px;line-height:18px;color:#64748B;">Before</div>
                <div style="margin:0 0 10px;font-size:14px;line-height:20px;color:#0F172A;">
                    @if(is_array($diff['before']))
                        <pre style="margin:0;white-space:pre-wrap;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;line-height:18px;">{{ json_encode($diff['before'], JSON_PRETTY_PRINT) }}</pre>
                    @else
                        {{ $diff['before'] ?? '—' }}
                    @endif
                </div>
                <div style="margin:0 0 4px;font-size:12px;line-height:18px;color:#64748B;">After</div>
                <div style="font-size:14px;line-height:20px;color:#0F172A;">
                    @if(is_array($diff['after']))
                        <pre style="margin:0;white-space:pre-wrap;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:12px;line-height:18px;">{{ json_encode($diff['after'], JSON_PRETTY_PRINT) }}</pre>
                    @else
                        {{ $diff['after'] ?? '—' }}
                    @endif
                </div>
            @else
                <div style="font-size:14px;line-height:20px;color:#0F172A;">Changed</div>
            @endif
        </x-email.panel>
    @endforeach
</x-email.layout>
