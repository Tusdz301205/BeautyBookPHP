@extends('layouts.app')

@section('title', $branch->public_name.' · BeautyBook')

@section('content')
<section class="page-shell detail-shell">
    <div class="page-heading detail-heading">
        <div>
            <p class="eyebrow">{{ $branch->business->name }}</p>
            <h1>{{ $branch->public_name }}</h1>
        </div>
        <p>{{ $branch->description }}</p>
    </div>

    <div class="detail-grid">
        <article class="panel">
            <h2>Dịch vụ</h2>
            <div class="stack-list">
                @foreach ($branch->offerings as $offering)
                    <div class="list-row">
                        <div><strong>{{ $offering->service->name }}</strong><span>{{ $offering->service->category->name }}</span></div>
                        <strong>{{ number_format((float) $offering->price, 0, ',', '.') }}đ</strong>
                        @if(auth()->user()?->account_kind->value === 'CUSTOMER')<a class="button button-secondary" href="{{ route('booking.create',['branch'=>$branch->id,'offering'=>$offering->id]) }}">Chọn dịch vụ</a>@endif
                        @if (auth()->check() && auth()->user()->account_kind->value === 'CUSTOMER')
                            <button type="button" class="button" data-favorite-url="{{ route('engagement.save', $offering) }}"
                                data-remove-url="{{ route('engagement.remove', $offering) }}" aria-pressed="{{ \App\Models\SavedService::where('customer_profile_id', auth()->user()->customerProfile?->id)->where('branch_service_offering_id', $offering->id)->exists() ? 'true' : 'false' }}">Lưu dịch vụ</button>
                        @endif
                    </div>
                @endforeach
            </div>
        </article>

        <aside class="panel detail-aside">
            <h2>Thông tin chi nhánh</h2>
            <p><a href="{{ route('combos.public', ['branch_id' => $branch->id]) }}">Combo dịch vụ</a></p>
            @if (auth()->check() && auth()->user()->account_kind->value === 'CUSTOMER')
                <p><a href="{{ route('recurring.create', $branch) }}">Đặt lịch định kỳ</a></p>
            @endif
            <p><a href="{{ route('engagement.public', $branch->business) }}">Xem đánh giá của khách</a></p>
            <p>{{ $branch->address_line }}</p>
            <p>{{ $branch->phone }}</p>
            <div class="hours-list">
                @foreach ($branch->workingHours as $hour)
                    <div><span>{{ ['Chủ nhật','Thứ hai','Thứ ba','Thứ tư','Thứ năm','Thứ sáu','Thứ bảy'][$hour->day_of_week] }}</span><strong>{{ $hour->is_closed ? 'Đóng cửa' : substr($hour->open_time, 0, 5).' – '.substr($hour->close_time, 0, 5) }}</strong></div>
                @endforeach
            </div>
            <div class="action-row">
                @if(auth()->user()?->account_kind->value === 'CUSTOMER' || (in_array($ui['role'],['BUSINESS_OWNER','RECEPTIONIST'],true) && $ui['workspace']?->business_id === $branch->business_id && (!$ui['workspace']?->branch_id || $ui['workspace']?->branch_id === $branch->id)))
                    <a class="button button-primary" href="{{ route('booking.create', $branch) }}">Đặt lịch tại chi nhánh này</a>
                @elseif(!auth()->check())
                    <a class="button button-primary" href="{{ route('login') }}">Đăng nhập để đặt lịch</a>
                @else
                    <a class="button button-secondary" href="{{ route('app') }}">Về không gian của tôi</a>
                @endif
            </div>
        </aside>
    </div>
</section>
@endsection
