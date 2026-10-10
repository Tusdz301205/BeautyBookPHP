@extends('layouts.app')
@section('title', 'Chuỗi lịch của tôi · BeautyBook')
@section('content')
<section class="page-shell management-shell module-workspace">
    <div class="page-heading"><h1>Chuỗi lịch của tôi</h1><a class="button button-primary" href="{{ route('marketplace.index') }}">Chọn chi nhánh</a></div>
    
    
    @forelse($series as $plan)
    <article class="service-card">
        <h2>{{ $plan->branch->name }} · #{{ $plan->id }}</h2><p>{{ ['WEEKLY'=>'Hàng tuần','BIWEEKLY'=>'Hai tuần một lần','MONTHLY'=>'Hàng tháng'][$plan->frequency] ?? $plan->frequency }} · <span class="badge">{{ ['ACTIVE'=>'Đang hoạt động','PAUSED'=>'Tạm dừng','CANCELLED'=>'Đã hủy'][$plan->status] ?? $plan->status }}</span></p>
        @if(in_array($plan->status, ['ACTIVE', 'PAUSED']))
        <form class="form-stack" method="POST" action="{{ route('recurring.status', $plan) }}">@csrf @method('PATCH')<input type="hidden" name="status" value="{{ $plan->status === 'ACTIVE' ? 'PAUSED' : 'ACTIVE' }}"><button class="button button-primary" type="submit">{{ $plan->status === 'ACTIVE' ? 'Tạm dừng chuỗi' : 'Tiếp tục chuỗi' }}</button></form>
        <p>Tạm dừng không hủy các lịch hẹn đã tạo.</p>
        <form class="form-stack" method="POST" action="{{ route('recurring.cancel', $plan) }}">@csrf<button class="button button-secondary" type="submit">Hủy chuỗi lịch</button></form>
        @endif
        <ul>@foreach($plan->occurrences as $occurrence)
            <li>{{ $occurrence->scheduled_start_at->setTimezone($plan->branch->timezone ?: 'Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }} · {{ $occurrence->booking?->status ?? $occurrence->status }}
                @if($occurrence->reason)<span>{{ $occurrence->reason }}</span>@endif
                @if($occurrence->booking)<a href="{{ route('bookings.index', ['booking' => $occurrence->booking_id]) }}">Xem lịch hẹn</a>@endif
                @if(in_array($occurrence->booking?->status, ['PENDING', 'CONFIRMED']))<form class="form-stack" method="POST" action="{{ route('recurring.occurrences.cancel', [$plan, $occurrence->id]) }}">@csrf<button class="button button-secondary" type="submit">Hủy kỳ này</button></form>@endif
            </li>
        @endforeach</ul>
    </article>
    @empty<div class="empty-state panel"><h2>Chưa có dữ liệu</h2><p>Chưa có chuỗi lịch. Chọn một chi nhánh để đặt lịch định kỳ.</p></div>@endforelse
    {{ $series->links() }}
</section>
@endsection
