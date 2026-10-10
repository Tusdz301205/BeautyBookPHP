@props(['branch'])
<article class="market-card">
    <a href="{{ route('marketplace.show',[$branch->business->slug,$branch->slug]) }}" aria-label="Xem {{ $branch->public_name }}"><img class="bb-market-image" src="{{ asset('images/beautybook/spa-wellness-editorial-640.webp') }}" alt="Minh họa chăm sóc sắc đẹp" loading="lazy" width="640" height="480"></a>
    <div class="market-card-copy"><p class="card-label">{{ $branch->business->name }}</p><h2><a href="{{ route('marketplace.show',[$branch->business->slug,$branch->slug]) }}">{{ $branch->public_name }}</a></h2><p>{{ $branch->address_line }}</p><span class="bb-market-note">Ảnh minh họa</span></div>
    <div class="service-chips">@foreach($branch->offerings->take(2) as $offering)<span>{{ $offering->service->name }} · {{ number_format((float)$offering->price,0,',','.') }}đ</span>@endforeach</div>
    <a class="text-link" href="{{ route('marketplace.show',[$branch->business->slug,$branch->slug]) }}">Xem dịch vụ & đặt lịch <x-icon name="arrow"/></a>
</article>
