@extends('layouts.app')
@section('title', 'Ảnh hưởng vận hành · BeautyBook')
@section('content')
<section class="page-shell management-shell module-workspace">
    <div class="page-heading"><div><p class="eyebrow">Giữ lịch hẹn được xử lý đầy đủ</p><h1>Ảnh hưởng vận hành</h1></div></div>
    @forelse($cases as $case)
    <article class="service-card"><h2><a href="{{ route('impacts.show', $case) }}">{{ $case->branch->name }} · #{{ $case->id }}</a></h2><p>{{ $case->trigger_type }} · @include('finance.status', ['value' => $case->status])</p><p>{{ $case->reason }}</p><p>{{ $case->pending_count }} / {{ $case->items_count }} lịch hẹn chờ xử lý · Hạn: {{ $case->deadline_at->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }}</p></article>
    @empty<div class="empty-state panel"><h2>Chưa có dữ liệu</h2><p>Chưa có hồ sơ ảnh hưởng vận hành.</p></div>@endforelse
    {{ $cases->links() }}
</section>
@endsection
