<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Settings Updated</title>
</head>
<body>
    <h2>Settings Updated</h2>
    <p>The following settings were changed:</p>
    <ul>
        @foreach($changes as $key => $diff)
            <li>
                <strong>{{ ucwords(str_replace('_', ' ', $key)) }}:</strong>
                @if(is_array($diff) && array_key_exists('before', $diff) && array_key_exists('after', $diff))
                    <div>Before:
                        @if(is_array($diff['before']))
                            <pre style="white-space:pre-wrap">{{ json_encode($diff['before'], JSON_PRETTY_PRINT) }}</pre>
                        @else
                            {{ $diff['before'] ?? '—' }}
                        @endif
                    </div>
                    <div>After:
                        @if(is_array($diff['after']))
                            <pre style="white-space:pre-wrap">{{ json_encode($diff['after'], JSON_PRETTY_PRINT) }}</pre>
                        @else
                            {{ $diff['after'] ?? '—' }}
                        @endif
                    </div>
                @else
                    <span>Changed</span>
                @endif
            </li>
        @endforeach
    </ul>
</body>
</html>
