@extends('layouts.app')
@section('title', 'Báo cáo lịch hẹn · BeautyBook')
@section('content')
<section class="page-shell"><h1>Báo cáo lịch hẹn</h1>
<form class="panel" method="GET"><label>Từ ngày <input type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label><label>Đến ngày <input type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label><button class="button">Lọc</button></form>
<div class="panel"><h2>Số lượng và giá trị lịch hẹn</h2><p>Giá trị lịch hẹn theo snapshot; khoản thực thu được ghi trong sổ giao dịch thanh toán.</p>@foreach($summary as $row)<p>{{ $row->status }}: {{ $row->total }} lịch · {{ number_format((float)$row->booking_value, 0, ',', '.') }}đ</p>@endforeach</div>
@forelse($bookings as $booking)<article class="panel"><strong>{{ $booking->booking_code }}</strong><p>{{ $booking->branch->public_name }} · {{ $booking->status }} · {{ $booking->appointment_start_at->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }}</p></article>@empty<p>Không có lịch hẹn trong khoảng đã chọn.</p>@endforelse
{{ $bookings->links() }}</section>
@endsection
