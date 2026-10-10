@extends('layouts.app')
@section('title', 'Đặt combo · BeautyBook')
@section('content')
<section class="page-shell management-shell module-workspace">
    <div class="page-heading"><div><p class="eyebrow">{{ $branch->name }}</p><h1>{{ $combo->name }}</h1></div></div>
    <p>{{ number_format($combo->combo_price, 0, ',', '.') }} ₫ · {{ $combo->duration_minutes }} phút</p>
    
    <form class="form-stack" method="POST" action="{{ route('combos.booking.store', [$branch, $combo]) }}">
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $key) }}">
        <label>Thời gian bắt đầu <input type="datetime-local" name="start_at" value="{{ old('start_at') }}" required></label>
        @if($combo->staff_assignment_mode === 'SINGLE_PROVIDER')
        <label>Nhân viên thực hiện <select name="staff_profile_id" required><option value="">Chọn nhân viên</option>
            @foreach($staff as $person)
                @if($combo->items->every(fn ($item) => $person->offerings->contains('id', $item->branch_service_offering_id)))
                    <option value="{{ $person->id }}">{{ $person->full_name }}</option>
                @endif
            @endforeach
        </select></label>
        @endif
        @foreach($combo->items as $item)
        <fieldset><legend>{{ $item->offering->service->name }} × {{ $item->quantity }}</legend>
            @if($combo->staff_assignment_mode === 'PER_SERVICE_PROVIDER')
            <label>Nhân viên <select name="staff_assignments[{{ $item->branch_service_offering_id }}]" required><option value="">Chọn nhân viên</option>
                @foreach($staff as $person)
                    @if($person->offerings->contains('id', $item->branch_service_offering_id))
                        <option value="{{ $person->id }}">{{ $person->full_name }}</option>
                    @endif
                @endforeach
            </select></label>
            @endif
            <label>Biến thể <select name="variant_assignments[{{ $item->branch_service_offering_id }}]"><option value="">Dịch vụ cơ bản</option>@foreach($item->offering->variants->where('status', 'ACTIVE')->where('price_type', 'FIXED') as $variant)<option value="{{ $variant->id }}">{{ $variant->name }}</option>@endforeach</select></label>
        </fieldset>
        @endforeach
        <label>Voucher <input type="text" name="voucher_code" value="{{ old('voucher_code') }}" maxlength="64"></label>
        @if($context['kind'] === 'CUSTOMER')<label>Điểm muốn đổi <input type="number" name="loyalty_points" min="0" value="{{ old('loyalty_points', 0) }}"></label>@endif
        @if($context['kind'] !== 'CUSTOMER')
        <label>Tên khách <input type="text" name="contact[full_name]" required></label><label>Điện thoại <input type="text" name="contact[phone]"></label><label>Email <input type="email" name="contact[email]"></label>
        @if($context['kind'] === 'RECEPTIONIST')<label>Kênh đặt <select name="source"><option value="WALK_IN">Tại quầy</option><option value="PHONE">Qua điện thoại</option></select></label>@endif
        @endif
        <label>Ghi chú <textarea name="note">{{ old('note') }}</textarea></label>
        @if($context['kind'] === 'CUSTOMER')<label class="check-row"><input type="checkbox" name="violation_acknowledged" value="1"> Tôi đã hiểu cảnh báo vi phạm đặt lịch (nếu có)</label>@endif
        <button class="button button-primary" type="submit">Xác nhận đặt combo</button>
    </form>
</section>
@endsection
