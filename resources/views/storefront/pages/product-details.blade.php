@extends('storefront.layout')
@section('title', $product->name)

@section('content')

@push('styles')
   <style>
      .product-detail-page { background: #fff; padding: 60px 0; }
      .product-gallery { display: flex; flex-direction: column; gap: 20px; }
      .gallery-thumbnails { display: flex; flex-direction: row; gap: 12px; flex-wrap: wrap; }
      .gallery-thumb { width: 80px; height: 80px; border: 2px solid #e5e5e5; border-radius: 8px; overflow: hidden; cursor: pointer; transition: border-color 0.3s; }
      .gallery-thumb:hover, .gallery-thumb.active { border-color: #333; }
      .gallery-thumb img { width: 100%; height: 100%; object-fit: cover; }
      .gallery-main { flex: 1; background: #f8f8f8; border-radius: 12px; overflow: hidden; display: flex; align-items: center; justify-content: center; min-height: 500px; }
      .gallery-main img { max-width: 100%; max-height: 500px; object-fit: contain; }
      .product-info { padding-left: 40px; }
      .product-category { color: #666; font-size: 12px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
      .product-title { font-size: 32px; font-weight: 400; line-height: 1.3; margin-bottom: 16px; color: #1a1a1a; }
      .product-rating { display: flex; align-items: center; gap: 8px; margin-bottom: 20px; }
      .stars { color: #ffa500; display: flex; gap: 2px; }
      .review-count { color: #666; font-size: 14px; }
      .product-price { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
      .current-price { font-size: 24px; font-weight: 600; color: #1a1a1a; }
      .original-price { font-size: 18px; color: #999; text-decoration: line-through; }
      .discount-badge { background: #d4f4dd; color: #0d6832; padding: 4px 12px; border-radius: 20px; font-size: 13px; font-weight: 600; }
      .product-description { color: #666; line-height: 1.7; margin-bottom: 30px; font-size: 15px; }
      .size-selector { margin-bottom: 24px; }
      .size-selector label { display: block; margin-bottom: 12px; font-weight: 600; color: #333; font-size: 15px; }
      .size-options { display: flex; gap: 10px; }
      .size-btn { padding: 12px 24px; border: 2px solid #e5e5e5; background: #fff; border-radius: 8px; cursor: pointer; transition: all 0.3s; font-size: 14px; }
      .size-btn:hover { border-color: #999; }
      .size-btn.active { background: #e8f5e9; border-color: #4caf50; color: #1b5e20; }
      .quantity-cart-wrapper { display: flex; gap: 16px; align-items: center; margin-bottom: 20px; }
      .quantity-selector { display: flex; align-items: center; border: 2px solid #e5e5e5; border-radius: 8px; }
      .qty-btn { width: 44px; height: 48px; border: none; background: #fff; cursor: pointer; font-size: 18px; color: #666; transition: color 0.3s; }
      .qty-btn:hover { color: #000; }
      .qty-input { width: 60px; height: 48px; border: none; text-align: center; font-size: 16px; font-weight: 600; }
      .add-to-cart-btn { flex: 1; height: 52px; background: #2c3e50; color: #fff; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; cursor: pointer; transition: background 0.3s; }
      .add-to-cart-btn:hover { background: #1a252f; }
      .wishlist-btn { width: 52px; height: 52px; border: 2px solid #e5e5e5; background: #fff; border-radius: 8px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all 0.3s; }
      .wishlist-btn:hover { border-color: #e91e63; color: #e91e63; }
      .shipping-info { color: #666; font-size: 13px; margin-bottom: 30px; text-align: center; }
      .product-features { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; padding: 30px 0; border-top: 1px solid #e5e5e5; border-bottom: 1px solid #e5e5e5; }
      .feature-item { text-align: center; }
      .feature-icon { width: 40px; height: 40px; margin: 0 auto 10px; }
      .feature-icon svg { width: 100%; height: 100%; }
      .feature-label { font-size: 12px; color: #666; line-height: 1.4; }
      .product-details-section { }
      .details-header { padding: 20px 0; border-bottom: 1px solid #e5e5e5; cursor: pointer; display: flex; justify-content: between; align-items: center; }
      .details-header h4 { margin: 0; font-size: 16px; font-weight: 600; flex: 1; }
      .details-content { padding: 20px 0; display: none; color: #666; line-height: 1.7; font-size: 14px; }
      .details-content.active { display: block; }
      .back-link { display: inline-flex; align-items: center; gap: 8px; color: #666; text-decoration: none; margin-bottom: 30px; font-size: 14px; }
      .back-link:hover { color: #333; }
      @media (max-width: 991px) {
         .product-info { padding-left: 0; margin-top: 40px; }
         .product-features { grid-template-columns: repeat(2, 1fr); }
      }
   </style>
@endpush

<section class="product-detail-page">
   <div class="container">
      <a href="{{ store_url($product->store->slug ?? 'store') }}" class="back-link">
         <i class="fas fa-arrow-left"></i> Back to {{ $product->store->name ?? 'Store' }}
      </a>

      <div class="row">
         <!-- Product Gallery -->
         <div class="col-lg-6">
            <div class="product-gallery">
               <div class="gallery-main" id="mainGallery">
                  @if($product->images && $product->images->count() > 0)
                     <img src="{{ asset('storage/' . $product->images->first()->path) }}" alt="{{ $product->name }}" id="mainImage">
                  @else
                     <img src="{{ asset('storefront/assets/img/product/product-1.jpg') }}" alt="{{ $product->name }}" id="mainImage">
                  @endif
               </div>
               <div class="gallery-thumbnails">
                  @if($product->images && $product->images->count() > 0)
                     @foreach($product->images as $index => $image)
                        <div class="gallery-thumb {{ $index === 0 ? 'active' : '' }}" onclick="changeMainImage('{{ asset('storage/' . $image->path) }}', this)">
                           <img src="{{ asset('storage/' . $image->path) }}" alt="{{ $product->name }}">
                        </div>
                     @endforeach
                  @else
                     <div class="gallery-thumb active">
                        <img src="{{ asset('storefront/assets/img/product/product-1.jpg') }}" alt="{{ $product->name }}">
                     </div>
                  @endif
               </div>
            </div>
         </div>

         <!-- Product Info -->
         <div class="col-lg-6">
            <div class="product-info">
               <div class="product-category">{{ $product->category->name ?? ($product->store->name ?? 'Products') }}</div>
               
               <h1 class="product-title">{{ $product->name }}</h1>
               
               <div class="product-rating">
                  <div class="stars">
                     <i class="fas fa-star"></i>
                     <i class="fas fa-star"></i>
                     <i class="fas fa-star"></i>
                     <i class="fas fa-star"></i>
                     <i class="fas fa-star"></i>
                  </div>
                  <span class="review-count">{{ $product->views ?? 0 }} views</span>
               </div>

               <div class="product-price">
                  @if($product->has_variants && $priceInfoSymbol)
                     <span class="current-price">{{ $priceInfoSymbol }}</span>
                  @else
                     @if($hasDiscount && $displayDiscountedAmount)
                        <span class="current-price">{{ $displayDiscountedAmount }}</span>
                        <span class="original-price">{{ $displayBaseAmount }}</span>
                        <span class="discount-badge"> -{{ $displayDiscountPct ?? '' }}%</span>
                     @else
                        <span class="current-price">{{ $displayBaseAmount }}</span>
                     @endif
                  @endif
               </div>

               @if($product->description)
                  <div class="product-description">
                     {{ Str::limit(strip_tags($product->description), 200) }}
                  </div>
               @endif

               <!-- Size/Variant Selector -->
               @if($product->has_variants && isset($sizeOptions) && count($sizeOptions) > 0)
                  <div class="size-selector">
                     <label>Size:</label>
                     <div class="size-options">
                        @foreach($sizeOptions as $size)
                           <button class="size-btn" onclick="selectSize(this)">{{ $size }}</button>
                        @endforeach
                     </div>
                  </div>
               @elseif(!$product->has_variants && $baseMeta['size'])
                  <div class="size-selector">
                     <label>Size:</label>
                     <div class="size-options">
                        <button class="size-btn active">{{ $baseMeta['size'] }}</button>
                     </div>
                  </div>
               @endif

               <!-- Quantity & Add to Cart -->
               <div class="quantity-cart-wrapper">
                  <div class="quantity-selector">
                     <button class="qty-btn" onclick="decrementQty()">−</button>
                     <input type="number" class="qty-input" id="quantity" value="1" min="1" readonly>
                     <button class="qty-btn" onclick="incrementQty()">+</button>
                  </div>
                  <button style="font-size: 12px;" id="addToCartDetails" class="add-to-cart-btn" data-product-id="{{ $product->id }}">Add to <i class="far fa-shopping-cart"></i></button>
                  <button style="font-size: 12px;" id="buyNowBtn" class="add-to-cart-btn" data-product-id="{{ $product->id }}">Buy Now</button>
               </div>

               <div class="shipping-info">
                  <i class="fas fa-truck"></i> Ships within 3-5 business days
               </div>

               <!-- Product Features -->
               <div class="product-features">
                  <div class="feature-item">
                     <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                           <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                     </div>
                     <div class="feature-label">Quality Assured</div>
                  </div>
                  <div class="feature-item">
                     <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                           <path d="M12 2a10 10 0 100 20 10 10 0 000-20zm0 18a8 8 0 110-16 8 8 0 010 16z"/>
                           <path d="M12 6v6l4 2"/>
                        </svg>
                     </div>
                     <div class="feature-label">Fast Delivery</div>
                  </div>
                  <div class="feature-item">
                     <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                           <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/>
                           <path d="M9 22V12h6v10"/>
                        </svg>
                     </div>
                     <div class="feature-label">Secure Packaging</div>
                  </div>
                  <div class="feature-item">
                     <div class="feature-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                           <path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                        </svg>
                     </div>
                     <div class="feature-label">{{ $product->brand ?? 'Authentic' }}</div>
                  </div>
               </div>

               <!-- Product Details Accordion -->
               <div class="product-details-section">
                  <div class="details-header" onclick="toggleDetails(this)">
                     <h4>Product Details</h4>
                     <i class="fas fa-chevron-down"></i>
                  </div>
                  <div class="details-content">
                     @if($product->description)
                        <div class="description-content">
                           {!! $product->description !!}
                        </div>
                     @endif
                     
                     <ul style="list-style: none; padding: 0; margin-top: 20px;">
                        @if(isset($baseMeta['qty']))
                           <li style="padding: 8px 0; border-bottom: 1px solid #f0f0f0;">
                              <strong>Stock:</strong> {{ $baseMeta['qty'] }} {{ $product->has_variants ? 'Total' : 'Available' }}
                           </li>
                        @endif
                        @if($product->sku)
                           <li style="padding: 8px 0; border-bottom: 1px solid #f0f0f0;">
                              <strong>SKU:</strong> {{ $product->sku }}
                           </li>
                        @endif
                        <li style="padding: 8px 0; border-bottom: 1px solid #f0f0f0;">
                           <strong>Product Code:</strong> {{ $product->product_code }}
                        </li>
                        @if($product->brand)
                           <li style="padding: 8px 0; border-bottom: 1px solid #f0f0f0;">
                              <strong>Brand:</strong> {{ $product->brand }}
                           </li>
                        @endif
                        @if(!$product->has_variants && $baseMeta['weight'])
                           <li style="padding: 8px 0; border-bottom: 1px solid #f0f0f0;">
                              <strong>Weight:</strong> {{ $baseMeta['weight'] }}
                           </li>
                        @endif
                        @if(!$product->has_variants && $baseMeta['color'])
                           <li style="padding: 8px 0; border-bottom: 1px solid #f0f0f0;">
                              <strong>Color:</strong> {{ $baseMeta['color'] }}
                           </li>
                        @endif
                     </ul>

                     @if($tagsArr && $tagsArr->count() > 0)
                        <div style="margin-top: 20px;">
                           <strong style="display: block; margin-bottom: 10px;">Tags:</strong>
                           @foreach($tagsArr as $tag)
                              <span style="display: inline-block; padding: 4px 12px; background: #f0f0f0; border-radius: 16px; margin: 4px; font-size: 13px;">{{ $tag }}</span>
                           @endforeach
                        </div>
                     @endif
                  </div>
               </div>
            </div>
         </div>
      </div>
   </div>
</section>

<script>
function changeMainImage(imageSrc, thumbElement) {
   document.getElementById('mainImage').src = imageSrc;
   document.querySelectorAll('.gallery-thumb').forEach(thumb => thumb.classList.remove('active'));
   thumbElement.classList.add('active');
}

function selectSize(btn) {
   document.querySelectorAll('.size-btn').forEach(b => b.classList.remove('active'));
   btn.classList.add('active');
}

function incrementQty() {
   const input = document.getElementById('quantity');
   input.value = parseInt(input.value) + 1;
}

function decrementQty() {
   const input = document.getElementById('quantity');
   if (parseInt(input.value) > 1) {
      input.value = parseInt(input.value) - 1;
   }
}

function toggleDetails(header) {
   const content = header.nextElementSibling;
   const icon = header.querySelector('i');
   content.classList.toggle('active');
   icon.style.transform = content.classList.contains('active') ? 'rotate(180deg)' : 'rotate(0deg)';
}
</script>

@endsection
