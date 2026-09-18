<x-public-layout>
    <x-slot name="title">{{ $business->name }} — BeautyBook</x-slot>

    <!-- Salon Header Banner -->
    <div class="bg-gradient-to-r from-stone-900 via-stone-800 to-stone-900 text-white py-12 sm:py-16">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col md:flex-row items-center md:items-start gap-8">
                <img src="{{ $business->logo_url }}" alt="{{ $business->name }}" class="w-32 h-32 rounded-3xl object-cover border-4 border-white/10 shadow-2xl bg-stone-800">
                
                <div class="flex-1 text-center md:text-left space-y-3">
                    <div class="flex flex-wrap items-center justify-center md:justify-start gap-3">
                        <h1 class="text-3xl sm:text-4xl font-extrabold tracking-tight text-white">{{ $business->name }}</h1>
                        <span class="px-3 py-1 bg-emerald-500/20 border border-emerald-500/40 text-emerald-300 text-xs font-bold rounded-full">
                            ✓ Đã xác thực
                        </span>
                    </div>

                    <p class="text-sm text-stone-300 max-w-3xl leading-relaxed">
                        {{ $business->description ?? 'Thương hiệu làm đẹp uy tín với các liệu trình chuyên sâu và tiêu chuẩn phục vụ tận tâm.' }}
                    </p>

                    <div class="flex flex-wrap items-center justify-center md:justify-start gap-6 text-xs text-stone-400 pt-2 font-medium">
                        @if($business->phone)
                            <div class="flex items-center gap-1.5">
                                <span>📞</span>
                                <span>{{ $business->phone }}</span>
                            </div>
                        @endif
                        @if($business->email)
                            <div class="flex items-center gap-1.5">
                                <span>✉️</span>
                                <span>{{ $business->email }}</span>
                            </div>
                        @endif
                        @if($business->website)
                            <a href="{{ $business->website }}" target="_blank" class="flex items-center gap-1.5 hover:text-pink-400 transition">
                                <span>🌐</span>
                                <span>{{ parse_url($business->website, PHP_URL_HOST) ?? $business->website }}</span>
                            </a>
                        @endif
                        <div class="flex items-center gap-1.5 text-amber-300">
                            <span>⭐</span>
                            <span class="font-bold">{{ $business->average_rating ?? '5.0' }} / 5.0</span>
                            <span class="text-stone-500">({{ $reviews->count() }} đánh giá)</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content Area -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-10 items-start">

            <!-- Left: Branches & Services -->
            <div class="lg:col-span-2 space-y-12">

                <!-- Branches Section -->
                <div>
                    <h2 class="text-2xl font-bold text-stone-900 mb-6 flex items-center gap-2">
                        <span>📍</span>
                        <span>Danh sách Chi nhánh ({{ $business->branches->count() }})</span>
                    </h2>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        @foreach($business->branches as $branch)
                            <div class="bg-white p-5 rounded-2xl border border-stone-200 shadow-xs hover:border-pink-300 transition flex flex-col justify-between">
                                <div class="space-y-2">
                                    <h3 class="font-bold text-base text-stone-800">{{ $branch->name }}</h3>
                                    <p class="text-xs text-stone-500">{{ $branch->address }}</p>
                                    @if($branch->phone)
                                        <p class="text-xs text-stone-400 font-medium">Hotline: {{ $branch->phone }}</p>
                                    @endif
                                </div>
                                <div class="mt-4 pt-3 border-t border-stone-100 flex items-center justify-between">
                                    <span class="text-xs font-semibold text-emerald-600 flex items-center gap-1">
                                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span> Đang mở cửa
                                    </span>
                                    <a href="{{ route('branches.show', $branch->id) }}" class="text-xs font-bold text-pink-600 hover:text-pink-700">
                                        Xem chi nhánh →
                                    </a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Services by Category -->
                <div>
                    <h2 class="text-2xl font-bold text-stone-900 mb-6 flex items-center gap-2">
                        <span>💅</span>
                        <span>Bảng giá & Dịch vụ Nổi bật</span>
                    </h2>

                    @foreach($business->branches as $branch)
                        @if($branch->services->isNotEmpty())
                            <div class="mb-8 bg-white rounded-3xl p-6 border border-stone-200/80 shadow-xs">
                                <div class="border-b border-stone-100 pb-3 mb-4 flex items-center justify-between">
                                    <span class="font-bold text-sm text-stone-800 flex items-center gap-1.5">
                                        <span>📍</span> {{ $branch->name }}
                                    </span>
                                    <span class="text-xs text-stone-400">{{ $branch->services->count() }} dịch vụ</span>
                                </div>

                                <div class="divide-y divide-stone-100">
                                    @foreach($branch->services as $service)
                                        <div class="py-4 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                                            <div class="space-y-1">
                                                <div class="flex items-center gap-2">
                                                    <h4 class="font-bold text-sm text-stone-900 hover:text-pink-600">
                                                        <a href="{{ route('services.show', $service->id) }}">{{ $service->name }}</a>
                                                    </h4>
                                                    @if($service->category)
                                                        <span class="px-2 py-0.5 bg-stone-100 text-stone-600 text-[10px] font-semibold rounded-md">
                                                            {{ $service->category->name }}
                                                        </span>
                                                    @endif
                                                </div>
                                                <p class="text-xs text-stone-500 line-clamp-1">{{ $service->description }}</p>
                                                <span class="text-xs text-stone-400">⏱ {{ $service->duration_minutes }} phút</span>
                                            </div>

                                            <div class="flex items-center gap-4 flex-shrink-0 self-end sm:self-center">
                                                <div class="text-right">
                                                    @if($service->has_discount)
                                                        <span class="text-xs text-stone-400 line-through block">{{ number_format($service->price, 0, ',', '.') }} đ</span>
                                                        <span class="text-sm font-extrabold text-pink-600">{{ number_format($service->discount_price, 0, ',', '.') }} đ</span>
                                                    @else
                                                        <span class="text-sm font-extrabold text-stone-900">{{ number_format($service->price, 0, ',', '.') }} đ</span>
                                                    @endif
                                                </div>

                                                <a href="{{ route('customer.bookings.create', ['service_id' => $service->id, 'branch_id' => $branch->id]) }}" 
                                                    class="px-3.5 py-2 bg-pink-600 hover:bg-pink-700 text-white text-xs font-bold rounded-xl shadow-xs transition">
                                                    Đặt ngay
                                                </a>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>

            <!-- Right: Reviews & Customer Feedback -->
            <div class="space-y-6">
                <div class="bg-white p-6 rounded-3xl border border-stone-200/80 shadow-xs">
                    <h3 class="text-lg font-bold text-stone-900 mb-4 flex items-center justify-between">
                        <span>Đánh giá từ khách hàng</span>
                        <span class="text-xs text-pink-600 font-semibold">⭐ {{ $business->average_rating ?? '5.0' }}</span>
                    </h3>

                    @if($reviews->isEmpty())
                        <div class="text-center py-8 text-stone-400 text-xs">
                            Chưa có đánh giá nào cho salon này. Hãy là người đầu tiên trải nghiệm và đánh giá!
                        </div>
                    @else
                        <div class="space-y-4">
                            @foreach($reviews as $rev)
                                <div class="p-4 bg-stone-50 rounded-2xl border border-stone-100 space-y-2">
                                    <div class="flex items-center justify-between">
                                        <div class="flex items-center gap-2">
                                            <div class="w-7 h-7 rounded-full bg-pink-100 text-pink-700 font-bold text-xs flex items-center justify-center">
                                                {{ $rev->is_anonymous ? '?' : mb_substr($rev->customer->name, 0, 1) }}
                                            </div>
                                            <span class="text-xs font-bold text-stone-800">
                                                {{ $rev->is_anonymous ? 'Khách hàng ẩn danh' : $rev->customer->name }}
                                            </span>
                                        </div>
                                        <div class="text-amber-400 text-xs font-bold">
                                            {{ str_repeat('★', $rev->overall_rating) }}{{ str_repeat('☆', 5 - $rev->overall_rating) }}
                                        </div>
                                    </div>

                                    <p class="text-xs text-stone-600 leading-relaxed">{{ $rev->comment }}</p>
                                    <div class="text-[10px] text-stone-400">{{ $rev->created_at->format('d/m/Y') }}</div>

                                    <!-- Business Reply -->
                                    @if($rev->business_reply)
                                        <div class="mt-2 p-3 bg-pink-50/70 border-l-2 border-pink-400 rounded-r-xl text-xs space-y-1">
                                            <span class="font-bold text-pink-900 block text-[11px]">Phản hồi từ Salon:</span>
                                            <p class="text-stone-600 text-[11px]">{{ $rev->business_reply }}</p>
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

        </div>
    </div>
</x-public-layout>
