@extends('layouts.app')
@section('title', 'Dịch vụ đã lưu')
@section('content')
<section class="page-shell management-shell module-workspace">
<div class="page-heading"><div><p class="eyebrow">Bộ sưu tập của tôi</p><h1>Dịch vụ đã lưu</h1><p>Lưu dịch vụ yêu thích để tìm lại và đặt lịch khi bạn cần.</p></div></div>
<div class="card-grid">@forelse($saved as $item)
<article class="card">
    <h2>{{ $item->offering?->service?->name ?? 'Dịch vụ đã ngừng cung cấp' }}</h2>
    <p>{{ $item->offering?->branch?->name }} · {{ $item->available ? 'Có thể đặt lịch' : 'Hiện không khả dụng' }}</p>
    @if($item->available)<a href="{{ route('booking.create', $item->offering->branch) }}" class="button button-primary">Đặt lịch</a>@endif
    <form class="form-stack" method="POST" action="{{ route('engagement.remove', $item->branch_service_offering_id) }}">@csrf @method('DELETE')<button class="button button-secondary" type="submit">Bỏ lưu</button></form>
</article>
@empty<div class="empty-state panel"><h2>Chưa có dữ liệu</h2><p>Bạn chưa lưu dịch vụ nào. Chọn biểu tượng lưu ở trang dịch vụ để thêm vào bộ sưu tập.</p><a class="button button-primary" href="{{ route('marketplace.index') }}">Khám phá dịch vụ</a></div>@endforelse
</div>{{ $saved->links() }}
</section>
@endsection
