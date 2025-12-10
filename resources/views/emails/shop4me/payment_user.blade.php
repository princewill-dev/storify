<p>Hi {{ $requestModel->user?->name ?? 'there' }},</p>
<p>We have received your payment for SHOP4ME list <strong>{{ $requestModel->list_id }}</strong>.</p>
<p>We will begin processing your order. You can track progress here:</p>
<p><a href="{{ route('tracking.order', ['list' => $requestModel->list_id]) }}">Track your order</a></p>
<p>Thank you.</p>
