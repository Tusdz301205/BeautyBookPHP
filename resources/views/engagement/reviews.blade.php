@extends('layouts.app')
@section('title', 'Đánh giá BeautyBook')
@section('content')
<section class="page-shell management-shell module-workspace">
<div class="page-heading"><div><p class="eyebrow">{{ $mode === 'public' ? 'Cộng đồng BeautyBook' : ($mode === 'management' ? 'Chăm sóc khách hàng' : 'Trải nghiệm của tôi') }}</p><h1>Đánh giá {{ $business->name ?? 'BeautyBook' }}</h1><p>Mỗi lịch hoàn thành được đánh giá một lần. Theo dõi phản hồi của salon và trạng thái hiển thị tại đây.</p></div></div>
@if($mode === 'public')<p>{{ number_format($average, 1) }}/5 · {{ $total }} đánh giá</p>@endif
@if($mode !== 'public')
<form class="form-stack" method="GET"><label>Tìm nội dung <input type="text" name="search" value="{{ request('search') }}"></label><label>Trạng thái <select name="status"><option value="">Tất cả</option>@foreach(['APPROVED','REPORTED','HIDDEN'] as $status)<option @selected(request('status') === $status)>{{ $status }}</option>@endforeach</select></label><button class="button button-primary">Lọc</button></form>
@endif
@foreach($bookings as $booking)
<article class="card"><h2>{{ $booking->branch?->name ?? 'Lịch hoàn thành' }}</h2><p>{{ $booking->booking_code }} · {{ $booking->appointment_start_at?->copy()->timezone($booking->branch?->timezone ?? 'Asia/Ho_Chi_Minh')->format('d/m/Y') }}</p><details class="module-disclosure"><summary>Viết đánh giá</summary>
<form class="form-stack" method="POST" action="{{ route('engagement.store', $booking) }}">@csrf
<label>Điểm tổng thể <select name="overall_rating">@foreach(range(5,1) as $rating)<option>{{ $rating }}</option>@endforeach</select></label>
<label>Nội dung <textarea required name="comment" maxlength="5000"></textarea></label>
<label class="check-row"><input type="checkbox" name="is_anonymous" value="1">Ẩn tên khi hiển thị công khai</label>
@foreach($booking->services as $item)
<fieldset><legend>{{ $item->service_name_snapshot }}</legend><input type="hidden" name="service_ratings[{{ $loop->index }}][booking_service_id]" value="{{ $item->id }}"><label>Điểm dịch vụ <select name="service_ratings[{{ $loop->index }}][rating]">@foreach(range(5,1) as $rating)<option>{{ $rating }}</option>@endforeach</select></label><label>Nhận xét <input type="text" name="service_ratings[{{ $loop->index }}][comment]" maxlength="2000"></label></fieldset>
@endforeach
<button class="button button-primary">Gửi đánh giá</button></form></details></article>
@endforeach
@forelse($reviews as $review)
<article class="card">
@if($mode === 'public' && $review->public_avatar_url)
<img src="{{ $review->public_avatar_url }}" alt="Ảnh đại diện" width="48" height="48" loading="lazy">
@endif
<h2><span class="rating" aria-label="{{ $review->overall_rating }} trên 5 sao">{{ str_repeat('★', $review->overall_rating) }}{{ str_repeat('☆', 5 - $review->overall_rating) }}</span> {{ $review->overall_rating }}/5 · {{ $review->is_anonymous ? 'Ẩn danh' : ($review->customerProfile?->user?->name ?? 'Khách hàng') }}</h2>
<p>{{ $review->booking?->branch?->name }} · {{ $review->created_at->format('d/m/Y') }}</p><p>{{ $review->comment }}</p>
@if($mode==='public')
@foreach($review->public_service_ratings??[] as $rating)<p>{{ $rating['serviceName'] }}@if($rating['staffName']) · {{ $rating['staffName'] }}@endif: {{ $rating['rating'] }}/5 {{ $rating['comment']??'' }}</p>@endforeach
@else
@foreach($review->service_ratings ?? [] as $rating)<p>{{ $rating['service_name'] }}: {{ $rating['rating'] }}/5 {{ $rating['comment'] ?? '' }}</p>@endforeach
@endif
@if($review->business_reply)<blockquote>Phản hồi từ salon: {{ $review->business_reply }}</blockquote>@endif
@if($mode !== 'public')<p><span class="badge">{{ ['APPROVED'=>'Đã hiển thị','REPORTED'=>'Đang được xem xét','HIDDEN'=>'Đã ẩn'][$review->status] ?? $review->status }}</span></p>@endif
@if($mode === 'management')
<form class="form-stack" method="POST" action="{{ route('engagement.action', [$review, 'reply']) }}">@csrf<label>Phản hồi <textarea name="content" required maxlength="5000">{{ $review->business_reply }}</textarea></label><button class="button button-primary">Lưu phản hồi</button></form>
@endif
@auth
<details class="panel module-disclosure"><summary>Báo cáo đánh giá</summary><form class="form-stack" method="POST" action="{{ route('engagement.action', [$review, 'report']) }}">@csrf<label>Lý do <input type="text" name="reason" required maxlength="2000"></label><label>Phân loại <select name="category">@foreach(['OTHER','SPAM','PII','THREAT','HATE','SEXUAL'] as $category)<option>{{ $category }}</option>@endforeach</select></label><label>Mức độ <select name="severity">@foreach(['MEDIUM','LOW','HIGH','CRITICAL'] as $severity)<option>{{ $severity }}</option>@endforeach</select></label><button class="button button-primary">Gửi báo cáo</button></form></details>
@if($mode !== 'public')<details class="panel module-disclosure"><summary>Khiếu nại</summary><form class="form-stack" method="POST" action="{{ route('engagement.action', [$review, 'appeal']) }}">@csrf<label>Lý do <textarea required name="reason" maxlength="2000"></textarea></label><button class="button button-primary">Gửi khiếu nại</button></form></details>@endif
@endauth
@if($isAdmin)
<form class="form-stack" method="POST" action="{{ route('engagement.action', [$review, 'moderate']) }}">@csrf<label>Quyết định <select name="status"><option value="APPROVED">Hiển thị</option><option value="HIDDEN">Ẩn</option></select></label><label>Mã lý do <input type="text" name="reason_code" required maxlength="80"></label><label>Nội dung quyết định <textarea name="reason" required maxlength="2000"></textarea></label><button class="button button-primary">Kiểm duyệt</button></form>
@foreach($appeals->get($review->id, collect()) as $appeal)<form class="form-stack" method="POST" action="{{ route('engagement.action', [$review, 'resolve']) }}">@csrf<p>Khiếu nại: {{ $appeal->reason }}</p><input type="hidden" name="appeal_id" value="{{ $appeal->id }}"><label>Kết quả <select name="approve"><option value="1">Chấp nhận và hiển thị</option><option value="0">Từ chối</option></select></label><label>Kết luận <textarea name="reason" required maxlength="2000"></textarea></label><button class="button button-primary">Xử lý khiếu nại</button></form>@endforeach
@endif
</article>
@empty<div class="empty-state panel"><h2>Chưa có dữ liệu</h2><p>Chưa có đánh giá.</p></div>@endforelse
{{ $reviews->links() }}
</section>
@endsection
