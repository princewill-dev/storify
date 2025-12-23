@extends('vendors.layout')
@section('subtitle', 'View product')

@section('content')
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h4 class="mb-0">Product: {{ $product->name }}</h4>
    <div class="d-flex gap-2">
      <a href="{{ route('vendor.products.edit', ['vendor' => $vendor, 'product' => $product, 'store_id' => request('store_id')]) }}" class="btn btn-primary btn-sm">Edit</a>
      <a href="{{ $backUrl ?? route('vendor.products.index', ['vendor' => $vendor, 'store_id' => request('store_id')]) }}" class="btn btn-light btn-sm">Back</a>
    </div>
  </div>

  <ul class="nav nav-tabs" role="tablist">
    <li class="nav-item" role="presentation">
      <button class="nav-link active" id="tab-overview" data-bs-toggle="tab" data-bs-target="#pane-overview" type="button" role="tab" aria-controls="pane-overview" aria-selected="true">Overview</button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tab-variants" data-bs-toggle="tab" data-bs-target="#pane-variants" type="button" role="tab" aria-controls="pane-variants" aria-selected="false">Variants</button>
    </li>
    <li class="nav-item" role="presentation">
      <button class="nav-link" id="tab-media" data-bs-toggle="tab" data-bs-target="#pane-media" type="button" role="tab" aria-controls="pane-media" aria-selected="false">Images & Meta</button>
    </li>
  </ul>

  <div class="tab-content pt-3">
    <div class="tab-pane fade show active" id="pane-overview" role="tabpanel" aria-labelledby="tab-overview">
      <div class="row g-3">
        <div class="col-lg-8">
          <div class="card h-100">
            <div class="card-body">
              <div class="row g-3">
                <div class="col-md-6">
                  <div class="mb-1 text-muted">Store</div>
                  <div class="fw-semibold">{{ $product->store?->name ?? '—' }}</div>
                </div>
                <div class="col-md-6">
                  @if($product->has_variants)
                    <div class="mb-1 text-muted">Price</div>
                    <div class="fw-semibold">{{ $priceInfoSymbol ?? '—' }}</div>
                  @else
                    <div class="mb-1 text-muted">Amount</div>
                    @php(
                      $sym = ($currencySymbols[$product->currency_id ?? 0]->symbol ?? '')
                    )
                    @if(!is_null($product->discount_percentage) && $product->discount_percentage > 0)
                      @php($discAmt = (float)($product->amount ?? 0) * (1 - ((float)$product->discount_percentage/100)))
                      <div class="fw-semibold">
                        <span class="text-muted text-decoration-line-through me-2">{{ $sym }}{{ number_format((float)($product->amount ?? 0), 2) }}</span>
                        <span>{{ $sym }}{{ number_format($discAmt, 2) }}</span>
                        <span class="badge bg-success ms-2">-{{ rtrim(rtrim(number_format((float)$product->discount_percentage, 2, '.', ''), '0'), '.') }}%</span>
                      </div>
                    @else
                      <div class="fw-semibold">{{ $sym }}{{ number_format((float)($product->amount ?? 0), 2) }}</div>
                    @endif
                  @endif
                </div>
                <div class="col-md-6">
                  <div class="mb-1 text-muted">Category</div>
                  <div class="fw-semibold">{{ $product->category?->name ?? '—' }}</div>
                </div>
                <div class="col-md-6">
                  <div class="mb-1 text-muted">Product Code</div>
                  <div class="fw-semibold">{{ $product->product_code }}</div>
                </div>
                <div class="col-md-6">
                  <div class="mb-1 text-muted">Slug</div>
                  <div class="fw-semibold">{{ $product->slug }}</div>
                </div>
                <div class="col-md-6">
                  <div class="mb-1 text-muted">Status</div>
                  <span class="badge bg-light text-dark">{{ $product->status }}</span>
                </div>
                <div class="col-md-6">
                  <div class="mb-1 text-muted">Featured</div>
                  <div class="fw-semibold">{{ $product->featured ? 'Yes' : 'No' }}</div>
                </div>
                <div class="col-md-6">
                  <div class="mb-1 text-muted">COD Available</div>
                  <div class="fw-semibold">{{ $product->cod_available ? 'Yes' : 'No' }}</div>
                </div>
                <div class="col-md-6">
                  <div class="mb-1 text-muted">Views</div>
                  <div class="fw-semibold">{{ number_format((int)($product->views ?? 0)) }}</div>
                </div>
                <div class="col-12">
                  <div class="mb-1 text-muted">Description</div>
                  <div class="border rounded p-2 bg-light">{!! $product->description !!}</div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h6 class="mb-0">Images</h6>
            </div>
            <div class="card-body">
              <div class="d-flex flex-wrap gap-3">
                @forelse($product->images as $img)
                  <div class="border rounded p-2" style="width:160px;">
                    <img src="{{ asset('storage/'.$img->path) }}" class="img-fluid" style="max-height:120px;object-fit:contain;" alt="">
                    @if($img->is_primary)
                      <div class="mt-2"><span class="badge bg-primary">Primary</span></div>
                    @endif
                  </div>
                @empty
                  <div class="text-muted">No images uploaded.</div>
                @endforelse
              </div>
            </div>
          </div>
        </div>
      </div>
      @unless($product->has_variants)
      <div class="card mt-3">
        <div class="card-header"><h6 class="mb-0">Pricing & Stock</h6></div>
        <div class="card-body">
          <div class="row g-3">
            <div class="col-md-4">
              <div class="mb-1 text-muted">Quantity</div>
              <div class="fw-semibold">{{ (int)($product->quantity ?? 0) }}</div>
            </div>
            <div class="col-md-4">
              <div class="mb-1 text-muted">Amount</div>
              @php(
                $sym = ($currencySymbols[$product->currency_id ?? 0]->symbol ?? '')
              )
              @if(!is_null($product->discount_percentage) && $product->discount_percentage > 0)
                @php($discAmt = (float)($product->amount ?? 0) * (1 - ((float)$product->discount_percentage/100)))
                <div class="fw-semibold">
                  <span class="text-muted text-decoration-line-through me-2">{{ $sym }}{{ number_format((float)($product->amount ?? 0), 2) }}</span>
                  <span>{{ $sym }}{{ number_format($discAmt, 2) }}</span>
                  <span class="badge bg-success ms-2">-{{ rtrim(rtrim(number_format((float)$product->discount_percentage, 2, '.', ''), '0'), '.') }}%</span>
                </div>
              @else
                <div class="fw-semibold">{{ $sym }}{{ number_format((float)($product->amount ?? 0), 2) }}</div>
              @endif
            </div>
            <div class="col-md-4">
              <div class="mb-1 text-muted">Color</div>
              <div class="fw-semibold">{{ $product->color ?? '—' }}</div>
            </div>
          </div>
        </div>
      </div>
      @endunless
    </div>

    <div class="tab-pane fade" id="pane-variants" role="tabpanel" aria-labelledby="tab-variants">
      <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
          <h6 class="mb-0">Variants</h6>
          @if($priceInfoSymbol ?? $priceInfo)
            <span class="text-muted small">Price: {{ $priceInfoSymbol ?? $priceInfo }}</span>
          @endif
        </div>
        <div class="card-body p-0">
          <div class="table-responsive" style="max-height: 55vh;">
            <table class="table table-sm mb-0">
              <thead>
                <tr>
                  <th>SKU</th>
                  <th>Size</th>
                  <th>Weight</th>
                  <th>Color</th>
                  <th class="text-end">Qty</th>
                  <th class="text-end">Amount</th>
                  <th>Status</th>
                  <th>Featured</th>
                </tr>
              </thead>
              <tbody>
                @forelse($product->variants as $v)
                <tr>
                  <td>{{ $v->sku ?? '—' }}</td>
                  <td>
                    @if(!is_null($v->size))
                      {{ rtrim(rtrim(number_format((float)$v->size, 2, '.', ''), '0'), '.') }} {{ optional(DB::table('size_units')->find($v->size_unit_id))->code }}
                    @else — @endif
                  </td>
                  <td>
                    @if(!is_null($v->weight))
                      {{ rtrim(rtrim(number_format((float)$v->weight, 2, '.', ''), '0'), '.') }} {{ optional(DB::table('weight_units')->find($v->weight_unit_id))->code }}
                    @else — @endif
                  </td>
                  <td>{{ $v->color ?? '—' }}</td>
                  <td class="text-end">{{ (int)$v->quantity }}</td>
                  <td class="text-end">{{ ($currencySymbols[$v->currency_id ?? 0]->symbol ?? '') }}{{ number_format((float)$v->amount, 2) }}</td>
                  <td><span class="badge bg-light text-dark">{{ $v->status }}</span></td>
                  <td>{{ $v->featured ? 'Yes' : 'No' }}</td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted">No variants.</td></tr>
                @endforelse
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>

    <div class="tab-pane fade" id="pane-media" role="tabpanel" aria-labelledby="tab-media">
      <div class="row g-3">
        <div class="col-lg-8">
          <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
              <h6 class="mb-0">Images</h6>
            </div>
            <div class="card-body">
              <div class="d-flex flex-wrap gap-3">
                @forelse($product->images as $img)
                  <div class="border rounded p-2" style="width:160px;">
                    <img src="{{ asset('storage/'.$img->path) }}" class="img-fluid" style="max-height:120px;object-fit:contain;" alt="">
                    @if($img->is_primary)
                      <div class="mt-2"><span class="badge bg-primary">Primary</span></div>
                    @endif
                  </div>
                @empty
                  <div class="text-muted">No images uploaded.</div>
                @endforelse
              </div>
            </div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="card h-100">
            <div class="card-header"><h6 class="mb-0">Meta</h6></div>
            <div class="card-body">
              <div class="mb-2"><span class="text-muted">Created:</span> <span class="fw-semibold">{{ $product->created_at }}</span></div>
              <div class="mb-2"><span class="text-muted">Updated:</span> <span class="fw-semibold">{{ $product->updated_at }}</span></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
@endsection
