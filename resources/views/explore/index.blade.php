<x-public-layout>
    <x-slot name="title">Khám phá Dịch vụ Làm đẹp & Spa Cao cấp — BeautyBook</x-slot>

    <!-- Hero Section -->
    <section class="relative bg-gradient-to-b from-pink-50/70 via-stone-50/50 to-stone-50 py-16 sm:py-24 overflow-hidden">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10 text-center">
            <div class="inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-pink-100 text-pink-800 text-xs font-bold uppercase tracking-wider mb-6">
                <span>✨</span> Khám phá & Đặt lịch làm đẹp trực tuyến
            </div>
            <h1 class="text-4xl sm:text-6xl font-extrabold text-stone-900 tracking-tight leading-tight max-w-4xl mx-auto">
                Tôn vinh vẻ đẹp tự nhiên cùng hàng ngàn <span class="bg-gradient-to-r from-pink-600 to-rose-500 bg-clip-text text-transparent">Salon & Spa</span> hàng đầu
            </h1>
            <p class="mt-5 text-base sm:text-lg text-stone-600 max-w-2xl mx-auto">
                Tìm kiếm cơ sở làm đẹp uy tín, xem bảng giá minh bạch, kiểm tra lịch trống và đặt hẹn nhanh chóng chỉ trong vài bước.
            </p>

            <!-- Search & Filter Bar in Hero -->
            <div class="mt-10 max-w-3xl mx-auto bg-white p-3 sm:p-4 rounded-3xl shadow-xl shadow-pink-500/5 border border-stone-200">
                <form action="{{ route('home') }}" method="GET" class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-center">
                    <!-- Keyword -->
                    <div class="sm:col-span-5 relative text-left">
                        <label class="block text-[11px] font-bold text-stone-500 uppercase px-2 mb-0.5">Tìm kiếm</label>
                        <input type="text" name="q" value="{{ request('q') }}" placeholder="Dịch vụ, cắt tóc, massage..." 
                            class="w-full px-3 py-2 text-sm bg-stone-50 rounded-xl border-0 focus:ring-2 focus:ring-pink-500 focus:bg-white text-stone-800 placeholder-stone-400">
                    </div>

                    <!-- Category -->
                    <div class="sm:col-span-3 relative text-left">
                        <label class="block text-[11px] font-bold text-stone-500 uppercase px-2 mb-0.5">Danh mục</label>
                        <select name="category" class="w-full px-3 py-2 text-sm bg-stone-50 rounded-xl border-0 focus:ring-2 focus:ring-pink-500 focus:bg-white text-stone-700">
                            <option value="">Tất cả danh mục</option>
                            @foreach($categories as $cat)
                                <option value="{{ $cat->id }}" {{ request('category') == $cat->id ? 'selected' : '' }}>
                                    {{ $cat->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Location / Province -->
                    <div class="sm:col-span-2 relative text-left">
                        <label class="block text-[11px] font-bold text-stone-500 uppercase px-2 mb-0.5">Khu vực</label>
                        <select name="province" class="w-full px-3 py-2 text-sm bg-stone-50 rounded-xl border-0 focus:ring-2 focus:ring-pink-500 focus:bg-white text-stone-700">
                            <option value="">Toàn quốc</option>
                            @foreach($provinces as $prov)
                                <option value="{{ $prov->id }}" {{ request('province') == $prov->id ? 'selected' : '' }}>
                                    {{ $prov->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <!-- Submit Button -->
                    <div class="sm:col-span-2 mt-2 sm:mt-4">
                        <button type="submit" class="w-full py-2.5 px-4 bg-pink-600 hover:bg-pink-700 active:bg-pink-800 text-white font-bold text-sm rounded-xl shadow-md shadow-pink-500/20 transition flex items-center justify-center gap-1.5">
                            <span>🔍</span> Tìm
                        </button>
                    </div>
                </form>
            </div>

            <!-- Quick Categories Chips -->
            <div class="mt-8 flex flex-wrap items-center justify-center gap-2 max-w-3xl mx-auto">
                <a href="{{ route('home') }}" class="px-4 py-2 rounded-full text-xs font-semibold {{ !request('category') ? 'bg-pink-600 text-white shadow-sm' : 'bg-white text-stone-700 hover:bg-pink-50 border border-stone-200' }} transition">
                    Tất cả dịch vụ
                </a>
                @foreach($categories as $cat)
                    <a href="{{ route('home', ['category' => $cat->id]) }}" class="px-4 py-2 rounded-full text-xs font-semibold {{ request('category') == $cat->id ? 'bg-pink-600 text-white shadow-sm' : 'bg-white text-stone-700 hover:bg-pink-50 border border-stone-200' }} transition">
                        {{ $cat->name }}
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    <!-- Services Grid Section -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-8">
            <div>
                <h2 class="text-2xl font-bold text-stone-900">Danh sách Dịch vụ Nổi bật</h2>
                <p class="text-xs text-stone-500 mt-1">Tìm thấy {{ $services->total() }} dịch vụ làm đẹp sẵn sàng phục vụ</p>
            </div>

            <!-- Sorting Dropdown -->
            <div class="flex items-center gap-2 text-xs font-medium text-stone-600">
                <span>Sắp xếp theo:</span>
                <form id="sortForm" method="GET" action="{{ route('home') }}">
                    @if(request('q')) <input type="hidden" name="q" value="{{ request('q') }}"> @endif
                    @if(request('category')) <input type="hidden" name="category" value="{{ request('category') }}"> @endif
                    @if(request('province')) <input type="hidden" name="province" value="{{ request('province') }}"> @endif
                    <select name="sort" onchange="document.getElementById('sortForm').submit()" class="text-xs py-1.5 pl-3 pr-8 rounded-xl bg-white border border-stone-200 text-stone-800 font-semibold focus:ring-pink-500 focus:border-pink-500">
                        <option value="popular" {{ request('sort') == 'popular' ? 'selected' : '' }}>Mới nhất</option>
                        <option value="price_asc" {{ request('sort') == 'price_asc' ? 'selected' : '' }}>Giá tăng dần</option>
                        <option value="price_desc" {{ request('sort') == 'price_desc' ? 'selected' : '' }}>Giá giảm dần</option>
                        <option value="duration_asc" {{ request('sort') == 'duration_asc' ? 'selected' : '' }}>Thời lượng ngắn nhất</option>
                    </select>
                </form>
            </div>
        </div>

        @if($services->isEmpty())
            <div class="text-center py-16 bg-white rounded-3xl border border-stone-200/80 p-8">
                <span class="text-5xl">🔍</span>
                <h3 class="mt-4 text-lg font-bold text-stone-800">Không tìm thấy dịch vụ phù hợp</h3>
                <p class="text-xs text-stone-500 mt-1 max-w-sm mx-auto">Vui lòng thử tìm với từ khóa khác hoặc chọn danh mục khác.</p>
                <a href="{{ route('home') }}" class="mt-5 inline-flex items-center px-4 py-2 text-xs font-bold text-pink-600 bg-pink-50 hover:bg-pink-100 rounded-xl transition">
                    Xóa bộ lọc tìm kiếm
                </a>
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($services as $service)
                    <div class="group bg-white rounded-3xl overflow-hidden border border-stone-200/80 hover:border-pink-200 hover:shadow-xl hover:shadow-pink-500/10 transition-all duration-300 flex flex-col justify-between">
                        <div>
                            <!-- Card Image Banner -->
                            <div class="relative h-48 overflow-hidden bg-stone-100">
                                <img src="{{ $service->cover_image_url }}" alt="{{ $service->name }}" class="w-full h-full object-cover group-hover:scale-105 transition duration-500">
                                
                                <!-- Category Badge -->
                                @if($service->category)
                                    <span class="absolute top-3 left-3 px-3 py-1 bg-white/90 backdrop-blur-md rounded-full text-[11px] font-bold text-stone-700 shadow-xs">
                                        {{ $service->category->name }}
                                    </span>
                                @endif

                                <!-- Favorite Heart Button (AJAX) -->
                                @auth
                                    @php $isFav = auth()->user()->savedServices->contains($service->id); @endphp
                                    <button type="button" 
                                        onclick="toggleFavorite({{ $service->id }}, this)" 
                                        class="absolute top-3 right-3 w-9 h-9 bg-white/90 backdrop-blur-md rounded-full flex items-center justify-center text-sm shadow-sm hover:scale-110 active:scale-95 transition"
                                        title="Lưu dịch vụ yêu thích">
                                        <span class="fav-icon">{{ $isFav ? '❤️' : '🤍' }}</span>
                                    </button>
                                @else
                                    <a href="{{ route('login') }}" class="absolute top-3 right-3 w-9 h-9 bg-white/90 backdrop-blur-md rounded-full flex items-center justify-center text-sm shadow-sm hover:scale-110 transition" title="Đăng nhập để lưu">
                                        🤍
                                    </a>
                                @endauth

                                <!-- Duration Badge -->
                                <span class="absolute bottom-3 right-3 px-2.5 py-1 bg-stone-900/80 backdrop-blur-md text-white rounded-lg text-[10px] font-semibold flex items-center gap-1">
                                    <span>⏱</span> {{ $service->duration_minutes }} phút
                                </span>
                            </div>

                            <!-- Card Body -->
                            <div class="p-5">
                                <a href="{{ route('salons.show', $service->branch?->business?->slug ?? '#') }}" class="text-xs font-bold text-pink-600 hover:text-pink-700 flex items-center gap-1 mb-1">
                                    <span>🏢</span>
                                    <span>{{ $service->branch?->business?->name }}</span>
                                </a>

                                <h3 class="font-bold text-base text-stone-900 group-hover:text-pink-600 transition line-clamp-2">
                                    <a href="{{ route('services.show', $service->id) }}">
                                        {{ $service->name }}
                                    </a>
                                </h3>

                                <p class="text-xs text-stone-500 mt-2 line-clamp-2 leading-relaxed">
                                    {{ $service->description ?? 'Trải nghiệm liệu trình chăm sóc sắc đẹp cao cấp cùng trang thiết bị hiện đại và kỹ thuật viên tay nghề cao.' }}
                                </p>

                                <div class="mt-3 flex items-center gap-1.5 text-xs text-stone-500 truncate">
                                    <span>📍</span>
                                    <span class="truncate">{{ $service->branch?->name }} • {{ $service->branch?->district?->name }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Card Footer (Pricing & CTA) -->
                        <div class="px-5 pb-5 pt-3 border-t border-stone-100 flex items-center justify-between">
                            <div>
                                @if($service->has_discount)
                                    <span class="text-xs text-stone-400 line-through block">{{ number_format($service->price, 0, ',', '.') }} đ</span>
                                    <span class="text-base font-extrabold text-pink-600">{{ number_format($service->discount_price, 0, ',', '.') }} đ</span>
                                @else
                                    <span class="text-xs text-stone-400 block">Giá dịch vụ</span>
                                    <span class="text-base font-extrabold text-stone-900">{{ number_format($service->price, 0, ',', '.') }} đ</span>
                                @endif
                            </div>

                            <a href="{{ route('customer.bookings.create', ['service_id' => $service->id, 'branch_id' => $service->branch_id]) }}" 
                                class="inline-flex items-center gap-1.5 px-4 py-2 bg-pink-600 hover:bg-pink-700 active:bg-pink-800 text-white font-bold text-xs rounded-xl shadow-xs transition">
                                <span>Đặt lịch</span>
                                <span>→</span>
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Pagination Links -->
            <div class="mt-10">
                {{ $services->links() }}
            </div>
        @endif
    </section>

    <!-- Featured Salons / Spa Section -->
    <section id="salons" class="bg-white py-16 border-t border-stone-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-2xl mx-auto mb-12">
                <span class="text-xs font-bold uppercase tracking-wider text-pink-600">Thương hiệu uy tín</span>
                <h2 class="text-3xl font-extrabold text-stone-900 tracking-tight mt-1">Hệ thống Salon & Spa Nổi bật</h2>
                <p class="text-xs text-stone-500 mt-2">Các cơ sở đối tác được kiểm duyệt kỹ lưỡng về cơ sở vật chất và tay nghề</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-8">
                @foreach($featuredBusinesses as $biz)
                    <div class="bg-stone-50/70 rounded-3xl p-6 border border-stone-200 hover:border-pink-200 hover:shadow-lg transition duration-300 flex flex-col sm:flex-row gap-6 items-start">
                        <img src="{{ $biz->logo_url }}" alt="{{ $biz->name }}" class="w-24 h-24 rounded-2xl object-cover border border-stone-200 bg-white shadow-xs flex-shrink-0">
                        <div class="flex-1 space-y-2">
                            <div class="flex items-center justify-between">
                                <h3 class="font-bold text-lg text-stone-900">{{ $biz->name }}</h3>
                                <span class="px-2.5 py-1 bg-amber-100 text-amber-800 font-bold rounded-lg text-xs flex items-center gap-1">
                                    <span>⭐</span> {{ $biz->average_rating ?? '5.0' }}
                                </span>
                            </div>
                            <p class="text-xs text-stone-600 line-clamp-2 leading-relaxed">
                                {{ $biz->description }}
                            </p>
                            <div class="text-xs text-stone-500 flex flex-wrap gap-x-4 gap-y-1 pt-1">
                                <span>📍 {{ $biz->branches->count() }} chi nhánh</span>
                                @if($biz->phone) <span>📞 {{ $biz->phone }}</span> @endif
                            </div>
                            <div class="pt-2">
                                <a href="{{ route('salons.show', $biz->slug) }}" class="inline-flex items-center gap-1 text-xs font-bold text-pink-600 hover:text-pink-700">
                                    <span>Xem chi tiết & bảng giá</span>
                                    <span>→</span>
                                </a>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>
</x-public-layout>
