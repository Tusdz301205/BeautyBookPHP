<label>Tên combo <input name="name" value="{{ $combo?->name }}" required minlength="2" maxlength="255"></label>
<label>Mô tả <textarea name="description">{{ $combo?->description }}</textarea></label>
<label>Giá combo (₫) <input name="combo_price" type="number" min="0" step="0.01" value="{{ $combo?->combo_price }}" required></label>
<label>Phân công nhân viên <select name="staff_assignment_mode"><option value="SINGLE_PROVIDER" @selected($combo?->staff_assignment_mode === 'SINGLE_PROVIDER')>Một người thực hiện</option><option value="PER_SERVICE_PROVIDER" @selected($combo?->staff_assignment_mode === 'PER_SERVICE_PROVIDER')>Theo từng dịch vụ</option></select></label>
<label>Trạng thái <select name="status">@foreach(['ACTIVE' => 'Hoạt động', 'INACTIVE' => 'Chưa kích hoạt', 'PAUSED' => 'Tạm dừng', 'EXPIRED' => 'Hết hạn'] as $value => $label)<option value="{{ $value }}" @selected(($combo?->status ?? 'INACTIVE') === $value)>{{ $label }}</option>@endforeach</select></label>
<label>Áp dụng từ <input type="datetime-local" name="valid_from" value="{{ $combo?->valid_from?->format('Y-m-d\TH:i') }}"></label>
<label>Áp dụng đến <input type="datetime-local" name="valid_to" value="{{ $combo?->valid_to?->format('Y-m-d\TH:i') }}"></label>
<label>Giới hạn lượt dùng <input type="number" min="1" name="max_usage" value="{{ $combo?->max_usage }}"></label>
@php($items = $combo ? $combo->items : [null, null])
<div data-combo-items>
<div class="section-heading"><h3>Dịch vụ trong combo</h3><p>Thêm từ 2 đến 20 dịch vụ, theo thứ tự thực hiện. Phút chuyển tiếp nằm giữa các dịch vụ.</p></div>
<div data-combo-rows>
@foreach($items as $index => $item)
<fieldset data-combo-row><legend>Dịch vụ {{ $index + 1 }}</legend>
    <label>Dịch vụ <select name="items[{{ $index }}][branch_service_offering_id]" required><option value="">Chọn dịch vụ</option>@foreach($offerings as $offering)<option value="{{ $offering->id }}" @selected($item?->branch_service_offering_id === $offering->id)>{{ $offering->service->name }}</option>@endforeach</select></label>
    <label>Số lượng <input type="number" name="items[{{ $index }}][quantity]" min="1" max="10" value="{{ $item?->quantity ?? 1 }}" required></label>
    <label>Phút chuyển tiếp <input type="number" name="items[{{ $index }}][transition_minutes]" min="0" max="120" value="{{ $item?->transition_minutes ?? 0 }}"></label>
    <input type="hidden" name="items[{{ $index }}][sort_order]" value="{{ $index }}">
    <button class="button button-secondary" type="button" data-combo-remove>Bỏ dịch vụ</button>
</fieldset>
@endforeach
</div>
<button type="button" class="button button-secondary" data-combo-add>Thêm dịch vụ</button>
<p class="help-text" data-combo-count role="status"></p>
</div>
