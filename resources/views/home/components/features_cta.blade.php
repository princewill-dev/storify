<section class="content-inner overlay-white-middle">
    <div class="container">
        <div class="row justify-content-center gx-sm-1">
            @foreach($featureCtas as $index => $feature)
            <div class="col-lg-4 col-md-4 col-sm-4 p-b30 wow fadeInUp" data-wow-delay="{{ ($index + 1) / 10 }}s">
                <div class="icon-bx-wraper style-1 text-center">
                    <div class="icon-bx">
                        <img src="{{ $feature->icon_url }}" alt="{{ $feature->title }}" width="48" height="48">
                    </div>
                    <div class="icon-content">
                        <h3 class="dz-title m-b0">{{ $feature->title }}</h3>
                        <div class="square"></div>
                        <p class="font-20">{{ $feature->description }}</p>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</section>