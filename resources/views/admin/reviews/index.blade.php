@extends('layouts.app')

@section('title', 'Hàng đợi kiểm duyệt · BeautyBook')

@section('content')
<section class="page-shell admin-shell">
    <div class="page-heading">
        <p class="eyebrow">Platform admin</p>
        <h1>Hàng đợi kiểm duyệt.</h1>
        <p>Chỉ hồ sơ đang ở trạng thái PENDING_REVIEW xuất hiện trong danh sách này.</p>
    </div>

    <div class="review-grid">
        <article class="panel">
            <div class="section-heading-row compact-heading">
                <div>
                    <p class="card-label">Doanh nghiệp</p>
                    <h2>{{ $businesses->count() }} hồ sơ</h2>
                </div>
            </div>
            <div class="stack-list">
                @forelse ($businesses as $business)
                    <div class="list-row">
                        <div>
                            <strong>{{ $business->name }}</strong>
                            <span>{{ $business->ownerProfile?->user?->email }} · {{ optional($business->submitted_at)->format('d/m/Y H:i') }}</span>
                        </div>
                        <a class="text-link" href="{{ route('admin.reviews.business', $business) }}">Review</a>
                    </div>
                @empty
                    <p>Không có hồ sơ doanh nghiệp chờ xử lý.</p>
                @endforelse
            </div>
        </article>

        <article class="panel">
            <div class="section-heading-row compact-heading">
                <div>
                    <p class="card-label">Chi nhánh</p>
                    <h2>{{ $branches->count() }} hồ sơ</h2>
                </div>
            </div>
            <div class="stack-list">
                @forelse ($branches as $branch)
                    <div class="list-row">
                        <div>
                            <strong>{{ $branch->public_name }}</strong>
                            <span>{{ $branch->business->name }} · {{ optional($branch->submitted_at)->format('d/m/Y H:i') }}</span>
                        </div>
                        <a class="text-link" href="{{ route('admin.reviews.branch', $branch) }}">Review</a>
                    </div>
                @empty
                    <p>Không có hồ sơ chi nhánh chờ xử lý.</p>
                @endforelse
            </div>
        </article>
    </div>
</section>
@endsection
