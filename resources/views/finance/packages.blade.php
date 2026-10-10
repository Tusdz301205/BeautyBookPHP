@extends('layouts.app')
@section('title','Gói liệu trình')
@section('content')
<section class="page-shell management-shell module-workspace finance-workspace">
<div class="page-heading"><div><p class="eyebrow">Liệu trình làm đẹp</p><h1>Gói liệu trình</h1><p>Tìm gói dịch vụ phù hợp. Salon hỗ trợ đăng ký và thanh toán tại quầy.</p></div></div>
<form class="form-stack" method="GET"><label>Tìm gói <input type="text" name="search" value="{{ request('search') }}"></label><button class="button button-primary">Tìm</button></form>
@forelse($packages as $package)<article class="card"><h2>{{ $package->name }}</h2><p>{{ $package->description }}</p><p>{{ \App\Services\Finance\Money::decimal($package->total_cents) }} {{ $package->currency }} · {{ $package->session_count }} lượt · sử dụng trong {{ $package->validity_days }} ngày</p></article>@empty<div class="empty-state panel"><h2>Chưa có dữ liệu</h2><p>Chưa có gói liệu trình.</p></div>@endforelse
{{ $packages->links() }}
</section>
@endsection
