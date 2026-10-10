@extends('layouts.app')
@section('title', 'Thông báo · BeautyBook')
@section('content')
<section class="page-shell">
    <div class="page-heading"><div><p class="eyebrow">BeautyBook</p><h1>Thông báo</h1></div><p>{{ $unreadCount }} thông báo chưa đọc</p></div>
    <div class="stack-list">
        @forelse ($notifications as $notification)
            <article class="panel">
                <h2>{{ $notification->title }}</h2>
                <p>{{ $notification->body }}</p>
                <p class="muted-note">{{ \Carbon\CarbonImmutable::parse($notification->created_at, 'UTC')->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }} · {{ $notification->is_read ? 'Đã đọc' : 'Chưa đọc' }}</p>
                <div class="action-row">
                    @if ($notification->related_booking_id)
                        <a class="button" href="{{ route('bookings.index', ['booking' => $notification->related_booking_id]) }}">Xem lịch hẹn</a>
                    @endif
                    @if (! $notification->is_read)
                        <form method="POST" action="{{ route('notifications.read', $notification->id) }}">@csrf<button class="button" type="submit">Đánh dấu đã đọc</button></form>
                    @endif
                </div>
            </article>
        @empty
            <p class="panel">Bạn chưa có thông báo.</p>
        @endforelse
    </div>
    {{ $notifications->links() }}
</section>
@endsection
