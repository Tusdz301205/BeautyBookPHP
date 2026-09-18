<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Khu vực Khách hàng' }} — BeautyBook</title>

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
<body class="bg-stone-50/70 text-stone-800 antialiased min-h-screen flex flex-col" x-data="{ sidebarOpen: false }">

    <!-- Header Navigation -->
    <header class="sticky top-0 z-30 bg-white/95 backdrop-blur-md border-b border-stone-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <div class="flex items-center gap-4">
                    <button @click="sidebarOpen = !sidebarOpen" class="md:hidden p-2 text-stone-600 hover:text-stone-900 rounded-xl">
                        <span class="text-xl">☰</span>
                    </button>
                    <a href="{{ route('home') }}" class="flex items-center gap-2">
                        <div class="w-8 h-8 rounded-xl bg-pink-600 flex items-center justify-center text-white text-base font-bold">
                            💅
                        </div>
                        <span class="text-lg font-bold text-stone-900 tracking-tight">BeautyBook</span>
                        <span class="px-2 py-0.5 bg-pink-100 text-pink-700 rounded-full text-[10px] font-semibold">Khách hàng</span>
                    </a>
                </div>

                <div class="flex items-center gap-4">
                    <a href="{{ route('home') }}" class="text-xs font-semibold text-stone-600 hover:text-pink-600 flex items-center gap-1.5 transition">
                        <span>🔍</span>
                        <span class="hidden sm:inline">Khám phá dịch vụ</span>
                    </a>

                    <!-- User Menu -->
                    <div class="flex items-center gap-3 pl-3 border-l border-stone-200">
                        <img src="{{ auth()->user()->avatar_url }}" alt="{{ auth()->user()->name }}" class="w-8 h-8 rounded-full object-cover border border-stone-200 shadow-xs">
                        <span class="text-xs font-bold text-stone-700 hidden sm:inline">{{ auth()->user()->name }}</span>
                        <form method="POST" action="{{ route('logout') }}" class="inline">
                            @csrf
                            <button type="submit" class="text-xs text-stone-400 hover:text-rose-600 p-1" title="Đăng xuất">
                                🚪
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 w-full flex-1">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-8 items-start">
            
            <!-- Sidebar Navigation -->
            <aside class="md:col-span-1 bg-white p-5 rounded-3xl border border-stone-200/80 shadow-xs space-y-1" :class="sidebarOpen ? 'block' : 'hidden md:block'">
                <div class="text-center pb-4 mb-4 border-b border-stone-100">
                    <img src="{{ auth()->user()->avatar_url }}" class="w-16 h-16 rounded-full object-cover mx-auto mb-2 border-2 border-pink-100 shadow-sm">
                    <div class="font-bold text-sm text-stone-800">{{ auth()->user()->name }}</div>
                    <div class="text-xs text-stone-400 truncate">{{ auth()->user()->email }}</div>
                </div>

                <nav class="space-y-1 text-sm font-medium">
                    <a href="{{ route('customer.dashboard') }}" class="flex items-center gap-3 px-3.5 py-2.5 rounded-2xl transition {{ request()->routeIs('customer.dashboard') ? 'bg-pink-50 text-pink-700 font-bold' : 'text-stone-600 hover:bg-stone-50 hover:text-stone-900' }}">
                        <span class="text-lg">📊</span>
                        <span>Tổng quan</span>
                    </a>
                    <a href="{{ route('customer.bookings.index') }}" class="flex items-center gap-3 px-3.5 py-2.5 rounded-2xl transition {{ request()->routeIs('customer.bookings.*') ? 'bg-pink-50 text-pink-700 font-bold' : 'text-stone-600 hover:bg-stone-50 hover:text-stone-900' }}">
                        <span class="text-lg">📅</span>
                        <span>Lịch hẹn của tôi</span>
                    </a>
                    <a href="{{ route('customer.favorites.index') }}" class="flex items-center gap-3 px-3.5 py-2.5 rounded-2xl transition {{ request()->routeIs('customer.favorites.*') ? 'bg-pink-50 text-pink-700 font-bold' : 'text-stone-600 hover:bg-stone-50 hover:text-stone-900' }}">
                        <span class="text-lg">💖</span>
                        <span>Dịch vụ đã lưu</span>
                    </a>
                    <a href="{{ route('customer.reviews.index') }}" class="flex items-center gap-3 px-3.5 py-2.5 rounded-2xl transition {{ request()->routeIs('customer.reviews.*') ? 'bg-pink-50 text-pink-700 font-bold' : 'text-stone-600 hover:bg-stone-50 hover:text-stone-900' }}">
                        <span class="text-lg">⭐</span>
                        <span>Đánh giá của tôi</span>
                    </a>
                    <a href="{{ route('customer.profile.edit') }}" class="flex items-center gap-3 px-3.5 py-2.5 rounded-2xl transition {{ request()->routeIs('customer.profile.*') ? 'bg-pink-50 text-pink-700 font-bold' : 'text-stone-600 hover:bg-stone-50 hover:text-stone-900' }}">
                        <span class="text-lg">👤</span>
                        <span>Thông tin cá nhân</span>
                    </a>
                </nav>

                <div class="pt-4 mt-4 border-t border-stone-100">
                    <a href="{{ route('customer.bookings.create') }}" class="w-full flex items-center justify-center gap-2 py-2.5 px-4 rounded-2xl text-xs font-bold text-white bg-pink-600 hover:bg-pink-700 transition shadow-sm shadow-pink-500/20">
                        <span>✨</span> Đặt lịch hẹn mới
                    </a>
                </div>
            </aside>

            <!-- Main Content Pane -->
            <div class="md:col-span-3 space-y-6">
                <!-- Alerts / Flashes -->
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
                            <span>Có lỗi xảy ra:</span>
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
        </div>
    </div>
</body>
</html>
