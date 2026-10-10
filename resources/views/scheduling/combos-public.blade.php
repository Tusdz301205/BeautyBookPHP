@extends('layouts.app')
@section('title', 'Combo dịch vụ · BeautyBook')
@section('content')
<section class="page-shell management-shell module-workspace">
    <div class="page-heading"><div><p class="eyebrow">Chăm sóc trọn gói</p><h1>Combo dịch vụ</h1></div></div>
    <form class="form-stack" method="GET"><label>Tìm combo <input type="text" name="search" value="{{ request('search') }}" placeholder="Tên combo"></label><button class="button button-primary" type="submit">Tìm kiếm</button></form>
    <div class="service-grid">
    @forelse($combos as $combo)
        <article class="service-card">
            <h2>{{ $combo->name }}</h2><p>{{ $combo->branch->name }}</p><p>{{ $combo->description }}</p>
            <p><strong>{{ number_format($combo->combo_price, 0, ',', '.') }} ₫</strong> · {{ $combo->duration_minutes }} phút</p>
            <p>Giá lẻ: {{ number_format($combo->original_price, 0, ',', '.') }} ₫ · Tiết kiệm {{ number_format(max(0, $combo->original_price - $combo->combo_price), 0, ',', '.') }} ₫</p>
            <ul>@foreach($combo->items as $item)<li>{{ $item->offering->service->name }} × {{ $item->quantity }}</li>@endforeach</ul>
            <a class="button button-primary" href="{{ route('combos.booking.create', [$combo->branch, $combo]) }}">Đặt combo</a>
            <a href="{{ route('marketplace.show', [$combo->branch->business->slug, $combo->branch->slug]) }}">Xem chi nhánh</a>
        </article>
    @empty<div class="empty-state panel"><h2>Chưa có dữ liệu</h2><p>Chưa có combo phù hợp.</p></div>@endforelse
    </div>
    {{ $combos->links() }}
</section>
@endsection
