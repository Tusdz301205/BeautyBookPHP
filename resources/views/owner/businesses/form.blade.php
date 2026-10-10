@extends('layouts.app')

@section('title', ($business ? 'Hồ sơ '.$business->name : 'Tạo doanh nghiệp').' · BeautyBook')

@section('content')
<section class="page-shell narrow-shell owner-shell">
    <div class="page-heading">
        <p class="eyebrow">Onboarding doanh nghiệp</p>
        <h1>{{ $business ? $business->name : 'Tạo hồ sơ doanh nghiệp.' }}</h1>
        @if ($business)
            <p>Trạng thái hiện tại: <strong>{{ $business->status->value }}</strong></p>
            @if ($business->review_note)
                <div class="notice danger-panel">Phản hồi kiểm duyệt: {{ $business->review_note }}</div>
            @endif
        @else
            <p>Điền thông tin cơ bản. Sau khi tạo, tài khoản này sẽ được gán quyền BUSINESS_OWNER cho doanh nghiệp.</p>
        @endif
    </div>

    @if ($errors->any())
        <div class="notice danger-panel" role="alert">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <form class="form-panel form-stack" method="POST" action="{{ $business ? route('owner.businesses.update', $business) : route('owner.businesses.store') }}">
        @csrf
        @if ($business)
            @method('PUT')
        @endif
        <label>Tên doanh nghiệp<input type="text" name="name" value="{{ old('name', $business?->name) }}" required></label>
        <label>Slug<input type="text" name="slug" value="{{ old('slug', $business?->slug) }}" placeholder="beauty-studio" required></label>
        <label>Mô tả<textarea name="description" rows="4">{{ old('description', $business?->description) }}</textarea></label>
        <div class="form-grid-two">
            <label>Email liên hệ<input type="email" name="contact_email" value="{{ old('contact_email', $business?->contact_email) }}"></label>
            <label>Số điện thoại<input type="text" name="contact_phone" value="{{ old('contact_phone', $business?->contact_phone) }}"></label>
        </div>
        <label>Địa chỉ<input type="text" name="address_line" value="{{ old('address_line', $business?->address_line) }}"></label>
        <div class="form-grid-two">
            <label>Tên pháp lý<input type="text" name="company_name" value="{{ old('company_name', $business?->ownerProfile?->company_name) }}"></label>
            <label>Mã số thuế<input type="text" name="tax_code" value="{{ old('tax_code', $business?->ownerProfile?->tax_code) }}"></label>
        </div>
        <div class="action-row">
            <button class="button button-primary" type="submit">{{ $business ? 'Lưu hồ sơ' : 'Tạo hồ sơ' }}</button>
            <a class="button button-secondary" href="{{ route('owner.businesses.index') }}">Quay lại</a>
        </div>
    </form>

    @if ($business)
        <div class="management-nav">
            <a class="management-nav-card" href="{{ route('owner.catalog.index', $business) }}">
                <span class="card-label">Catalog</span>
                <strong>Dịch vụ & giá</strong>
                <span>Quản lý offering theo chi nhánh, biến thể và ảnh dịch vụ.</span>
            </a>
            <a class="management-nav-card" href="{{ route('owner.staff.index', $business) }}">
                <span class="card-label">Team</span>
                <strong>Nhân sự & capability</strong>
                <span>Quản lý hồ sơ, lời mời và dịch vụ nhân viên có thể thực hiện.</span>
            </a>
        </div>

        <div class="panel workflow-panel">
            <div>
                <p class="card-label">Quy trình kiểm duyệt</p>
                <h2>Gửi hồ sơ khi thông tin đã đầy đủ</h2>
                <p>Hồ sơ cần tên, email, số điện thoại, địa chỉ, tên pháp lý và mã số thuế trước khi gửi.</p>
            </div>
            @if (in_array($business->status->value, ['DRAFT', 'NEED_MORE_INFO'], true))
                <form method="POST" action="{{ route('owner.businesses.submit', $business) }}">
                    @csrf
                    <button class="button button-primary" type="submit">Gửi kiểm duyệt</button>
                </form>
            @endif
        </div>

        <div class="panel">
            <div class="section-heading-row compact-heading">
                <div>
                    <p class="card-label">Chi nhánh</p>
                    <h2>{{ $business->branches->count() }} chi nhánh</h2>
                </div>
                <a class="button button-secondary" href="{{ route('owner.branches.create', $business) }}">Thêm chi nhánh</a>
            </div>
            @if ($business->branches->isNotEmpty())
                <div class="stack-list">
                    @foreach ($business->branches as $branch)
                        <div class="list-row">
                            <div>
                                <strong>{{ $branch->public_name ?: $branch->name }}</strong>
                                <span>{{ $branch->review_status->value }} · {{ $branch->operational_status->value }}</span>
                            </div>
                            <a class="text-link" href="{{ route('owner.branches.edit', [$business, $branch]) }}">Mở</a>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif
</section>
@endsection
