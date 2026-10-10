@if ($ui['isApp'])
    <button class="bb-backdrop" data-nav-close hidden aria-label="Đóng điều hướng"></button>
    <aside class="bb-sidebar" id="main-navigation" aria-label="Điều hướng {{ $ui['roleLabel'] }}" tabindex="-1">
        <div class="bb-sidebar-brand"><a class="brand" href="{{ route('app') }}"><span class="brand-mark">B</span>BeautyBook</a><button type="button" class="bb-icon-button bb-drawer-close" data-nav-close aria-label="Đóng menu"><x-icon name="close"/></button></div>
        <div class="bb-workspace-label"><span>{{ $ui['roleLabel'] }}</span><strong>{{ $ui['workspace']?->branch?->name ?? $ui['workspace']?->business?->name ?? 'Không gian của tôi' }}</strong></div>
        <nav class="bb-side-nav">
            @foreach ($ui['groups'] as $group => $links)
                @if($group === 'Ưu đãi & dịch vụ')<details class="bb-nav-disclosure" @if(collect($links)->contains('active',true)) open @endif><summary><x-icon name="ticket"/><span>{{ $group }}</span></summary>@endif
                <div class="bb-nav-group"><p>{{ $group }}</p>
                    @foreach ($links as $link)
                        <a href="{{ $link['url'] }}" @class(['bb-nav-link','is-active'=>$link['active']]) @if($link['active']) aria-current="page" @endif><x-icon :name="$link['icon']"/><span>{{ $link['label'] }}</span></a>
                    @endforeach
                </div>
                @if($group === 'Ưu đãi & dịch vụ')</details>@endif
            @endforeach
        </nav>
        <div class="bb-sidebar-account"><span class="bb-avatar">{{ mb_substr(auth()->user()->name,0,1) }}</span><div><strong>{{ auth()->user()->name }}</strong><span>{{ $ui['roleLabel'] }}</span></div><form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="bb-icon-button" aria-label="Đăng xuất"><x-icon name="exchange"/></button></form></div>
    </aside>
    <header class="bb-topbar"><div class="bb-topbar-context"><button type="button" class="bb-icon-button bb-menu-toggle" data-nav-open aria-controls="main-navigation" aria-expanded="false" aria-label="Mở menu"><x-icon name="menu"/></button><span>{{ $ui['roleLabel'] }}</span><span class="bb-context-divider">/</span><strong>@yield('title','Tổng quan')</strong></div><div class="bb-topbar-actions"><a class="bb-icon-button" href="{{ route('notifications.index') }}" aria-label="Thông báo"><x-icon name="bell"/></a><a class="bb-avatar" href="{{ $ui['role']==='CUSTOMER' ? route('profile.show') : route('security.index') }}" aria-label="Tài khoản của tôi">{{ mb_substr(auth()->user()->name,0,1) }}</a></div></header>
@else
    <div class="bb-utility"><span>Đặt lịch làm đẹp, theo cách của bạn.</span><a href="{{ route('login') }}">Dành cho cơ sở</a></div>
    <header class="bb-public-header"><a class="brand" href="{{ route('home') }}"><span class="brand-mark">B</span>BeautyBook</a><nav class="bb-public-nav" aria-label="Điều hướng công khai"><a href="{{ route('marketplace.index') }}" @if(request()->routeIs('marketplace.*')) aria-current="page" @endif>Khám phá</a><a href="{{ route('home') }}#dich-vu">Dịch vụ</a><a href="{{ route('marketplace.index') }}">Cơ sở</a><a href="{{ route('home') }}#cach-dat">Cách đặt</a></nav><div class="bb-public-actions">@auth<a class="bb-account-link" href="{{ route('app') }}"><x-icon name="user"/><span>Tài khoản</span></a>@else<a class="bb-account-link" href="{{ route('login') }}">Đăng nhập</a>@endauth<a class="button button-primary" href="{{ route('marketplace.index') }}">Đặt lịch<x-icon name="arrow"/></a><button type="button" class="bb-icon-button bb-menu-toggle" data-nav-open aria-controls="main-navigation" aria-expanded="false" aria-label="Mở menu"><x-icon name="menu"/></button></div></header>
    <button class="bb-backdrop" data-nav-close hidden aria-label="Đóng điều hướng"></button><aside id="main-navigation" class="bb-public-drawer" aria-label="Menu công khai" tabindex="-1"><div class="section-heading-row"><strong>BeautyBook</strong><button type="button" class="bb-icon-button" data-nav-close aria-label="Đóng menu"><x-icon name="close"/></button></div><a href="{{ route('marketplace.index') }}">Khám phá</a><a href="{{ route('home') }}#dich-vu">Dịch vụ</a><a href="{{ route('marketplace.index') }}">Cơ sở</a><a href="{{ route('home') }}#cach-dat">Cách đặt</a><a href="{{ auth()->check()?route('app'):route('login') }}">{{ auth()->check()?'Tài khoản của tôi':'Đăng nhập' }}</a></aside>
@endif
