<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'BeautyBook — Nền tảng Đặt lịch & Quản lý Làm đẹp Thông minh' }}</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Tailwind & App Assets -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
        }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-stone-50/60 text-stone-800 antialiased min-h-screen flex flex-col selection:bg-pink-500 selection:text-white" x-data="{ mobileMenuOpen: false }">

    <!-- Top Announcement Bar -->
    <div class="bg-gradient-to-r from-pink-600 via-rose-500 to-purple-600 text-white text-xs py-2 px-4 text-center font-medium shadow-sm">
        <span>✨ BeautyBook — Phiên bản Mã nguồn Mở (Laravel 11 + Blade + MySQL)</span>
        <span class="mx-2 opacity-60">|</span>
        <span class="hidden sm:inline">Ưu đãi trải nghiệm các liệu trình làm đẹp & spa cao cấp hôm nay!</span>
    </div>

    <!-- Main Navigation Header -->
    <header class="sticky top-0 z-40 bg-white/90 backdrop-blur-md border-b border-stone-200/80 transition-all">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
                <!-- Logo -->
                <div class="flex items-center gap-8">
                    <a href="{{ route('home') }}" class="flex items-center gap-2.5 group">
                        <div class="w-11 h-11 rounded-2xl bg-gradient-to-tr from-pink-600 to-rose-400 flex items-center justify-center text-white text-2xl shadow-md shadow-pink-500/20 group-hover:scale-105 transition-transform">
                            💅
                        </div>
                        <div>
                            <span class="text-2xl font-extrabold tracking-tight bg-gradient-to-r from-stone-900 via-pink-900 to-rose-600 bg-clip-text text-transparent">BeautyBook</span>
                            <span class="block text-[10px] uppercase font-bold tracking-widest text-pink-600">Open Source</span>
                        </div>
                    </a>

                    <!-- Desktop Nav Links -->
                    <nav class="hidden md:flex items-center gap-1">
                        <a href="{{ route('home') }}" class="px-3.5 py-2 rounded-xl text-sm font-semibold {{ request()->routeIs('home') ? 'text-pink-600 bg-pink-50' : 'text-stone-600 hover:text-stone-900 hover:bg-stone-100' }} transition">
                            Khám phá dịch vụ
                        </a>
                        <a href="{{ route('home') }}#salons" class="px-3.5 py-2 rounded-xl text-sm font-semibold text-stone-600 hover:text-stone-900 hover:bg-stone-100 transition">
                            Salon & Spa nổi bật
                        </a>
                    </nav>
                </div>

                <!-- Live Search Bar (Desktop) -->
                <div class="hidden lg:block relative flex-1 max-w-md mx-8" x-data="liveSearch()">
                    <div class="relative">
                        <input type="text" 
                            x-model="query" 
                            @input.debounce.300ms="search()"
                            @focus="open = true"
                            placeholder="Tìm dịch vụ, salon, liệu trình..." 
                            class="w-full pl-10 pr-4 py-2.5 text-sm bg-stone-100/80 border border-stone-200 rounded-2xl focus:bg-white focus:outline-none focus:ring-2 focus:ring-pink-500/30 focus:border-pink-500 transition-all">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-stone-400">
                            🔍
                        </div>
                        <div x-show="loading" class="absolute inset-y-0 right-0 pr-3 flex items-center">
                            <span class="w-4 h-4 border-2 border-pink-500 border-t-transparent rounded-full animate-spin"></span>
                        </div>
                    </div>

                    <!-- Search Dropdown Results -->
                    <div x-show="open && (services.length > 0 || salons.length > 0)" 
                        @click.away="open = false" 
                        x-cloak
                        class="absolute left-0 right-0 mt-2 bg-white rounded-2xl shadow-xl border border-stone-200/80 overflow-hidden z-50">
                        <template x-if="services.length > 0">
                            <div class="p-3 border-b border-stone-100">
                                <span class="text-xs font-bold uppercase tracking-wider text-stone-400 px-2">Dịch vụ</span>
                                <div class="mt-1 space-y-1">
                                    <template x-for="item in services" :key="item.id">
                                        <a :href="item.url" class="flex items-center justify-between p-2 rounded-xl hover:bg-pink-50 transition group">
                                            <div>
                                                <div class="font-medium text-sm text-stone-800 group-hover:text-pink-600" x-text="item.name"></div>
                                                <div class="text-xs text-stone-500" x-text="item.salon + ' • ' + item.duration"></div>
                                            </div>
                                            <span class="text-xs font-semibold text-pink-600" x-text="item.price_formatted"></span>
                                        </a>
                                    </template>
                                </div>
                            </div>
                        </template>

                        <template x-if="salons.length > 0">
                            <div class="p-3">
                                <span class="text-xs font-bold uppercase tracking-wider text-stone-400 px-2">Salon / Spa</span>
                                <div class="mt-1 space-y-1">
                                    <template x-for="salon in salons" :key="salon.id">
                                        <a :href="salon.url" class="flex items-center gap-3 p-2 rounded-xl hover:bg-pink-50 transition group">
                                            <img :src="salon.logo" class="w-8 h-8 rounded-lg object-cover bg-stone-100">
                                            <span class="font-medium text-sm text-stone-800 group-hover:text-pink-600" x-text="salon.name"></span>
                                        </a>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Right Actions (Auth / User) -->
                <div class="flex items-center gap-3">
                    @auth
                        @if(auth()->user()->isBusinessOwner())
                            <a href="{{ route('owner.dashboard') }}" class="hidden sm:inline-flex items-center gap-2 px-4 py-2 text-xs font-bold text-pink-700 bg-pink-50 hover:bg-pink-100 border border-pink-200 rounded-xl transition">
                                <span>🏢</span>
                                <span>Quản lý Salon</span>
                            </a>
                        @else
                            <a href="{{ route('customer.bookings.index') }}" class="hidden sm:inline-flex items-center gap-2 px-3.5 py-2 text-xs font-semibold text-stone-700 hover:text-pink-600 hover:bg-stone-100 rounded-xl transition">
                                <span>📅</span>
                                <span>Lịch hẹn của tôi</span>
                            </a>
                            <a href="{{ route('customer.favorites.index') }}" class="p-2 text-stone-600 hover:text-pink-600 hover:bg-pink-50 rounded-xl transition" title="Yêu thích">
                                <span class="text-lg">💖</span>
                            </a>
                        @endif

                        <!-- User Profile Dropdown -->
                        <div class="relative" x-data="{ userMenuOpen: false }">
                            <button @click="userMenuOpen = !userMenuOpen" class="flex items-center gap-2.5 p-1 rounded-full hover:ring-2 hover:ring-pink-200 transition">
                                <img src="{{ auth()->user()->avatar_url }}" alt="{{ auth()->user()->name }}" class="w-9 h-9 rounded-full object-cover border border-stone-200 shadow-sm">
                                <span class="hidden md:block text-sm font-semibold text-stone-700">{{ auth()->user()->name }}</span>
                                <span class="text-xs text-stone-400">▼</span>
                            </button>

                            <div x-show="userMenuOpen" 
                                @click.away="userMenuOpen = false" 
                                x-cloak
                                class="absolute right-0 mt-2 w-56 bg-white rounded-2xl shadow-xl border border-stone-100 py-2 z-50 text-sm">
                                <div class="px-4 py-2 border-b border-stone-100">
                                    <div class="font-bold text-stone-800">{{ auth()->user()->name }}</div>
                                    <div class="text-xs text-stone-500 truncate">{{ auth()->user()->email }}</div>
                                    <div class="mt-1">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold {{ auth()->user()->isBusinessOwner() ? 'bg-amber-100 text-amber-800' : 'bg-pink-100 text-pink-800' }}">
                                            {{ auth()->user()->isBusinessOwner() ? 'Chủ Doanh Nghiệp' : 'Khách Hàng' }}
                                        </span>
                                    </div>
                                </div>

                                @if(auth()->user()->isBusinessOwner())
                                    <a href="{{ route('owner.dashboard') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-stone-700 hover:bg-pink-50 hover:text-pink-600">
                                        <span>📊</span> Dashboard Quản lý
                                    </a>
                                    <a href="{{ route('owner.business.edit') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-stone-700 hover:bg-pink-50 hover:text-pink-600">
                                        <span>🏢</span> Hồ sơ Salon
                                    </a>
                                    <a href="{{ route('owner.bookings.index') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-stone-700 hover:bg-pink-50 hover:text-pink-600">
                                        <span>📋</span> Danh sách Lịch hẹn
                                    </a>
                                @else
                                    <a href="{{ route('customer.dashboard') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-stone-700 hover:bg-pink-50 hover:text-pink-600">
                                        <span>✨</span> Tổng quan tài khoản
                                    </a>
                                    <a href="{{ route('customer.bookings.index') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-stone-700 hover:bg-pink-50 hover:text-pink-600">
                                        <span>📅</span> Lịch hẹn của tôi
                                    </a>
                                    <a href="{{ route('customer.favorites.index') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-stone-700 hover:bg-pink-50 hover:text-pink-600">
                                        <span>💖</span> Dịch vụ đã lưu
                                    </a>
                                    <a href="{{ route('customer.profile.edit') }}" class="flex items-center gap-2.5 px-4 py-2.5 text-stone-700 hover:bg-pink-50 hover:text-pink-600">
                                        <span>👤</span> Cập nhật thông tin
                                    </a>
                                @endif

                                <div class="border-t border-stone-100 my-1"></div>
                                <form method="POST" action="{{ route('logout') }}">
                                    @csrf
                                    <button type="submit" class="w-full text-left flex items-center gap-2.5 px-4 py-2 text-rose-600 hover:bg-rose-50">
                                        <span>🚪</span> Đăng xuất
                                    </button>
                                </form>
                            </div>
                        </div>
                    @else
                        <a href="{{ route('login') }}" class="px-4 py-2 text-sm font-semibold text-stone-700 hover:text-pink-600 hover:bg-stone-100 rounded-xl transition">
                            Đăng nhập
                        </a>
                        <a href="{{ route('register') }}" class="px-5 py-2.5 text-sm font-semibold text-white bg-pink-600 hover:bg-pink-700 rounded-xl shadow-sm shadow-pink-500/20 transition">
                            Đăng ký
                        </a>
                    @endauth

                    <!-- Mobile Menu Toggle Button -->
                    <button @click="mobileMenuOpen = !mobileMenuOpen" class="md:hidden p-2 text-stone-600 hover:text-stone-900 rounded-xl">
                        <span class="text-2xl">☰</span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Mobile Drawer Menu -->
        <div x-show="mobileMenuOpen" @click.away="mobileMenuOpen = false" x-cloak class="md:hidden border-t border-stone-200 bg-white px-4 py-4 space-y-2">
            <a href="{{ route('home') }}" class="block px-3 py-2 rounded-xl text-base font-semibold text-stone-800 hover:bg-pink-50 hover:text-pink-600">Khám phá dịch vụ</a>
            <a href="{{ route('home') }}#salons" class="block px-3 py-2 rounded-xl text-base font-semibold text-stone-800 hover:bg-pink-50 hover:text-pink-600">Salon & Spa nổi bật</a>
            @auth
                @if(auth()->user()->isBusinessOwner())
                    <a href="{{ route('owner.dashboard') }}" class="block px-3 py-2 rounded-xl text-base font-semibold text-pink-600 bg-pink-50">Quản lý Salon (Owner Portal)</a>
                @else
                    <a href="{{ route('customer.dashboard') }}" class="block px-3 py-2 rounded-xl text-base font-semibold text-pink-600 bg-pink-50">Khu vực Khách hàng</a>
                @endif
            @endauth
        </div>
    </header>

    <!-- Global Toast / Alert Notifications -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4 w-full">
        @if (session('success'))
            <div class="flex items-center justify-between p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl shadow-sm" x-data="{ show: true }" x-show="show">
                <div class="flex items-center gap-3">
                    <span class="text-xl">✅</span>
                    <span class="text-sm font-medium">{{ session('success') }}</span>
                </div>
                <button @click="show = false" class="text-emerald-500 hover:text-emerald-700">✕</button>
            </div>
        @endif

        @if ($errors->any())
            <div class="p-4 bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl shadow-sm space-y-1">
                <div class="flex items-center gap-2 font-semibold text-sm">
                    <span>⚠️</span>
                    <span>Vui lòng kiểm tra lại thông tin:</span>
                </div>
                <ul class="list-disc list-inside text-xs pl-6 space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </div>

    <!-- Main Content -->
    <main class="flex-1">
        {{ $slot }}
    </main>

    <!-- Footer -->
    <footer class="bg-stone-900 text-stone-300 mt-20 border-t border-stone-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-10">
                <!-- Brand Info -->
                <div class="md:col-span-1 space-y-4">
                    <div class="flex items-center gap-2.5">
                        <div class="w-9 h-9 rounded-xl bg-pink-600 flex items-center justify-center text-white text-xl">
                            💅
                        </div>
                        <span class="text-2xl font-bold text-white tracking-tight">BeautyBook</span>
                    </div>
                    <p class="text-xs text-stone-400 leading-relaxed">
                        Nền tảng mở đặt lịch làm đẹp và quản lý chuỗi salon, spa hiện đại. Phiên bản tối ưu cho môn học Lập trình Mã nguồn Mở.
                    </p>
                    <div class="flex items-center gap-3 text-xs text-stone-500 font-mono">
                        <span class="px-2 py-1 bg-stone-800 rounded">PHP 8.3</span>
                        <span class="px-2 py-1 bg-stone-800 rounded">Laravel 11</span>
                        <span class="px-2 py-1 bg-stone-800 rounded">MySQL 8.4</span>
                    </div>
                </div>

                <!-- Dành cho Khách hàng -->
                <div>
                    <h4 class="text-sm font-bold uppercase tracking-wider text-white mb-4">Dành cho Khách hàng</h4>
                    <ul class="space-y-2.5 text-xs text-stone-400">
                        <li><a href="{{ route('home') }}" class="hover:text-pink-400 transition">Khám phá dịch vụ</a></li>
                        <li><a href="{{ route('customer.bookings.create') }}" class="hover:text-pink-400 transition">Đặt lịch hẹn nhanh</a></li>
                        <li><a href="{{ route('customer.favorites.index') }}" class="hover:text-pink-400 transition">Dịch vụ đã lưu</a></li>
                        <li><a href="{{ route('customer.reviews.index') }}" class="hover:text-pink-400 transition">Đánh giá của tôi</a></li>
                    </ul>
                </div>

                <!-- Dành cho Chủ Salon -->
                <div>
                    <h4 class="text-sm font-bold uppercase tracking-wider text-white mb-4">Dành cho Chủ Cơ sở</h4>
                    <ul class="space-y-2.5 text-xs text-stone-400">
                        <li><a href="{{ route('register') }}" class="hover:text-pink-400 transition">Đăng ký mở Salon</a></li>
                        <li><a href="{{ route('owner.dashboard') }}" class="hover:text-pink-400 transition">Bảng điều khiển kinh doanh</a></li>
                        <li><a href="{{ route('owner.services.index') }}" class="hover:text-pink-400 transition">Quản lý bảng giá dịch vụ</a></li>
                        <li><a href="{{ route('owner.staff.index') }}" class="hover:text-pink-400 transition">Quản lý nhân viên & lịch làm</a></li>
                    </ul>
                </div>

                <!-- Đồ án & Giới thiệu -->
                <div>
                    <h4 class="text-sm font-bold uppercase tracking-wider text-white mb-4">Thông tin Đồ án</h4>
                    <p class="text-xs text-stone-400 leading-relaxed">
                        Hệ thống được thiết kế theo kiến trúc MVC chuẩn mực, bảo mật RBAC với Policy/Middleware và hỗ trợ tương tác bất đồng bộ AJAX.
                    </p>
                    <div class="mt-4 p-3 bg-stone-800/80 rounded-xl border border-stone-700/60 text-[11px] text-stone-400">
                        <span class="font-semibold text-pink-400 block mb-1">Môn học:</span>
                        Lập trình Mã nguồn Mở (Open Source Programming)
                    </div>
                </div>
            </div>

            <div class="mt-12 pt-8 border-t border-stone-800/80 flex flex-col sm:flex-row items-center justify-between text-xs text-stone-500 gap-4">
                <div>© {{ date('Y') }} BeautyBook Open Source Edition. Giữ toàn quyền.</div>
                <div class="flex gap-4">
                    <span>Clean Architecture</span>
                    <span>•</span>
                    <span>TDD 80%+ Coverage</span>
                    <span>•</span>
                    <span>Responsive UI</span>
                </div>
            </div>
        </div>
    </footer>

    <script>
        function liveSearch() {
            return {
                query: '',
                open: false,
                loading: false,
                services: [],
                salons: [],
                search() {
                    if (this.query.trim().length < 2) {
                        this.services = [];
                        this.salons = [];
                        return;
                    }
                    this.loading = true;
                    fetch(`{{ route('search.ajax') }}?q=${encodeURIComponent(this.query)}`)
                        .then(r => r.json())
                        .then(data => {
                            this.services = data.services || [];
                            this.salons = data.salons || [];
                            this.open = true;
                            this.loading = false;
                        })
                        .catch(() => {
                            this.loading = false;
                        });
                }
            }
        }

        // Global AJAX Helper for Favorites Toggle
        function toggleFavorite(serviceId, buttonEl) {
            fetch(`/customer/favorites/${serviceId}/toggle`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    'Accept': 'application/json'
                }
            })
            .then(res => {
                if (res.status === 401) {
                    window.location.href = "{{ route('login') }}";
                    return;
                }
                return res.json();
            })
            .then(data => {
                if (data && data.success) {
                    const icon = buttonEl.querySelector('.fav-icon');
                    if (icon) {
                        icon.textContent = data.saved ? '❤️' : '🤍';
                    }
                    if (buttonEl.dataset.removeOnUnsave && !data.saved) {
                        const card = buttonEl.closest('.fav-card');
                        if (card) card.remove();
                    }
                }
            })
            .catch(err => console.error(err));
        }
    </script>
</body>
</html>
