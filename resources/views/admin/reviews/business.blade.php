@extends('layouts.app')

@section('title', 'Review '.$business->name.' · BeautyBook')

@section('content')
<section class="page-shell admin-shell">
    <div class="page-heading">
        <p class="eyebrow">Review doanh nghiệp</p>
        <h1>{{ $business->name }}</h1>
        <p>{{ $business->status->value }} · gửi {{ optional($business->submitted_at)->format('d/m/Y H:i') }}</p>
    </div>

    @if ($errors->any())
        <div class="notice danger-panel" role="alert">{{ $errors->first() }}</div>
    @endif

    <div class="detail-grid review-detail-grid">
        <article class="panel definition-panel">
            <h2>Thông tin hồ sơ</h2>
            <dl class="definition-list">
                <div><dt>Chủ sở hữu</dt><dd>{{ $business->ownerProfile?->user?->name }} · {{ $business->ownerProfile?->user?->email }}</dd></div>
                <div><dt>Tên pháp lý</dt><dd>{{ $business->ownerProfile?->company_name }}</dd></div>
                <div><dt>Mã số thuế</dt><dd>{{ $business->ownerProfile?->tax_code }}</dd></div>
                <div><dt>Email</dt><dd>{{ $business->contact_email }}</dd></div>
                <div><dt>Điện thoại</dt><dd>{{ $business->contact_phone }}</dd></div>
                <div><dt>Địa chỉ</dt><dd>{{ $business->address_line }}</dd></div>
                <div><dt>Mô tả</dt><dd>{{ $business->description ?: '—' }}</dd></div>
            </dl>
        </article>

        <aside class="panel form-stack">
            <div>
                <p class="card-label">Quyết định</p>
                <h2>Xử lý hồ sơ</h2>
                <p>REQUEST_INFO và REJECT bắt buộc có lý do.</p>
            </div>
            <form class="form-stack" method="POST" action="{{ route('admin.reviews.business.review', $business) }}">
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

    @if ($business->reviewEvents->isNotEmpty())
        <article class="panel history-panel">
            <h2>Lịch sử review</h2>
            <div class="stack-list">
                @foreach ($business->reviewEvents->sortByDesc('id') as $event)
                    <div class="list-row">
                        <div><strong>{{ $event->action }}</strong><span>{{ $event->from_status }} → {{ $event->to_status }}</span></div>
                        <span>{{ $event->actor?->email }}</span>
                    </div>
                @endforeach
            </div>
        </article>
    @endif
</section>
@endsection
