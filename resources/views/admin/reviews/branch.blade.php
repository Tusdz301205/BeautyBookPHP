@extends('layouts.app')

@section('title', 'Review '.$branch->public_name.' · BeautyBook')

@section('content')
@php($dayNames = ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'])
<section class="page-shell admin-shell">
    <div class="page-heading">
        <p class="eyebrow">Review chi nhánh · {{ $branch->business->name }}</p>
        <h1>{{ $branch->public_name }}</h1>
        <p>{{ $branch->review_status->value }} · {{ $branch->operational_status->value }}</p>
    </div>

    @if ($errors->any())
        <div class="notice danger-panel" role="alert">{{ $errors->first() }}</div>
    @endif

    <div class="detail-grid review-detail-grid">
        <article class="panel definition-panel">
            <h2>Thông tin chi nhánh</h2>
            <dl class="definition-list">
                <div><dt>Địa chỉ</dt><dd>{{ $branch->address_line }}</dd></div>
                <div><dt>Điện thoại</dt><dd>{{ $branch->phone }}</dd></div>
                <div><dt>Email</dt><dd>{{ $branch->email ?: '—' }}</dd></div>
                <div><dt>Múi giờ</dt><dd>{{ $branch->timezone }}</dd></div>
                <div><dt>Mô tả</dt><dd>{{ $branch->description ?: '—' }}</dd></div>
            </dl>
            <div class="hours-list review-hours">
                @foreach ($branch->workingHours as $hour)
                    <div><span>{{ $dayNames[$hour->day_of_week] }}</span><strong>{{ $hour->is_closed ? 'Đóng cửa' : substr($hour->open_time, 0, 5).' – '.substr($hour->close_time, 0, 5) }}</strong></div>
                @endforeach
            </div>
        </article>

        <aside class="panel form-stack">
            <div>
                <p class="card-label">Quyết định</p>
                <h2>Xử lý hồ sơ</h2>
                <p>APPROVE đưa chi nhánh sang READY_TO_PUBLISH. Chủ doanh nghiệp vẫn phải tự publish.</p>
            </div>
            <form class="form-stack" method="POST" action="{{ route('admin.reviews.branch.review', $branch) }}">
                @csrf
                <label>Quyết định
                    <select name="decision" required>
                        <option value="APPROVE">APPROVE</option>
                        <option value="REQUEST_INFO">REQUEST_INFO</option>
                        <option value="REJECT">REJECT</option>
                    </select>
                </label>
                <label>Ghi chú<textarea name="note" rows="5">{{ old('note') }}</textarea></label>
                <button class="button button-primary" type="submit">Xác nhận</button>
            </form>
            <a class="text-link" href="{{ route('admin.reviews.index') }}">Về hàng đợi</a>
        </aside>
    </div>

    @if ($branch->stateTransitions->isNotEmpty())
        <article class="panel history-panel">
            <h2>Lịch sử trạng thái</h2>
            <div class="stack-list">
                @foreach ($branch->stateTransitions->sortByDesc('version') as $transition)
                    <div class="list-row">
                        <div><strong>#{{ $transition->version }} · {{ $transition->action }}</strong><span>{{ $transition->from_review_status }} → {{ $transition->to_review_status }}</span></div>
                        <span>{{ $transition->from_operational_status }} → {{ $transition->to_operational_status }}</span>
                    </div>
                @endforeach
            </div>
        </article>
    @endif
</section>
@endsection
