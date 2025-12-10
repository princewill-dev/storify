<p>There is an update to your SHOP4ME list <strong>{{ $requestModel->list_id }}</strong>.</p>
<p><strong>Item:</strong> {{ $item->product?->name ?? ($item->name ?? 'N/A') }}</p>
<p><strong>Type:</strong> {{ $responseModel->type }}</p>
<p><strong>Message:</strong></p>
<p>{{ $responseModel->message }}</p>
@if(!empty($responseModel->meta['price_delta']))
<p><strong>Price Change:</strong> {{ number_format((float)$responseModel->meta['price_delta'], 2) }}</p>
@endif
@if(!empty($responseModel->meta['suggested_name']))
<p><strong>Suggested Replacement:</strong> {{ $responseModel->meta['suggested_name'] }}</p>
@endif
<p>Please review your list and respond in your account.</p>
