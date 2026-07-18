@extends('admin.layout')
@section('subtitle', 'View product')

@section('content')
<div class="flex items-center justify-between mb-6">
  <h2 class="text-lg font-bold text-slate-900">Product: {{ $product->name }}</h2>
  <div class="flex items-center gap-2">
    <a href="{{ route('admin.products.edit', $product) }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg bg-slate-900 text-white hover:bg-slate-800">Edit</a>
    <a href="{{ $backUrl ?? route('admin.products.index') }}" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50">Back</a>
  </div>
</div>

<div class="flex border-b border-slate-200 mb-4">
  <button onclick="switchProductTab('overview')" data-tab="overview" class="tab-btn px-4 py-2.5 text-sm font-medium border-b-2 border-slate-900 text-slate-900 -mb-px">Overview</button>
  <button onclick="switchProductTab('variants')" data-tab="variants" class="tab-btn px-4 py-2.5 text-sm font-medium border-b-2 border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300 -mb-px">Variants</button>
  <button onclick="switchProductTab('media')" data-tab="media" class="tab-btn px-4 py-2.5 text-sm font-medium border-b-2 border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300 -mb-px">Images &amp; Meta</button>
</div>

<div id="tab-overview" class="tab-panel">
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2">
      <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="p-6">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <div class="text-xs text-slate-500 mb-1">Store</div>
              <div class="text-sm font-semibold text-slate-900">{{ $product->store?->name ?? '—' }}</div>
            </div>
            <div>
              @if($product->has_variants)
                <div class="text-xs text-slate-500 mb-1">Price</div>
                <div class="text-sm font-semibold text-slate-900">{{ $priceInfoSymbol ?? '—' }}</div>
              @else
                <div class="text-xs text-slate-500 mb-1">Amount</div>
                @php(
                  $sym = ($currencySymbols[$product->currency_id ?? 0]->symbol ?? '')
                )
                @if(!is_null($product->discount_percentage) && $product->discount_percentage > 0)
                  @php($discAmt = (float)($product->amount ?? 0) * (1 - ((float)$product->discount_percentage/100)))
                  <div class="text-sm font-semibold text-slate-900">
                    <span class="text-slate-400 line-through mr-2">{{ $sym }}{{ number_format((float)($product->amount ?? 0), 2) }}</span>
                    <span>{{ $sym }}{{ number_format($discAmt, 2) }}</span>
                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ml-2">-{{ rtrim(rtrim(number_format((float)$product->discount_percentage, 2, '.', ''), '0'), '.') }}%</span>
                  </div>
                @else
                  <div class="text-sm font-semibold text-slate-900">{{ $sym }}{{ number_format((float)($product->amount ?? 0), 2) }}</div>
                @endif
              @endif
            </div>
            <div>
              <div class="text-xs text-slate-500 mb-1">Category</div>
              <div class="text-sm font-semibold text-slate-900">{{ $product->category?->name ?? '—' }}</div>
            </div>
            <div>
              <div class="text-xs text-slate-500 mb-1">Product Code</div>
              <div class="text-sm font-semibold text-slate-900">{{ $product->product_code }}</div>
            </div>
            <div>
              <div class="text-xs text-slate-500 mb-1">Slug</div>
              <div class="text-sm font-semibold text-slate-900">{{ $product->slug }}</div>
            </div>
            <div>
              <div class="text-xs text-slate-500 mb-1">Status</div>
              <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">{{ $product->status }}</span>
            </div>
            <div>
              <div class="text-xs text-slate-500 mb-1">Featured</div>
              <div class="text-sm font-semibold text-slate-900">{{ $product->featured ? 'Yes' : 'No' }}</div>
            </div>
            <div>
              <div class="text-xs text-slate-500 mb-1">COD Available</div>
              <div class="text-sm font-semibold text-slate-900">{{ $product->cod_available ? 'Yes' : 'No' }}</div>
            </div>
            <div>
              <div class="text-xs text-slate-500 mb-1">Views</div>
              <div class="text-sm font-semibold text-slate-900">{{ number_format((int)($product->views ?? 0)) }}</div>
            </div>
            <div class="md:col-span-2">
              <div class="text-xs text-slate-500 mb-1">Description</div>
              <div class="border border-slate-200 rounded-lg p-3 bg-slate-50 text-sm text-slate-700">{!! strip_tags($product->description, '<p><b><i><strong><em><br><ul><ol><li><a>') !!}</div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <div class="lg:col-span-1">
      <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
          <h3 class="text-sm font-semibold text-slate-900">Images</h3>
        </div>
        <div class="p-6">
          <div class="flex flex-wrap gap-3">
            @forelse($product->images as $img)
              <div class="border border-slate-200 rounded-lg p-2 w-[160px]">
                <img src="{{ asset('storage/'.$img->path) }}" class="w-full max-h-[120px] object-contain" alt="">
                @if($img->is_primary)
                  <div class="mt-2"><span class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-blue-700">Primary</span></div>
                @endif
              </div>
            @empty
              <div class="text-sm text-slate-500">No images uploaded.</div>
            @endforelse
          </div>
        </div>
      </div>
    </div>
  </div>

  @unless($product->has_variants)
  <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden mt-6">
    <div class="px-6 py-4 border-b border-slate-100">
      <h3 class="text-sm font-semibold text-slate-900">Pricing &amp; Stock</h3>
    </div>
    <div class="p-6">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div>
          <div class="text-xs text-slate-500 mb-1">Quantity</div>
          <div class="text-sm font-semibold text-slate-900">{{ (int)($product->quantity ?? 0) }}</div>
        </div>
        <div>
          <div class="text-xs text-slate-500 mb-1">Amount</div>
          @php(
            $sym = ($currencySymbols[$product->currency_id ?? 0]->symbol ?? '')
          )
          @if(!is_null($product->discount_percentage) && $product->discount_percentage > 0)
            @php($discAmt = (float)($product->amount ?? 0) * (1 - ((float)$product->discount_percentage/100)))
            <div class="text-sm font-semibold text-slate-900">
              <span class="text-slate-400 line-through mr-2">{{ $sym }}{{ number_format((float)($product->amount ?? 0), 2) }}</span>
              <span>{{ $sym }}{{ number_format($discAmt, 2) }}</span>
              <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700 ml-2">-{{ rtrim(rtrim(number_format((float)$product->discount_percentage, 2, '.', ''), '0'), '.') }}%</span>
            </div>
          @else
            <div class="text-sm font-semibold text-slate-900">{{ $sym }}{{ number_format((float)($product->amount ?? 0), 2) }}</div>
          @endif
        </div>
        @if($product->bulk_quantity > 0)
        <div>
          <div class="text-xs text-slate-500 mb-1">Bulk Pricing</div>
          <div class="text-sm font-semibold text-slate-900">
            {{ $sym }}{{ number_format((float)$product->bulk_price, 2) }}
            <span class="text-slate-500 text-xs">(Total for {{ $product->bulk_quantity }} units)</span>
          </div>
        </div>
        @endif
        <div>
          <div class="text-xs text-slate-500 mb-1">Color</div>
          <div class="text-sm font-semibold text-slate-900">{{ $product->color ?? '—' }}</div>
        </div>
      </div>
    </div>
  </div>
  @endunless
</div>

<div id="tab-variants" class="tab-panel hidden">
  <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
    <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
      <h3 class="text-sm font-semibold text-slate-900">Variants</h3>
      @if($priceInfoSymbol ?? $priceInfo)
        <span class="text-xs text-slate-500">Price: {{ $priceInfoSymbol ?? $priceInfo }}</span>
      @endif
    </div>
    <div class="overflow-x-auto" style="max-height: 55vh;">
      <table class="w-full text-sm">
        <thead class="border-b border-slate-100">
          <tr>
            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">SKU</th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Size</th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Weight</th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Color</th>
            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Qty</th>
            <th class="px-4 py-3 text-right text-xs font-semibold text-slate-500 uppercase tracking-wider">Amount</th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Status</th>
            <th class="px-4 py-3 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Featured</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-50">
          @forelse($product->variants as $v)
          <tr>
            <td class="px-4 py-3 text-slate-900">{{ $v->sku ?? '—' }}</td>
            <td class="px-4 py-3 text-slate-700">
              @if(!is_null($v->size))
                {{ rtrim(rtrim(number_format((float)$v->size, 2, '.', ''), '0'), '.') }} {{ optional(DB::table('size_units')->find($v->size_unit_id))->code }}
              @else — @endif
            </td>
            <td class="px-4 py-3 text-slate-700">
              @if(!is_null($v->weight))
                {{ rtrim(rtrim(number_format((float)$v->weight, 2, '.', ''), '0'), '.') }} {{ optional(DB::table('weight_units')->find($v->weight_unit_id))->code }}
              @else — @endif
            </td>
            <td class="px-4 py-3 text-slate-700">{{ $v->color ?? '—' }}</td>
            <td class="px-4 py-3 text-right text-slate-900">{{ (int)$v->quantity }}</td>
            <td class="px-4 py-3 text-right text-slate-900">{{ ($currencySymbols[$v->currency_id ?? 0]->symbol ?? '') }}{{ number_format((float)$v->amount, 2) }}</td>
            <td class="px-4 py-3"><span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">{{ $v->status }}</span></td>
            <td class="px-4 py-3 text-slate-700">{{ $v->featured ? 'Yes' : 'No' }}</td>
          </tr>
          @empty
          <tr><td colspan="8" class="px-4 py-8 text-center text-slate-500">No variants.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </div>
</div>

<div id="tab-media" class="tab-panel hidden">
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2">
      <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
          <h3 class="text-sm font-semibold text-slate-900">Images</h3>
        </div>
        <div class="p-6">
          <div class="flex flex-wrap gap-3">
            @forelse($product->images as $img)
              <div class="border border-slate-200 rounded-lg p-2 w-[160px]">
                <img src="{{ asset('storage/'.$img->path) }}" class="w-full max-h-[120px] object-contain" alt="">
                @if($img->is_primary)
                  <div class="mt-2"><span class="inline-flex items-center rounded-full bg-blue-50 px-2.5 py-0.5 text-xs font-medium text-blue-700">Primary</span></div>
                @endif
              </div>
            @empty
              <div class="text-sm text-slate-500">No images uploaded.</div>
            @endforelse
          </div>
        </div>
      </div>
    </div>
    <div class="lg:col-span-1">
      <div class="bg-white rounded-xl shadow-sm border border-slate-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
          <h3 class="text-sm font-semibold text-slate-900">Meta</h3>
        </div>
        <div class="p-6">
          <div class="mb-3"><span class="text-xs text-slate-500">Created:</span> <span class="text-sm font-semibold text-slate-900">{{ $product->created_at }}</span></div>
          <div><span class="text-xs text-slate-500">Updated:</span> <span class="text-sm font-semibold text-slate-900">{{ $product->updated_at }}</span></div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
function switchProductTab(name) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.add('hidden'));
  document.querySelectorAll('.tab-btn').forEach(b => {
    b.classList.remove('border-slate-900', 'text-slate-900');
    b.classList.add('border-transparent', 'text-slate-500');
  });
  document.getElementById('tab-' + name).classList.remove('hidden');
  const btn = document.querySelector('[data-tab="' + name + '"]');
  btn.classList.remove('border-transparent', 'text-slate-500');
  btn.classList.add('border-slate-900', 'text-slate-900');
}
</script>
@endsection
