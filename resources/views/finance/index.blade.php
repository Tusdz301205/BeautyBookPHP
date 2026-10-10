@extends('layouts.app')
@section('title', 'Thanh toán')
@section('content')
<section class="page-shell management-shell module-workspace finance-workspace">
<div class="page-heading"><div><p class="eyebrow">Tài chính</p><h1>Thanh toán và hoàn tiền</h1><p>Theo dõi thanh toán, khoản chờ xác minh và yêu cầu hoàn tiền theo từng lịch hẹn.</p></div></div>
<p><a href="{{ route('finance.advanced') }}">Gói liệu trình, hóa đơn và đối soát</a></p>
<form class="form-stack" method="GET"><label>Mã lịch hẹn <input type="text" name="search" maxlength="40" value="{{ request('search') }}"></label><button class="button button-primary">Tìm</button></form>
@forelse($bookings as $booking)
<article class="card"><h2>{{ $booking->booking_code }}</h2><p>{{ $booking->branch->name }} · {{ number_format((float) $booking->total_amount, 2) }} {{ $booking->currency }}</p><a href="{{ route('finance.show', $booking) }}">Xem thanh toán</a></article>
@empty<div class="empty-state panel"><h2>Chưa có dữ liệu</h2><p>Chưa có lịch hẹn.</p></div>@endforelse
{{ $bookings->links() }}
</section>
@endsection
