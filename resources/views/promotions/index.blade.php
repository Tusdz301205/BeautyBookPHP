@extends('layouts.app')
@section('title', 'Ưu đãi · BeautyBook')
@section('content')
@php($prefix = $business ? 'owner.' : 'admin.')
@php($params = $business ? ['business' => $business] : [])
<section class="page-shell management-shell module-workspace">
    <div class="page-heading"><div><p class="eyebrow">{{ $business?->name ?? 'Nền tảng BeautyBook' }}</p><h1>Khuyến mãi và voucher</h1><p>Quản lý đối tượng, phạm vi dịch vụ, giới hạn lượt dùng và thời gian áp dụng.</p></div></div>
    
    
    <div class="action-row"><a class="button button-secondary" href="{{ route($prefix.'promotions.index', $params) }}">Khuyến mãi tự động</a><a class="button button-secondary" href="{{ route($prefix.'promotions.index', $params + ['type' => 'voucher']) }}">Voucher</a></div>
    <form class="form-stack filter-panel" method="get"><input type="hidden" name="type" value="{{ $type }}"><label>Tìm tên<input type="text" name="search" value="{{ request('search') }}"></label><label>Trạng thái<select name="status"><option value="">Tất cả</option>@foreach(['ACTIVE','INACTIVE','EXPIRED','REVOKED'] as $status)<option @selected(request('status') === $status)>{{ $status }}</option>@endforeach</select></label><button class="button button-primary">Tìm kiếm</button></form>
    <details class="panel" open><summary>Tạo {{ $type === 'voucher' ? 'voucher' : 'khuyến mãi' }}</summary><form class="form-grid" method="post" action="{{ route($prefix.'promotions.store', $params) }}">@csrf<input type="hidden" name="type" value="{{ $type }}">
        <label>Tên<input type="text" name="name" required maxlength="255"></label>
        @if($type === 'voucher')<label>Mã voucher<input type="text" name="code" required maxlength="64" pattern="[A-Za-z0-9_-]+"></label><label>Số lượt tổng<input type="number" name="total_quantity" min="1" required></label><label>Đơn tối thiểu<input type="number" name="min_order_value" step="0.01" min="0" value="0"></label><label>Giảm tối đa<input type="number" name="max_discount" step="0.01" min="0.01"></label>@else<label>Số lượt tổng (trống: không giới hạn)<input type="number" name="total_quantity" min="1"></label>@endif
        <label>Kiểu giảm<select name="discount_type"><option value="PERCENTAGE">Phần trăm</option><option value="FIXED_AMOUNT">Số tiền cố định</option></select></label><label>Giá trị giảm<input type="number" name="discount_value" step="0.01" min="0.01" required></label>
        <label>Bắt đầu<input type="datetime-local" name="start_date" required></label><label>Kết thúc<input type="datetime-local" name="end_date" required></label><label>Giới hạn mỗi khách<input type="number" name="max_usage_per_customer" min="1" value="1" required></label>
        <label>Đối tượng<select name="audience">@foreach(['ALL'=>'Tất cả','NEW_CUSTOMER'=>'Khách mới','RETURNING_CUSTOMER'=>'Khách quay lại','BIRTHDAY'=>'Sinh nhật','VIP'=>'VIP','SELECTED'=>'Khách được chọn'] as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
        @if($business)<label>Chi nhánh (không chọn: tất cả)<select name="branch_ids[]" multiple>@foreach($branches as $branch)<option value="{{ $branch->id }}">{{ $branch->name }}</option>@endforeach</select></label><label>Dịch vụ (không chọn: tất cả)<select name="service_ids[]" multiple>@foreach($offerings as $offering)<option value="{{ $offering->id }}">{{ $offering->service->name }} · #{{ $offering->branch_id }}</option>@endforeach</select></label>@endif
        <label>Mô tả<textarea name="description" maxlength="3000"></textarea></label>
        @if($type === 'promotion')<input type="hidden" name="auto_apply" value="1"><label class="check-row"><input type="checkbox" name="stacking_allowed" value="1"> Cho phép cộng dồn voucher</label>@else<label class="check-row"><input type="checkbox" name="auto_issue" value="1"> Tự cấp cho khách phù hợp</label>@endif
        <button class="button button-primary">Tạo ưu đãi</button>
    </form></details>
    <div class="card-grid">@forelse($rules as $rule)<article class="panel"><h2>{{ $rule->name }}</h2><p>{{ $rule->description }}</p><p>{{ $rule->code }} · {{ $rule->discount_value }} {{ $rule->discount_type === 'PERCENTAGE' ? '%' : 'VND' }}</p><p>{{ $rule->start_date->format('d/m/Y H:i') }} – {{ $rule->end_date->format('d/m/Y H:i') }}</p><p>@include('finance.status', ['value' => $rule->status]) · {{ $rule->audience }} · {{ $rule->max_usage_per_customer }} lượt/khách · Phiên bản {{ $rule->version }}</p>
        <form class="form-stack" method="post" action="{{ route($prefix.'promotions.update', $params + ['type'=>$type,'id'=>$rule->id]) }}">@csrf @method('PUT')<label>Trạng thái<select name="status">@foreach($type === 'voucher' ? ['ACTIVE','EXPIRED','REVOKED'] : ['ACTIVE','INACTIVE','EXPIRED'] as $status)<option @selected($rule->status === $status)>{{ $status }}</option>@endforeach</select></label><label>Quota tổng<input name="total_quantity" type="number" min="1" value="{{ $rule->total_quantity }}"></label><label>Lượt mỗi khách<input name="max_usage_per_customer" type="number" min="1" value="{{ $rule->max_usage_per_customer }}"></label><button class="button button-secondary">Cập nhật</button></form>
        <form class="form-stack" method="post" action="{{ route($prefix.'promotions.destroy', $params + ['type'=>$type,'id'=>$rule->id]) }}">@csrf @method('DELETE')<button class="button button-secondary">Lưu trữ</button></form>
    </article>@empty<div class="empty-state panel"><h2>Chưa có dữ liệu</h2><p>Chưa có ưu đãi phù hợp.</p></div>@endforelse</div>{{ $rules->links() }}
</section>
@endsection
