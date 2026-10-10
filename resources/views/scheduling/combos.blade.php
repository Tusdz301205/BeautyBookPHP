@extends('layouts.app')
@section('title', 'Quản lý combo · BeautyBook')
@section('content')
<section class="page-shell management-shell module-workspace">
    <div class="page-heading"><div><p class="eyebrow">{{ $branch->name }}</p><h1>Quản lý combo</h1></div><a class="button button-primary" href="{{ route('combos.public', ['branch_id' => $branch->id]) }}">Xem trang công khai</a></div>
    
    
    <details class="panel module-disclosure" open><summary>Tạo combo</summary>
        <form class="form-stack" method="POST" action="{{ route('owner.combos.store', $branch) }}">
            @csrf
            @include('scheduling.combo-fields', ['combo' => null])
            <button type="submit" class="button button-primary">Tạo combo</button>
        </form>
    </details>
    @forelse($combos as $combo)
    <details class="panel module-disclosure"><summary>{{ $combo->name }} · @include('finance.status', ['value' => $combo->status]) · {{ number_format($combo->combo_price, 0, ',', '.') }} ₫</summary>
        <form class="form-stack" method="POST" action="{{ route('owner.combos.update', [$branch, $combo]) }}">
            @csrf @method('PUT')
            @include('scheduling.combo-fields', ['combo' => $combo])
            <p>Lượt sử dụng: {{ $combo->used_count }} · Phiên bản {{ $combo->version }}</p>
            <button class="button button-primary" type="submit">Lưu thay đổi</button>
        </form>
        <form class="form-stack" method="POST" action="{{ route('owner.combos.archive', [$branch, $combo]) }}">@csrf @method('DELETE')<button class="button button-secondary" type="submit">Lưu trữ combo</button></form>
    </details>
    @empty<div class="empty-state panel"><h2>Chưa có dữ liệu</h2><p>Chưa có combo.</p></div>@endforelse
    {{ $combos->links() }}
</section>
@endsection
