<x-customer-layout>
    <x-slot name="title">Bảng điều khiển Khách hàng</x-slot>

    <!-- Welcome & Stats Overview -->
    <div class="bg-gradient-to-r from-pink-600 via-rose-500 to-purple-600 rounded-3xl p-6 sm:p-8 text-white shadow-lg shadow-pink-500/15">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <span class="text-xs font-semibold uppercase tracking-wider text-pink-200">Chào mừng bạn trở lại,</span>
                <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight mt-1">{{ auth()->user()->name }} 👋</h1>
                <p class="text-xs text-pink-100 mt-1">Chúc bạn có một ngày làm đẹp thật thư thái và rạng rỡ!</p>
            </div>
            <a href="{{ route('customer.bookings.create') }}" class="inline-flex items-center gap-2 px-5 py-3 bg-white text-pink-600 hover:bg-pink-50 font-bold text-xs rounded-2xl shadow-md transition self-start sm:self-center">
                <span>✨</span> Đặt lịch mới ngay
            </a>
        </div>

        <!-- 4 Stats Cards -->
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mt-8 pt-6 border-t border-white/20">
            <div class="p-3 bg-white/10 backdrop-blur-md rounded-2xl">
                <span class="text-xs text-pink-200 block">Lịch hẹn sắp tới</span>
                <span class="text-2xl font-black mt-1 block">{{ $stats['upcoming'] }}</span>
            </div>
            <div class="p-3 bg-white/10 backdrop-blur-md rounded-2xl">
                <span class="text-xs text-pink-200 block">Đã hoàn thành</span>
                <span class="text-2xl font-black mt-1 block">{{ $stats['completed'] }}</span>
            </div>
            <div class="p-3 bg-white/10 backdrop-blur-md rounded-2xl">
                <span class="text-xs text-pink-200 block">Tổng lượt đặt</span>
                <span class="text-2xl font-black mt-1 block">{{ $stats['total_bookings'] }}</span>
            </div>
            <div class="p-3 bg-white/10 backdrop-blur-md rounded-2xl">
                <span class="text-xs text-pink-200 block">Dịch vụ yêu thích</span>
                <span class="text-2xl font-black mt-1 block">{{ $stats['saved_services'] }}</span>
            </div>
        </div>
    </div>

    <!-- Upcoming Appointments Section -->
    <div class="bg-white rounded-3xl p-6 border border-stone-200/80 shadow-xs space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-bold text-stone-900 flex items-center gap-2">
                <span>⏰</span>
                <span>Lịch hẹn sắp tới ({{ $upcomingBookings->count() }})</span>
            </h2>
            <a href="{{ route('customer.bookings.index') }}" class="text-xs font-bold text-pink-600 hover:text-pink-700">
                Xem tất cả →
            </a>
        </div>

        @if($upcomingBookings->isEmpty())
            <div class="text-center py-10 text-stone-400 text-xs">
                Bạn chưa có lịch hẹn sắp tới nào. Hãy tìm dịch vụ và đặt lịch ngay!
            </div>
        @else
            <div class="divide-y divide-stone-100">
                @foreach($upcomingBookings as $bk)
                    <div class="py-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                        <div class="space-y-1">
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-xs font-bold text-stone-700">{{ $bk->booking_code }}</span>
                                {!! $bk->status_badge !!}
                            </div>
                            <h4 class="font-bold text-sm text-stone-900">
                                {{ $bk->bookingServices->first()?->service_name ?? 'Dịch vụ làm đẹp' }}
                            </h4>
                            <div class="text-xs text-stone-500 flex flex-wrap gap-x-3 gap-y-1">
                                <span>📍 {{ $bk->branch?->name }}</span>
                                <span>🗓 {{ $bk->appointment_date->format('d/m/Y H:i') }}</span>
                                @if($bk->bookingServices->first()?->staff)
                                    <span>👤 {{ $bk->bookingServices->first()->staff->name }}</span>
                                @endif
                            </div>
                        </div>

                        <div class="flex items-center gap-3 self-end sm:self-center">
                            <span class="text-sm font-extrabold text-stone-900">{{ number_format($bk->total_price, 0, ',', '.') }} đ</span>
                            <a href="{{ route('customer.bookings.show', $bk->id) }}" class="px-3 py-1.5 bg-stone-100 hover:bg-stone-200 text-stone-700 text-xs font-bold rounded-xl transition">
                                Chi tiết
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <!-- Recent History Section -->
    <div class="bg-white rounded-3xl p-6 border border-stone-200/80 shadow-xs space-y-4">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-bold text-stone-900 flex items-center gap-2">
                <span>📜</span>
                <span>Lịch sử gần đây</span>
            </h2>
            <a href="{{ route('customer.bookings.index') }}" class="text-xs font-bold text-pink-600 hover:text-pink-700">
                Toàn bộ lịch sử →
            </a>
        </div>

        @if($recentBookings->isEmpty())
            <div class="text-center py-8 text-stone-400 text-xs">
                Chưa có lịch hẹn nào đã hoàn thành hoặc hủy trước đây.
            </div>
        @else
            <div class="divide-y divide-stone-100">
                @foreach($recentBookings as $bk)
                    <div class="py-3.5 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
                        <div class="space-y-0.5">
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-xs text-stone-500">{{ $bk->booking_code }}</span>
                                {!! $bk->status_badge !!}
                            </div>
                            <span class="font-semibold text-sm text-stone-800 block">
                                {{ $bk->bookingServices->first()?->service_name }}
                            </span>
                            <span class="text-xs text-stone-400">
                                {{ $bk->branch?->name }} • {{ $bk->appointment_date->format('d/m/Y') }}
                            </span>
                        </div>

                        <div class="flex items-center gap-3 self-end sm:self-center">
                            @if($bk->status === 'completed' && !$bk->review)
                                <a href="{{ route('customer.reviews.create', $bk->id) }}" class="px-3 py-1.5 bg-amber-50 hover:bg-amber-100 text-amber-800 text-xs font-bold rounded-xl border border-amber-200 transition flex items-center gap-1">
                                    <span>⭐</span> Đánh giá
                                </a>
                            @endif
                            <a href="{{ route('customer.bookings.show', $bk->id) }}" class="text-xs font-bold text-pink-600 hover:underline">
                                Xem lại
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-customer-layout>
