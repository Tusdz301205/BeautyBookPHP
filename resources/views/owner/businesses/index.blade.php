@extends('layouts.app')

@section('title', 'Doanh nghiệp của tôi · BeautyBook')

@section('content')
<section class="page-shell owner-shell">
    <div class="page-heading section-heading-row">
        <div>
            <p class="eyebrow">Business owner</p>
            <h1>Doanh nghiệp của tôi.</h1>
            <p>Quản lý hồ sơ doanh nghiệp, chi nhánh và trạng thái kiểm duyệt.</p>
        </div>
        <a class="button button-primary" href="{{ route('owner.businesses.create') }}">Tạo doanh nghiệp</a>
    </div>

    @if ($businesses->isEmpty())
        <div class="notice">Bạn chưa có doanh nghiệp nào. Tạo hồ sơ để bắt đầu quy trình onboarding.</div>
    @else
        <div class="owner-grid">
            @foreach ($businesses as $business)
                <article class="panel owner-card">
                    <div class="section-heading-row compact-heading">
                        <div>
                            <p class="card-label">{{ $business->status->value }}</p>
                            <h2>{{ $business->name }}</h2>
                        </div>
                        <a class="text-link" href="{{ route('owner.businesses.edit', $business) }}">Mở hồ sơ</a>
                    </div>
                    <p>{{ $business->address_line ?: 'Chưa có địa chỉ doanh nghiệp' }}</p>
                    <p><a href="{{ route('owner.promotions.index', $business) }}">Khuyến mãi và voucher</a></p>
                    <div class="branch-summary">
                        <span>{{ $business->branches->count() }} chi nhánh</span>
                        <a class="button button-secondary" href="{{ route('owner.branches.create', $business) }}">Thêm chi nhánh</a>
                    </div>
                    @if ($business->branches->isNotEmpty())
                        <div class="stack-list">
                            @foreach ($business->branches as $branch)
                                <div class="list-row">
                                    <div>
                                        <strong>{{ $branch->public_name ?: $branch->name }}</strong>
                                        <span>{{ $branch->review_status->value }} · {{ $branch->operational_status->value }}</span>
                                    </div>
                                    <a class="text-link" href="{{ route('owner.branches.edit', [$business, $branch]) }}">Quản lý</a>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
</section>
@endsection
