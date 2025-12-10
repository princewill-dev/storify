<p>New SHOP4ME list submitted.</p>
<p>List ID: <strong>{{ $requestModel->list_id }}</strong></p>
<p>Status: {{ $requestModel->status }}</p>
<p>Budget: {{ number_format((float)($requestModel->budget_amount ?? 0), 2) }}</p>
