<x-public-layout>
    <x-slot name="title">{{ $service->name }} — BeautyBook</x-slot>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <!-- Breadcrumb -->
        <nav class="flex items-center gap-2 text-xs text-stone-500 mb-6">
            <a href="{{ route('home') }}" class="hover:text-pink-600">Trang chủ</a>
            <span>/</span>
            <a href="{{ route('salons.show', $service->branch->business->slug) }}" class="hover:text-pink-600">{{ $service->branch->business->name }}</a>
            <span>/</span>
            <a href="{{ route('branches.show', $service->branch->id) }}" class="hover:text-pink-600">{{ $service->branch->name }}</a>
            <span>/</span>
            <span class="text-stone-800 font-semibold truncate">{{ $service->name }}</span>
        </nav>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-10 items-start">
            <!-- Left: Photos & Description -->
            <div class="lg:col-span-2 space-y-8">
                <!-- Cover Image -->
                <div class="rounded-3xl overflow-hidden bg-stone-100 border border-stone-200 shadow-xs h-80 sm:h-96">
                    <img src="{{ $service->cover_image_url }}" alt="{{ $service->name }}" class="w-full h-full object-cover">
                </div>

                <!-- Info Header -->
                <div class="bg-white rounded-3xl p-6 sm:p-8 border border-stone-200/80 shadow-xs space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-4">
                        <div class="flex items-center gap-2">
                            @if($service->category)
                                <span class="px-3 py-1 bg-pink-100 text-pink-700 text-xs font-bold rounded-full">
                                    {{ $service->category->name }}
                                </span>
                            @endif
                            <span class="px-3 py-1 bg-stone-100 text-stone-600 text-xs font-semibold rounded-full flex items-center gap-1">
                                <span>⏱</span> {{ $service->duration_minutes }} phút
                            </span>
                        </div>

                        <!-- Favorite Heart Button -->
                        @auth
                            @php $isFav = auth()->user()->savedServices->contains($service->id); @endphp
                            <button type="button" 
                                onclick="toggleFavorite({{ $service->id }}, this)" 
                                class="inline-flex items-center gap-2 px-4 py-2 bg-pink-50 hover:bg-pink-100 text-pink-700 text-xs font-bold rounded-xl transition">
                                <span class="fav-icon">{{ $isFav ? '❤️' : '🤍' }}</span>
                                <span>{{ $isFav ? 'Đã lưu' : 'Lưu yêu thích' }}</span>
                            </button>
                        @endauth
                    </div>

                    <h1 class="text-2xl sm:text-3xl font-extrabold text-stone-900 tracking-tight leading-snug">
                        {{ $service->name }}
                    </h1>

                    <div class="border-t border-stone-100 pt-4">
                        <h3 class="text-sm font-bold uppercase tracking-wider text-stone-400 mb-2">Mô tả chi tiết liệu trình</h3>
                        <p class="text-sm text-stone-600 leading-relaxed whitespace-pre-line">
                            {{ $service->description ?? 'Liệu trình cao cấp được thiết kế riêng nhằm mang lại hiệu quả tối ưu và cảm giác thư giãn tuyệt đối cho quý khách.' }}
                        </p>
                    </div>
                </div>

                <!-- Qualified Staff -->
                @if($service->staff->isNotEmpty())
                    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-stone-200/80 shadow-xs">
                        <h3 class="text-base font-bold text-stone-900 mb-4 flex items-center gap-2">
                            <span>👥</span>
                            <span>Chuyên viên thực hiện dịch vụ này</span>
                        </h3>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            @foreach($service->staff as $st)
                                <div class="p-3.5 rounded-2xl bg-stone-50 border border-stone-100 flex items-center gap-3">
                                    <img src="{{ $st->avatar_url }}" class="w-12 h-12 rounded-xl object-cover bg-white border border-stone-200">
                                    <div>
                                        <div class="font-bold text-sm text-stone-800">{{ $st->name }}</div>
                                        <div class="text-xs text-pink-600 font-medium">{{ $st->position ?? 'Kỹ thuật viên' }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>

            <!-- Right: Booking Action Card -->
            <div class="space-y-6 lg:sticky lg:top-28">
                <div class="bg-white rounded-3xl p-6 border border-stone-200/80 shadow-xl shadow-pink-500/5 space-y-6">
                    <div>
                        <span class="text-xs text-stone-400 block mb-1">Giá trải nghiệm</span>
                        <div class="flex items-baseline gap-3">
                            @if($service->has_discount)
                                <span class="text-3xl font-extrabold text-pink-600">{{ number_format($service->discount_price, 0, ',', '.') }} đ</span>
                                <span class="text-base text-stone-400 line-through">{{ number_format($service->price, 0, ',', '.') }} đ</span>
                            @else
                                <span class="text-3xl font-extrabold text-stone-900">{{ number_format($service->price, 0, ',', '.') }} đ</span>
                            @endif
                        </div>
                    </div>

                    <!-- Salon & Branch Summary -->
                    <div class="p-4 bg-stone-50 rounded-2xl border border-stone-100 space-y-2 text-xs">
                        <div class="flex items-center gap-2">
                            <span>🏢</span>
                            <span class="font-bold text-stone-800">{{ $service->branch->business->name }}</span>
                        </div>
                        <div class="flex items-start gap-2 text-stone-500">
                            <span>📍</span>
                            <span>{{ $service->branch->name }} — {{ $service->branch->address }}</span>
                        </div>
                    </div>

                    <!-- Booking CTA Button -->
                    <a href="{{ route('customer.bookings.create', ['service_id' => $service->id, 'branch_id' => $service->branch_id]) }}" 
                        class="w-full py-3.5 px-4 bg-pink-600 hover:bg-pink-700 active:bg-pink-800 text-white font-bold text-sm rounded-2xl shadow-lg shadow-pink-500/25 transition flex items-center justify-center gap-2">
                        <span>✨</span>
                        <span>Đặt lịch hẹn dịch vụ này</span>
                    </a>

                    <div class="space-y-2 text-[11px] text-stone-400 text-center">
                        <p>✓ Xác nhận tức thì & Giữ chỗ ưu tiên</p>
                        <p>✓ Hỗ trợ dời hoặc hủy lịch miễn phí trước 2 giờ</p>
                    </div>
                </div>

                <!-- Related Services -->
                @if($relatedServices->isNotEmpty())
                    <div class="bg-white rounded-3xl p-6 border border-stone-200/80 shadow-xs space-y-4">
                        <h4 class="font-bold text-sm text-stone-900">Dịch vụ cùng cơ sở</h4>
                        <div class="space-y-3">
                            @foreach($relatedServices as $rel)
                                <a href="{{ route('services.show', $rel->id) }}" class="block p-3 rounded-2xl hover:bg-pink-50 transition border border-stone-100 group">
                                    <div class="font-bold text-xs text-stone-800 group-hover:text-pink-600">{{ $rel->name }}</div>
                                    <div class="flex items-center justify-between mt-1 text-[11px]">
                                        <span class="text-stone-400">{{ $rel->duration_minutes }} phút</span>
                                        <span class="font-bold text-pink-600">{{ $rel->formatted_price }}</span>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-public-layout>
