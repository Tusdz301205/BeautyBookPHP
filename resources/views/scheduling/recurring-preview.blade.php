@extends('layouts.app')
@section('title', 'Kiểm tra lịch định kỳ · BeautyBook')
@section('content')
<section class="page-shell management-shell module-workspace">
    <div class="page-heading"><div><p class="eyebrow">Lịch định kỳ</p><h1>Kiểm tra chuỗi lịch</h1><p>Xem từng kỳ trước khi xác nhận. Các kỳ xung đột cần được chọn lại hoặc bỏ qua.</p></div></div>
    <p>{{ $preview['available_count'] }} kỳ khả dụng · {{ $preview['conflict_count'] }} kỳ xung đột</p>
    <ul>@foreach($preview['occurrences'] as $occurrence)<li>{{ $occurrence['start_at'] }} · {{ $occurrence['available'] ? 'Còn chỗ' : $occurrence['reason'] }}</li>@endforeach</ul>
    <form class="form-stack" method="POST" action="{{ route('recurring.store', $branch) }}">
        @csrf
        @foreach($data as $name => $value)
            @if($value !== null && !in_array($name, ['skip_conflicts', 'violation_acknowledged']))
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endif
        @endforeach
        <input type="hidden" name="idempotency_key" value="{{ $key }}">
        <label class="check-row"><input type="checkbox" name="skip_conflicts" value="1"> Bỏ qua các kỳ xung đột</label>
        <label class="check-row"><input type="checkbox" name="violation_acknowledged" value="1"> Tôi đã hiểu cảnh báo vi phạm đặt lịch (nếu có)</label>
        <p>Hệ thống sẽ kiểm tra lại giá, nhân viên và khung giờ khi tạo từng kỳ.</p>
        <button class="button button-primary" type="submit" @disabled($preview['available_count'] === 0)>Xác nhận tạo chuỗi lịch</button>
    </form>
    <a href="{{ route('recurring.create', $branch) }}">Chọn lại ngày và giờ</a>
</section>
@endsection
