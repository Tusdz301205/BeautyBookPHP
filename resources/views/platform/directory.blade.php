@extends('layouts.app')
@section('title', 'Danh bạ doanh nghiệp · BeautyBook')
@section('content')
<section class="page-shell"><h1>Danh bạ doanh nghiệp</h1>
<form class="panel" method="GET"><label>Tìm tên doanh nghiệp <input name="q" value="{{ $filters['q'] ?? '' }}"></label><label>Trạng thái <select name="status"><option value="">Tất cả</option>@foreach(['DRAFT','PENDING_REVIEW','NEED_MORE_INFO','APPROVED','ACTIVE','SUSPENDED','REJECTED'] as $status)<option @selected(($filters['status'] ?? '') === $status)>{{ $status }}</option>@endforeach</select></label><button class="button">Tìm kiếm</button></form>
@forelse($businesses as $business)<article class="panel"><h2>{{ $business->name }}</h2><p>{{ $business->status->value }} · {{ $business->branches_count }} chi nhánh</p><a href="{{ route('admin.reviews.business', $business) }}">Xem hồ sơ</a></article>@empty<p>Không có doanh nghiệp phù hợp.</p>@endforelse
{{ $businesses->links() }}</section>
@endsection
