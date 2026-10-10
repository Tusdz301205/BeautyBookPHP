@extends('layouts.app')
@section('title', 'Xử lý ảnh hưởng vận hành · BeautyBook')
@section('content')
<section class="page-shell management-shell module-workspace">
    <div class="page-heading"><div><p class="eyebrow">{{ $impact->branch->name }}</p><h1>Hồ sơ ảnh hưởng #{{ $impact->id }}</h1></div><a class="button button-primary" href="{{ route('impacts.index') }}">Danh sách hồ sơ</a></div>
    <p>@include('finance.status', ['value' => $impact->status]) · {{ $impact->trigger_type }} · {{ $impact->reason }}</p>
    
    
    @foreach($impact->items as $item)
    <article class="service-card">
        <h2>{{ $item->booking->booking_code }} · @include('finance.status', ['value' => $item->status])</h2><p>{{ $item->booking->customerProfile?->user?->name ?? $item->booking->contact?->full_name ?? 'Khách hàng' }}</p>
        <p>{{ $item->booking->appointment_start_at->setTimezone($impact->branch->timezone)->format('d/m/Y H:i') }} · {{ $item->booking->status }}</p>
        <ul>@foreach($item->booking->services as $service)<li>{{ $service->service_name_snapshot }} · {{ $service->staffProfile?->full_name }}</li>@endforeach</ul>
        @if($item->status === 'PENDING' && $impact->status !== 'COMPLETED')
        <form data-impact-resolution data-current-branch="{{ $impact->branch_id }}" class="form-stack" method="POST" action="{{ route('impacts.resolve', [$impact, $item]) }}">@csrf @method('PATCH')
            <label>Phương án <select name="resolution"><option value="REASSIGN">Đổi nhân viên</option><option value="RESCHEDULE">Đổi thời gian</option><option value="TRANSFER_BRANCH">Chuyển chi nhánh</option><option value="CANCEL_REFUND">Hủy và gửi yêu cầu hoàn tiền</option><option value="APPROVED_EXCEPTION">Giữ lịch theo ngoại lệ được phê duyệt</option></select></label>
            <label>Nhân viên thay thế <select name="replacement_staff_id"><option value="">Chọn nhân viên phù hợp</option>@foreach($staff as $person)<option data-branch="{{ $person->branch_id }}" value="{{ $person->id }}">{{ $person->full_name }} · Chi nhánh #{{ $person->branch_id }}</option>@endforeach</select></label>
            <label>Chi nhánh đích <select name="replacement_branch_id"><option value="">Chọn chi nhánh đích</option>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label>
            <label>Giờ bắt đầu mới <input type="datetime-local" name="proposed_start_at"></label>
            <label>Lý do <textarea name="reason" required maxlength="2000"></textarea></label>
            <p class="help-text" data-resolution-hint role="status">Chọn phương án để xem thông tin cần bổ sung. Hoàn tiền sẽ tạo yêu cầu chờ duyệt và đối soát.</p><button type="submit" class="button button-primary">Xử lý lịch hẹn</button>
        </form>
        @else<p>{{ $item->resolution }} · {{ $item->reason }}</p>@endif
    </article>
    @endforeach
    <form class="form-stack" method="POST" action="{{ route('impacts.complete', $impact) }}">@csrf<button class="button button-primary" type="submit">Kiểm tra lại và hoàn tất hồ sơ</button></form>
</section>
@endsection
