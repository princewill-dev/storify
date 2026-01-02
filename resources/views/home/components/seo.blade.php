@php(
    $t = $title ?? ($seo['title'] ?? ($seo_title ?? ($company->og_title ?? config('app.name'))))
)
@php(
    $d = $description ?? ($seo['description'] ?? ($seo_description ?? ($company->og_description ?? '')))
)
@php(
    $u = $url ?? ($seo['url'] ?? ($seo_url ?? ($company->og_url ?? url('/'))))
)
@php(
    $ty = $type ?? ($seo['type'] ?? ($seo_type ?? ($company->og_type ?? 'website')))
)
@php(
    $img = $image ?? ($seo['image'] ?? ($seo_image ?? ($company->og_image ?? null)))
)
@php(
    $card = $twitter_card ?? ($seo['twitter_card'] ?? 'summary_large_image')
)

@if(!empty($d))
<meta name="description" content="{{ $d }}">
@endif
<meta property="og:title" content="{{ $t }}">
@if(!empty($d))
<meta property="og:description" content="{{ $d }}">
@endif
<meta property="og:type" content="{{ $ty }}">
<meta property="og:url" content="{{ $u }}">
@if(!empty($img))
<meta property="og:image" content="{{ $img }}">
@endif

<meta name="twitter:card" content="{{ $card }}">
<meta name="twitter:title" content="{{ $t }}">
@if(!empty($d))
<meta name="twitter:description" content="{{ $d }}">
@endif
@if(!empty($img))
<meta name="twitter:image" content="{{ $img }}">
@endif
<meta name="twitter:url" content="{{ $u }}">
