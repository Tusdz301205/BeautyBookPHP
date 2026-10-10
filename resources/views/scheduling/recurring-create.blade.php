@extends('layouts.app')
@section('title', 'Đặt lịch định kỳ · BeautyBook')
@section('content')
<section class="page-shell management-shell module-workspace">
    <div class="page-heading"><div><p class="eyebrow">{{ $branch->name }}</p><h1>Đặt lịch định kỳ</h1></div><a class="button button-primary" href="{{ route('recurring.index') }}">Chuỗi lịch của tôi</a></div>
    
    <form data-recurring-form class="form-stack" method="POST" action="{{ route('recurring.preview', $branch) }}">
        @csrf
        <p>Chọn một dịch vụ hoặc một combo. Sau đó chọn ngày, giờ và xem trước tất cả các kỳ.</p>
        <label>Dịch vụ <select name="branch_service_offering_id"><option value="">Không chọn dịch vụ lẻ</option>@foreach($offerings as $offering)<option value="{{ $offering->id }}" @selected(old('branch_service_offering_id') == $offering->id)>{{ $offering->service->name }}</option>@endforeach</select></label>
        <label>Combo <select name="combo_id"><option value="">Không chọn combo</option>@foreach($combos as $combo)<option value="{{ $combo->id }}" @selected(old('combo_id') == $combo->id)>{{ $combo->name }} · {{ number_format($combo->combo_price, 0, ',', '.') }} ₫</option>@endforeach</select></label>
        <label>Biến thể dịch vụ <select name="service_variant_id"><option value="">Dịch vụ cơ bản</option>@foreach($offerings as $offering)@foreach($offering->variants->where('status','ACTIVE')->where('price_type','FIXED') as $variant)<option data-offering="{{ $offering->id }}" value="{{ $variant->id }}" @selected(old('service_variant_id') == $variant->id)>{{ $variant->name }}</option>@endforeach @endforeach</select></label>
        <label>Chọn nhân viên <select name="staff_mode"><option value="SAME_STAFF" @selected(old('staff_mode','SAME_STAFF') === 'SAME_STAFF')>Cùng một nhân viên</option><option value="ANY_AVAILABLE" @selected(old('staff_mode') === 'ANY_AVAILABLE')>Bất kỳ nhân viên phù hợp</option></select></label>
        <label>Nhân viên ưu tiên <select name="staff_profile_id"><option value="">Chọn nhân viên phù hợp</option>@foreach($staff as $person)<option data-offerings="{{ $person->offerings->pluck('id')->implode(',') }}" value="{{ $person->id }}" @selected(old('staff_profile_id') == $person->id)>{{ $person->full_name }}</option>@endforeach</select></label>
        <p class="help-text" data-staff-hint role="status">Nhân viên được lọc theo dịch vụ. Khung giờ sẽ được kiểm tra trong bước xem trước.</p>
        <div data-recurring-combos hidden>@foreach($combos as $combo)<span data-combo="{{ $combo->id }}" data-offerings="{{ $combo->items->pluck('branch_service_offering_id')->implode(',') }}"></span>@endforeach</div>
        <label>Tần suất <select name="frequency"><option value="WEEKLY" @selected(old('frequency','WEEKLY') === 'WEEKLY')>Hàng tuần</option><option value="BIWEEKLY" @selected(old('frequency','WEEKLY') === 'BIWEEKLY')>Hai tuần một lần</option><option value="MONTHLY" @selected(old('frequency','WEEKLY') === 'MONTHLY')>Hàng tháng</option></select></label>
        <label>Ngày bắt đầu <input type="date" name="start_date" value="{{ old('start_date') }}" required></label>
        <label>Ngày kết thúc (tùy chọn) <input type="date" name="end_date" value="{{ old('end_date') }}"></label>
        <label>Giờ ưu tiên <input type="time" name="preferred_time" value="{{ old('preferred_time', '10:00') }}" required></label>
        <label>Số kỳ <input type="number" name="occurrence_count" min="2" max="52" value="{{ old('occurrence_count', 4) }}" required></label>
        <label>Ghi chú <textarea name="note">{{ old('note') }}</textarea></label>
        <button type="submit" class="button button-primary">Kiểm tra các kỳ trước khi đặt</button>
    </form>
</section>
@endsection
