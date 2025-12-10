<div class="modal quick-view-modal fade" id="exampleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close">
                <i class="fa fa-times"></i>
            </button>
            <div class="modal-body">
                <div class="row g-xl-4 g-3">
                    <div class="col-xl-6 col-md-6">
                        <div class="dz-product-detail mb-0">
                            <div class="swiper-btn-center-lr">
                                <div class="swiper quick-modal-swiper2">
                                    <div class="swiper-wrapper" id="quickModalGallery">
                                        <!-- populated by JS -->
                                    </div>
                                    <script>
                                        document.addEventListener('DOMContentLoaded', function(){
                                        var modal = document.getElementById('exampleModal');
                                        modal?.addEventListener('show.bs.modal', function(e){
                                            var btn = e.relatedTarget; if(!btn) return;
                                            var name = btn.getAttribute('data-name') || 'Product';
                                            var url = btn.getAttribute('data-url') || '#';
                                            function fmtNgn(kobo){ var n=(parseInt(kobo||'0',10)||0)/100; return new Intl.NumberFormat('en-NG',{style:'currency',currency:'NGN'}).format(n); }
                                            var amountKobo = btn.getAttribute('data-amount-kobo');
                                            var amountWasKobo = btn.getAttribute('data-amount-was-kobo');
                                            var price = amountKobo ? fmtNgn(amountKobo) : (btn.getAttribute('data-price') || '—');
                                            var priceWas = amountWasKobo ? fmtNgn(amountWasKobo) : (btn.getAttribute('data-price-was') || '');
                                            var discount = btn.getAttribute('data-discount') || '';
                                            var qtyMax = parseInt(btn.getAttribute('data-qty-max') || '1', 10) || 1;
                                            var sku = btn.getAttribute('data-sku') || '—';
                                            var category = btn.getAttribute('data-category') || '—';
                                            var tagsAttr = btn.getAttribute('data-tags') || '[]';
                                            var imgsAttr = btn.getAttribute('data-images') || '[]';
                                            var tags = []; var images = [];
                                            try{ tags = JSON.parse(tagsAttr); }catch(_){ tags = []; }
                                            try{ images = JSON.parse(imgsAttr); }catch(_){ images = []; }
                                            // Fallback image from the card if images is empty
                                            if (!images || !images.length){
                                                var cardImg = btn.closest('.shop-card')?.querySelector('img');
                                                var dataImg = btn.getAttribute('data-image');
                                                var src = dataImg || (cardImg ? cardImg.getAttribute('src') : '');
                                                if (src) { images = [src]; }
                                            }

                                            var titleA = document.getElementById('quickModalTitle');
                                            if (titleA){ titleA.textContent = name; titleA.href = url; }
                                            var descA = document.getElementById('quickModalDescLink');
                                            if (descA){ descA.href = url; }

                                            var priceEl = document.getElementById('quickModalPrice');
                                            if (priceEl){ priceEl.innerHTML = priceWas ? (price + ' <del class="text-muted">' + priceWas + '</del>') : price; }

                                            var badge = document.getElementById('quickModalBadge');
                                            var badgePct = document.getElementById('quickModalBadgePct');
                                            var d = parseFloat(discount);
                                            if (badge && badgePct){
                                            if (!isNaN(d) && d > 0){ badge.style.display = 'inline-block'; badgePct.textContent = (''+d).replace(/\.0+$/,''); }
                                            else { badge.style.display = 'none'; }
                                            }

                                            var qty = document.getElementById('quickModalQty');
                                            if (qty){ qty.max = String(qtyMax > 0 ? qtyMax : 1); qty.value = '1'; }

                                            // Wire Add to Cart button
                                            var addBtn = document.getElementById('quickModalAddBtn');
                                            if (addBtn){
                                                var pid = btn.getAttribute('data-product-id') || '';
                                                var store = btn.getAttribute('data-store') || '';
                                                addBtn.setAttribute('data-add-to-cart','');
                                                addBtn.setAttribute('data-product-id', pid);
                                                addBtn.setAttribute('data-store', store);
                                                addBtn.setAttribute('data-qty-selector', '#quickModalQty');
                                            }

                                            var skuEl = document.getElementById('quickModalSku'); if (skuEl) skuEl.textContent = sku;
                                            var catEl = document.getElementById('quickModalCategory'); if (catEl) catEl.textContent = category || '—';
                                            var tagsEl = document.getElementById('quickModalTags'); if (tagsEl) tagsEl.textContent = (Array.isArray(tags) && tags.length) ? tags.join(', ') : '—';

                                            var gallery = document.getElementById('quickModalGallery');
                                            var thumbs = document.getElementById('quickModalThumbs');
                                            function clear(el){ while(el && el.firstChild){ el.removeChild(el.firstChild); } }
                                            clear(gallery); clear(thumbs);
                                            if (!images || !images.length){ images = ['{{ asset('home/images/no-image.jpg') }}']; }
                                            images.forEach(function(src){
                                            var ls = document.createElement('div'); ls.className = 'swiper-slide';
                                            ls.innerHTML = '<div class="dz-media DZoomImage"><a class="mfp-link lg-item" href="'+src+'" data-src="'+src+'"><i class="feather icon-maximize dz-maximize top-right"></i></a><img src="'+src+'" alt=""></div>';
                                            gallery?.appendChild(ls);
                                            var ts = document.createElement('div'); ts.className = 'swiper-slide';
                                            ts.innerHTML = '<img src="'+src+'" alt="">';
                                            thumbs?.appendChild(ts);
                                            });

                                            if (window.Swiper){
                                            try{ modal._qmMain?.destroy(true,true); }catch(_){}
                                            modal._qmMain = new Swiper('.quick-modal-swiper2', { slidesPerView: 1 });
                                            try{ modal._qmThumbs?.destroy(true,true); }catch(_){}
                                            modal._qmThumbs = new Swiper('.thumb-swiper-lg', { direction: 'vertical', slidesPerView: Math.min(images.length,4) });
                                            }
                                        });
                                        });
                                    </script>
                                </div>
                                <div class="swiper quick-modal-swiper thumb-swiper-lg thumb-sm swiper-vertical">
                                    <div class="swiper-wrapper" id="quickModalThumbs">
                                        <!-- populated by JS -->
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-xl-6 col-md-6">
                        <div class="dz-product-detail style-2 ps-xl-3 ps-0 pt-2 mb-0">
                            <div class="dz-content">
                                <div class="dz-content-footer">
                                    <div class="dz-content-start">
                                        <span class="badge bg-purple mb-2" id="quickModalBadge" style="display:none;">SALE <span id="quickModalBadgePct">0</span>% Off</span>
                                        <h4 class="title mb-1"><a id="quickModalTitle" href="#" target="_blank">Product</a></h4>
                                        
                                    </div>
                                </div>
                                <p class="para-text">
                                    <a id="quickModalDescLink" href="#" class="text-primary">View description</a>
                                </p>
                                <div class="meta-content m-b20 d-flex align-items-end">
                                    <div class="me-3">
                                        <span class="form-label">Price</span>
                                        <span class="price-num" id="quickModalPrice">—</span>
                                    </div>
                                    <div class="btn-quantity light me-0">
                                        <label class="form-label">Quantity</label>
                                        <input id="quickModalQty" type="number" value="1" min="1" max="1" class="form-control" name="qty">
                                    </div>
                                </div>
                                <div class="btn-group cart-btn">
                                    <a id="quickModalAddBtn" href="#" class="btn btn-md btn-secondary text-uppercase" data-add-to-cart data-product-id="" data-store="" data-qty-selector="#quickModalQty">Add To Cart</a>
                                    <a href="shop-wishlist.html" class="btn btn-md btn-light btn-icon">
                                        <svg width="19" height="17" viewBox="0 0 19 17" fill="none" xmlns="http://www.w3.org/2000/svg">
                                            <path d="M9.24805 16.9986C8.99179 16.9986 8.74474 16.9058 8.5522 16.7371C7.82504 16.1013 7.12398 15.5038 6.50545 14.9767L6.50229 14.974C4.68886 13.4286 3.12289 12.094 2.03333 10.7794C0.815353 9.30968 0.248047 7.9162 0.248047 6.39391C0.248047 4.91487 0.755203 3.55037 1.67599 2.55157C2.60777 1.54097 3.88631 0.984375 5.27649 0.984375C6.31552 0.984375 7.26707 1.31287 8.10464 1.96065C8.52734 2.28763 8.91049 2.68781 9.24805 3.15459C9.58574 2.68781 9.96875 2.28763 10.3916 1.96065C11.2292 1.31287 12.1807 0.984375 13.2197 0.984375C14.6098 0.984375 15.8885 1.54097 16.8202 2.55157C17.741 3.55037 18.248 4.91487 18.248 6.39391C18.248 7.9162 17.6809 9.30968 16.4629 10.7792C15.3733 12.094 13.8075 13.4285 11.9944 14.9737C11.3747 15.5016 10.6726 16.1001 9.94376 16.7374C9.75136 16.9058 9.50417 16.9986 9.24805 16.9986ZM5.27649 2.03879C4.18431 2.03879 3.18098 2.47467 2.45108 3.26624C1.71033 4.06975 1.30232 5.18047 1.30232 6.39391C1.30232 7.67422 1.77817 8.81927 2.84508 10.1066C3.87628 11.3509 5.41011 12.658 7.18605 14.1715L7.18935 14.1743C7.81021 14.7034 8.51402 15.3033 9.24654 15.9438C9.98344 15.302 10.6884 14.7012 11.3105 14.1713C13.0863 12.6578 14.6199 11.3509 15.6512 10.1066C16.7179 8.81927 17.1938 7.67422 17.1938 6.39391C17.1938 5.18047 16.7858 4.06975 16.045 3.26624C15.3152 2.47467 14.3118 2.03879 13.2197 2.03879C12.4197 2.03879 11.6851 2.29312 11.0365 2.79465C10.4585 3.24179 10.0558 3.80704 9.81975 4.20255C9.69835 4.40593 9.48466 4.52733 9.24805 4.52733C9.01143 4.52733 8.79774 4.52733 8.67635 4.20255C8.44041 3.80704 8.03777 3.24179 7.45961 2.79465C6.811 2.29312 6.07643 2.03879 5.27649 2.03879Z" fill="black"></path>
                                        </svg>
                                        Add To Wishlist
                                    </a>
                                </div>
                                <div class="dz-info mb-0">
                                    <ul>
                                        <li>
                                            <strong>SKU:</strong>
                                            <span id="quickModalSku">—</span>
                                        </li>
                                        <li>
                                            <strong>Category:</strong>
                                            <span id="quickModalCategory">—</span>
                                        </li>
                                        <li>
                                            <strong>Tags:</strong>
                                            <span id="quickModalTags">—</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>