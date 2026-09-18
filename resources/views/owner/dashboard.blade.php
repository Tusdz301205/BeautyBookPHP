<x-owner-layout>
    <x-slot name="title">Bảng điều khiển Quản trị Salon</x-slot>

    <!-- Welcome & Overview Banner -->
    <div class="bg-gradient-to-r from-stone-900 via-stone-800 to-stone-900 rounded-3xl p-6 sm:p-8 text-white border border-stone-800 shadow-xl">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <span class="text-xs uppercase font-extrabold tracking-wider text-pink-400">Trung tâm Điều hành</span>
                <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight mt-1">{{ $business->name }} 🏢</h1>
                <p class="text-xs text-stone-400 mt-1">Tổng quan doanh thu, lịch hẹn và hiệu suất các chi nhánh</p>
            </div>

            <div class="flex items-center gap-3">
                <a href="{{ route('owner.services.create') }}" class="px-4 py-2.5 bg-pink-600 hover:bg-pink-700 text-white font-bold text-xs rounded-xl shadow-xs transition">
                    + Thêm Dịch vụ
                </a>
                <a href="{{ route('owner.branches.create') }}" class="px-4 py-2.5 bg-stone-800 hover:bg-stone-700 text-stone-200 border border-stone-700 font-bold text-xs rounded-xl transition">
                    + Thêm Chi nhánh
                </a>
            </div>
        </div>

        <!-- Metric Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-8 pt-6 border-t border-stone-800">
            <div class="p-4 bg-stone-800/60 rounded-2xl border border-stone-700/50">
                <span class="text-xs text-stone-400 block">Doanh thu hoàn thành</span>
                <span class="text-xl sm:text-2xl font-black text-pink-400 mt-1 block font-mono">
                    {{ number_format($stats['total_revenue'], 0, ',', '.') }} đ
                </span>
            </div>
            <div class="p-4 bg-stone-800/60 rounded-2xl border border-stone-700/50">
                <span class="text-xs text-stone-400 block">Doanh thu tháng này</span>
                <span class="text-xl sm:text-2xl font-black text-emerald-400 mt-1 block font-mono">
                    {{ number_format($stats['monthly_revenue'], 0, ',', '.') }} đ
                </span>
            </div>
            <div class="p-4 bg-stone-800/60 rounded-2xl border border-stone-700/50">
                <span class="text-xs text-stone-400 block">Chờ xác nhận</span>
                <span class="text-xl sm:text-2xl font-black text-amber-400 mt-1 block font-mono">
                    {{ $stats['pending_bookings'] }}
                </span>
            </div>
            <div class="p-4 bg-stone-800/60 rounded-2xl border border-stone-700/50">
                <span class="text-xs text-stone-400 block">Chi nhánh / Dịch vụ / NV</span>
                <span class="text-xl sm:text-2xl font-black text-stone-200 mt-1 block font-mono">
                    {{ $stats['branches_count'] }} / {{ $stats['services_count'] }} / {{ $stats['staff_count'] }}
                </span>
            </div>
        </div>
    </div>

    <!-- 2 Column Layout: Today Schedule + Unreplied Reviews -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        
        <!-- Today's Appointments (2 cols) -->
        <div class="lg:col-span-2 bg-white rounded-3xl p-6 border border-stone-200/80 shadow-xs space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-lg font-bold text-stone-900 flex items-center gap-2">
                        <span>🗓</span>
                        <span>Lịch hẹn hôm nay ({{ $todayBookings->count() }})</span>
                    </h2>
                    <p class="text-xs text-stone-400 mt-0.5">Lịch phục vụ khách hàng trong ngày hôm nay</p>
                </div>
                <a href="{{ route('owner.bookings.index') }}" class="text-xs font-bold text-pink-600 hover:text-pink-700">
                    Toàn bộ lịch hẹn →
                </a>
            </div>

            @if($todayBookings->isEmpty())
                <div class="text-center py-12 text-stone-400 text-xs">
                    Hôm nay chưa có lịch hẹn nào.
                </div>
            @else
                <div class="divide-y divide-stone-100">
                    @foreach($todayBookings as $bk)
                        <div class="py-3.5 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                            <div class="space-y-1">
                                <div class="flex items-center gap-2">
                                    <span class="font-mono text-xs font-bold text-stone-700">{{ $bk->booking_code }}</span>
                                    {!! $bk->status_badge !!}
                                    <span class="text-xs text-stone-500">• <strong>{{ $bk->customer->name }}</strong> ({{ $bk->customer->phone ?? 'Không có SĐT' }})</span>
                                </div>
                                <div class="text-sm font-bold text-stone-900">
                                    {{ $bk->bookingServices->first()?->service_name }}
                                </div>
                                <div class="text-xs text-stone-400 flex items-center gap-3">
                                    <span>📍 {{ $bk->branch->name }}</span>
                                    <span>⏰ {{ $bk->appointment_date->format('H:i') }}</span>
                                    @if($bk->bookingServices->first()?->staff)
                                        <span>👤 {{ $bk->bookingServices->first()->staff->name }}</span>
                                    @endif
                                </div>
                            </div>

                            <div class="flex items-center gap-3 self-end sm:self-center">
                                <span class="font-bold text-sm text-stone-900">{{ number_format($bk->total_price, 0, ',', '.') }} đ</span>
                                <a href="{{ route('owner.bookings.show', $bk->id) }}" class="px-3 py-1.5 bg-stone-100 hover:bg-stone-200 text-stone-700 text-xs font-bold rounded-xl transition">
                                    Xử lý
                                </a>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <!-- Unreplied Reviews & Quick Actions (1 col) -->
        <div class="space-y-6">
            <div class="bg-white rounded-3xl p-6 border border-stone-200/80 shadow-xs space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-base font-bold text-stone-900 flex items-center gap-2">
                        <span>⭐</span>
                        <span>Đánh giá cần phản hồi ({{ $unrepliedReviews->count() }})</span>
                    </h3>
                    <a href="{{ route('owner.reviews.index') }}" class="text-xs font-bold text-pink-600 hover:text-pink-700">
                        Tất cả →
                    </a>
                </div>

                @if($unrepliedReviews->isEmpty())
                    <div class="text-center py-8 text-stone-400 text-xs">
                        Đã phản hồi toàn bộ đánh giá từ khách hàng! 🎉
                    </div>
                @else
                    <div class="space-y-3">
                        @foreach($unrepliedReviews as $rev)
                            <div class="p-3.5 rounded-2xl bg-stone-50 border border-stone-100 space-y-1.5">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-bold text-stone-800">{{ $rev->customer->name }}</span>
                                    <span class="text-amber-400 font-bold text-xs">{{ str_repeat('★', $rev->overall_rating) }}</span>
                                </div>
                                <p class="text-xs text-stone-600 line-clamp-2 leading-relaxed">{{ $rev->comment }}</p>
                                <div class="flex items-center justify-between pt-1">
                                    <span class="text-[10px] text-stone-400">{{ $rev->branch->name }}</span>
                                    <a href="{{ route('owner.reviews.index') }}" class="text-[11px] font-bold text-pink-600 hover:underline">
                                        Phản hồi ngay →
                                    </a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <!-- Quick Action Links -->
            <div class="bg-white rounded-3xl p-6 border border-stone-200/80 shadow-xs space-y-3">
                <h3 class="text-sm font-bold text-stone-900">Thao tác nhanh</h3>
                <div class="grid grid-cols-2 gap-2 text-xs">
                    <a href="{{ route('owner.services.create') }}" class="p-3 bg-pink-50 text-pink-700 font-bold rounded-xl hover:bg-pink-100 transition text-center">
                        + Dịch vụ mới
                    </a>
                    <a href="{{ route('owner.staff.create') }}" class="p-3 bg-purple-50 text-purple-700 font-bold rounded-xl hover:bg-purple-100 transition text-center">
                        + Thêm nhân viên
                    </a>
                    <a href="{{ route('owner.branches.create') }}" class="p-3 bg-stone-100 text-stone-800 font-bold rounded-xl hover:bg-stone-200 transition text-center">
                        + Chi nhánh mới
                    </a>
                    <a href="{{ route('owner.categories.index') }}" class="p-3 bg-stone-100 text-stone-800 font-bold rounded-xl hover:bg-stone-200 transition text-center">
                        📑 Quản lý danh mục
                    </a>
                </div>
            </div>
        </div>

    </div>
</x-owner-layout>
