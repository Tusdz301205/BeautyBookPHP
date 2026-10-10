@extends('layouts.app')

@section('title', ($branch ? 'Chi nhánh '.$branch->name : 'Tạo chi nhánh').' · BeautyBook')

@section('content')
@php
    $dayNames = ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'];
    $savedHours = $branch?->workingHours?->keyBy('day_of_week') ?? collect();
@endphp
<section class="page-shell owner-shell">
    <div class="page-heading">
        <p class="eyebrow">{{ $business->name }}</p>
        <h1>{{ $branch ? $branch->public_name : 'Tạo chi nhánh.' }}</h1>
        @if ($branch)
            <p>{{ $branch->review_status->value }} · {{ $branch->operational_status->value }} · {{ $branch->status->value }}</p>
            @if ($branch->review_note)
                <div class="notice danger-panel">Phản hồi kiểm duyệt: {{ $branch->review_note }}</div>
            @endif
        @endif
    </div>

    @if ($errors->any())
        <div class="notice danger-panel" role="alert">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <div class="detail-grid owner-edit-grid">
        <form class="form-panel form-stack" method="POST" action="{{ $branch ? route('owner.branches.update', [$business, $branch]) : route('owner.branches.store', $business) }}">
            @csrf
            @if ($branch)
                @method('PUT')
            @endif
            <p class="card-label">Thông tin chi nhánh</p>
            <label>Tên nội bộ<input type="text" name="name" value="{{ old('name', $branch?->name) }}" required></label>
            <label>Slug<input type="text" name="slug" value="{{ old('slug', $branch?->slug) }}" required></label>
            <label>Tên công khai<input type="text" name="public_name" value="{{ old('public_name', $branch?->public_name) }}" required></label>
            <label>Mô tả<textarea name="description" rows="4">{{ old('description', $branch?->description) }}</textarea></label>
            <label>Địa chỉ<input type="text" name="address_line" value="{{ old('address_line', $branch?->address_line) }}" required></label>
            <div class="form-grid-two">
                <label>Số điện thoại<input type="text" name="phone" value="{{ old('phone', $branch?->phone) }}" required></label>
                <label>Email<input type="email" name="email" value="{{ old('email', $branch?->email) }}"></label>
            </div>
            <label>Múi giờ
                <select name="timezone" required>
                    <option value="Asia/Ho_Chi_Minh" @selected(old('timezone', $branch?->timezone ?? 'Asia/Ho_Chi_Minh') === 'Asia/Ho_Chi_Minh')>Asia/Ho_Chi_Minh</option>
                </select>
            </label>
            <div class="action-row">
                <button class="button button-primary" type="submit">{{ $branch ? 'Lưu thông tin' : 'Tạo chi nhánh' }}</button>
                <a class="button button-secondary" href="{{ route('owner.businesses.edit', $business) }}">Về doanh nghiệp</a>
            </div>
        </form>

        @if ($branch)
            <div class="owner-side-stack">
                <form class="panel form-stack" method="POST" action="{{ route('owner.branches.hours', [$business, $branch]) }}">
                    @csrf
                    @method('PUT')
                    <div>
                        <p class="card-label">Giờ hoạt động</p>
                        <h2>Đủ 7 ngày trong tuần</h2>
                    </div>
                    <div class="hours-editor">
                        @foreach ($dayNames as $day => $label)
                            @php
                                $hour = $savedHours->get($day);
                                $closed = (bool) old("hours.$day.is_closed", $hour?->is_closed ?? ($day === 0));
                            @endphp
                            <div class="hours-editor-row">
                                <input type="hidden" name="hours[{{ $day }}][day_of_week]" value="{{ $day }}">
                                <div class="hours-day"><strong>{{ $label }}</strong></div>
                                <label class="check-row">
                                    <input type="hidden" name="hours[{{ $day }}][is_closed]" value="0">
                                    <input type="checkbox" name="hours[{{ $day }}][is_closed]" value="1" @checked($closed)>
                                    Đóng cửa
                                </label>
                                <input type="time" name="hours[{{ $day }}][open_time]" value="{{ old("hours.$day.open_time", $hour?->open_time ? substr($hour->open_time, 0, 5) : ($day === 0 ? '' : '09:00')) }}">
                                <input type="time" name="hours[{{ $day }}][close_time]" value="{{ old("hours.$day.close_time", $hour?->close_time ? substr($hour->close_time, 0, 5) : ($day === 0 ? '' : '20:00')) }}">
                            </div>
                        @endforeach
                    </div>
                    <button class="button button-secondary" type="submit">Lưu giờ hoạt động</button>
                </form>

                <div class="panel workflow-panel">
                    <div>
                        <p class="card-label">Trạng thái</p>
                        <h2>Bước tiếp theo</h2>
                        <p>Chi nhánh phải thuộc doanh nghiệp đã duyệt, đủ hồ sơ và đủ giờ mở cửa trước khi gửi review.</p>
                    </div>
                    <div class="action-row">
                        <a class="button button-secondary" href="{{ route('owner.combos.index', $branch) }}">Combo</a>
                        <a class="button button-secondary" href="{{ route('operations.waitlist', $branch) }}">Danh sách chờ</a>
                        <a class="button button-secondary" href="{{ route('owner.loyalty.index', $business) }}">Điểm tích lũy</a>
                        <a class="button button-secondary" href="{{ route('impacts.index') }}">Lịch bị ảnh hưởng</a>
                        @if (in_array($branch->review_status->value, ['DRAFT', 'NEED_MORE_INFO'], true))
                            <form method="POST" action="{{ route('owner.branches.submit', [$business, $branch]) }}">
                                @csrf
                                <button class="button button-primary" type="submit">Gửi kiểm duyệt</button>
                            </form>
                        @endif
                        @if ($branch->review_status->value === 'APPROVED' && $branch->operational_status->value === 'READY_TO_PUBLISH')
                            <form method="POST" action="{{ route('owner.branches.publish', [$business, $branch]) }}">
                                @csrf
                                <button class="button button-primary" type="submit">Công khai chi nhánh</button>
                            </form>
                        @endif
                    </div>
                    @if (in_array($branch->operational_status->value, ['ACTIVE', 'PAUSED', 'SUSPENDED'], true))
                        <form class="form-stack" method="POST" action="{{ route('owner.branches.operational', [$business, $branch]) }}">
                            @csrf
                            <label>Thao tác vận hành<select name="action">
                                @if ($branch->operational_status->value === 'ACTIVE')<option value="PAUSE">Tạm dừng nhận lịch</option>@else<option value="RESTORE">Khôi phục hoạt động</option>@endif
                                <option value="CLOSE">Đóng chi nhánh sau khi xử lý lịch</option>
                            </select></label>
                            <label>Lý do<textarea name="reason" required maxlength="2000"></textarea></label>
                            <button class="button button-secondary" type="submit">Cập nhật vận hành</button>
                        </form>
                    @endif
                </div>
            </div>
        @endif
    </div>
</section>
@endsection
