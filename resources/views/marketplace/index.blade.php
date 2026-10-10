@extends('layouts.app')

@section('title', 'Khám phá · BeautyBook')

@section('content')
<section class="page-shell marketplace-shell">
    <div class="page-heading marketplace-heading">
        <div>
            <p class="eyebrow">Khám phá</p>
            <h1>Tìm nơi làm đẹp phù hợp</h1>
        </div>
        <p>Khám phá dịch vụ và địa điểm, lọc theo khu vực hoặc mức giá phù hợp với bạn.</p>
    </div>

    <form class="filter-panel" method="GET" action="{{ route('marketplace.index') }}">
        <label>Từ khóa<input name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Tên salon hoặc dịch vụ"></label>
        <label>Khu vực<input name="location" value="{{ $filters['location'] ?? '' }}" placeholder="Bình Tân, Quận 3..."></label>
        <label>Danh mục
            <select name="category">
                <option value="">Tất cả</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->slug }}" @selected(($filters['category'] ?? '') === $category->slug)>{{ $category->name }}</option>
                @endforeach
            </select>
        </label>
        <label>Giá từ<input type="number" min="0" step="10000" name="min_price" value="{{ $filters['min_price'] ?? '' }}"></label>
        <label>Giá đến<input type="number" min="0" step="10000" name="max_price" value="{{ $filters['max_price'] ?? '' }}"></label>
        <button class="button button-primary" type="submit">Tìm kiếm</button>
    </form>

    @if ($branches->count())
        <div class="market-grid">
            @foreach ($branches as $branch)
                <x-branch-card :branch="$branch"/>
            @endforeach
        </div>
        <div class="pagination-wrap">{{ $branches->links() }}</div>
    @else
        <div class="notice empty-state">Không có chi nhánh phù hợp với bộ lọc hiện tại.</div>
    @endif
</section>
@endsection
