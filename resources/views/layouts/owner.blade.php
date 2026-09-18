<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Quản trị Salon' }} — BeautyBook Owner</title>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Tailwind & Assets -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="bg-stone-100/70 text-stone-800 antialiased min-h-screen flex flex-col md:flex-row" x-data="{ sidebarOpen: false }">

    <!-- Mobile Top Navigation Bar -->
    <div class="md:hidden bg-stone-900 text-white p-4 flex items-center justify-between sticky top-0 z-40">
        <div class="flex items-center gap-2">
            <span class="text-xl">💅</span>
            <span class="font-bold text-lg">BeautyBook <span class="text-pink-400 text-xs uppercase">Owner</span></span>
        </div>
        <button @click="sidebarOpen = !sidebarOpen" class="text-stone-300 hover:text-white p-1">
            <span class="text-2xl">☰</span>
        </button>
    </div>

    <!-- Admin Sidebar -->
    <aside class="w-full md:w-64 bg-stone-900 text-stone-300 flex-shrink-0 flex flex-col justify-between p-4 md:min-h-screen md:sticky md:top-0 transition-all z-30" 
        :class="sidebarOpen ? 'block' : 'hidden md:flex'">
        
        <div class="space-y-6">
            <!-- Brand Logo -->
            <div class="hidden md:flex items-center gap-3 px-3 py-2">
                <div class="w-10 h-10 rounded-2xl bg-gradient-to-tr from-pink-600 to-rose-500 flex items-center justify-center text-white text-xl shadow-md shadow-pink-500/30">
                    🏢
                </div>
                <div>
                    <span class="text-lg font-bold text-white tracking-tight">BeautyBook</span>
                    <span class="block text-[10px] uppercase tracking-wider font-extrabold text-pink-400">Owner Portal</span>
                </div>
            </div>

            <!-- Salon Info Badge -->
            @php $biz = auth()->user()->business; @endphp
            <div class="p-3 bg-stone-800/80 rounded-2xl border border-stone-700/60 flex items-center gap-3">
                <img src="{{ $biz?->logo_url ?? 'https://images.unsplash.com/photo-1560066984-138dadb4c035?auto=format&fit=crop&w=100&q=80' }}" class="w-10 h-10 rounded-xl object-cover bg-stone-700">
                <div class="overflow-hidden">
                    <div class="text-sm font-bold text-white truncate">{{ $biz?->name ?? 'Salon của tôi' }}</div>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold bg-emerald-900/60 text-emerald-300 border border-emerald-700/50">
                        {{ $biz?->status === 'active' ? 'Đang hoạt động' : 'Chưa kích hoạt' }}
                    </span>
                </div>
            </div>

            <!-- Navigation Links -->
            <nav class="space-y-1 text-sm font-medium">
                <a href="{{ route('owner.dashboard') }}" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition {{ request()->routeIs('owner.dashboard') ? 'bg-pink-600 text-white font-bold' : 'hover:bg-stone-800 text-stone-300 hover:text-white' }}">
                    <span class="text-base">📊</span>
                    <span>Bảng điều khiển</span>
                </a>
                <a href="{{ route('owner.business.edit') }}" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition {{ request()->routeIs('owner.business.*') ? 'bg-pink-600 text-white font-bold' : 'hover:bg-stone-800 text-stone-300 hover:text-white' }}">
                    <span class="text-base">🏢</span>
                    <span>Thông tin Salon</span>
                </a>
                <a href="{{ route('owner.branches.index') }}" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition {{ request()->routeIs('owner.branches.*') ? 'bg-pink-600 text-white font-bold' : 'hover:bg-stone-800 text-stone-300 hover:text-white' }}">
                    <span class="text-base">📍</span>
                    <span>Quản lý Chi nhánh</span>
                </a>
                <a href="{{ route('owner.categories.index') }}" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition {{ request()->routeIs('owner.categories.*') ? 'bg-pink-600 text-white font-bold' : 'hover:bg-stone-800 text-stone-300 hover:text-white' }}">
                    <span class="text-base">📑</span>
                    <span>Danh mục dịch vụ</span>
                </a>
                <a href="{{ route('owner.services.index') }}" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition {{ request()->routeIs('owner.services.*') ? 'bg-pink-600 text-white font-bold' : 'hover:bg-stone-800 text-stone-300 hover:text-white' }}">
                    <span class="text-base">💅</span>
                    <span>Bảng giá & Dịch vụ</span>
                </a>
                <a href="{{ route('owner.staff.index') }}" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition {{ request()->routeIs('owner.staff.*') ? 'bg-pink-600 text-white font-bold' : 'hover:bg-stone-800 text-stone-300 hover:text-white' }}">
                    <span class="text-base">👥</span>
                    <span>Đội ngũ Nhân viên</span>
                </a>
                <a href="{{ route('owner.bookings.index') }}" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition {{ request()->routeIs('owner.bookings.*') ? 'bg-pink-600 text-white font-bold' : 'hover:bg-stone-800 text-stone-300 hover:text-white' }}">
                    <span class="text-base">📅</span>
                    <span>Quản lý Lịch hẹn</span>
                </a>
                <a href="{{ route('owner.reviews.index') }}" class="flex items-center gap-3 px-3.5 py-2.5 rounded-xl transition {{ request()->routeIs('owner.reviews.*') ? 'bg-pink-600 text-white font-bold' : 'hover:bg-stone-800 text-stone-300 hover:text-white' }}">
                    <span class="text-base">⭐</span>
                    <span>Đánh giá khách hàng</span>
                </a>
            </nav>
        </div>

        <!-- Footer / Logout Actions -->
        <div class="pt-4 mt-6 border-t border-stone-800 space-y-2">
            <a href="{{ route('home') }}" target="_blank" class="flex items-center gap-2.5 px-3 py-2 text-xs text-stone-400 hover:text-white hover:bg-stone-800 rounded-xl transition">
                <span>🌐</span> Xem trang Public Khách hàng
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="w-full flex items-center gap-2.5 px-3 py-2 text-xs text-rose-400 hover:text-rose-300 hover:bg-rose-950/40 rounded-xl transition">
                    <span>🚪</span> Đăng xuất hệ thống
                </button>
            </form>
        </div>
    </aside>

    <!-- Main Content Area -->
    <main class="flex-1 p-6 sm:p-8 overflow-y-auto">
        <div class="max-w-7xl mx-auto space-y-6">

            <!-- Flash Notifications -->
            @if (session('success'))
                <div class="flex items-center justify-between p-4 bg-emerald-50 border border-emerald-200 text-emerald-800 rounded-2xl shadow-xs" x-data="{ show: true }" x-show="show">
                    <div class="flex items-center gap-3 text-sm font-medium">
                        <span class="text-lg">✅</span>
                        <span>{{ session('success') }}</span>
                    </div>
                    <button @click="show = false" class="text-emerald-500 hover:text-emerald-700">✕</button>
                </div>
            @endif

            @if ($errors->any())
                <div class="p-4 bg-rose-50 border border-rose-200 text-rose-800 rounded-2xl shadow-xs space-y-1">
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

            {{ $slot }}
        </div>
    </main>
</body>
</html>
