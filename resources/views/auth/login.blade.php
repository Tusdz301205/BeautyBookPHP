<x-guest-layout>
    <div class="mb-4 text-center">
        <h2 class="text-2xl font-bold text-gray-900">Đăng nhập BeautyBook</h2>
        <p class="text-sm text-gray-600 mt-1">Hệ thống đặt lịch & quản lý dịch vụ làm đẹp</p>
    </div>

    <!-- Demo Quick Login Helper -->
    <div class="mb-5 p-3.5 bg-gradient-to-r from-pink-50 to-purple-50 rounded-xl border border-pink-200/70 text-xs">
        <div class="font-semibold text-pink-900 mb-1.5 flex items-center gap-1.5">
            <span>✨</span>
            <span>Tài khoản Demo môn học (Click để điền nhanh):</span>
        </div>
        <div class="grid grid-cols-2 gap-2 mt-2">
            <button type="button" 
                onclick="document.getElementById('email').value='owner@beautybook.vn'; document.getElementById('password').value='password';"
                class="px-2.5 py-1.5 bg-white rounded-lg border border-pink-200 hover:border-pink-400 hover:bg-pink-50 text-left transition">
                <span class="font-medium text-pink-900 block">🏢 Chủ Salon</span>
                <span class="text-gray-500 text-[11px] block">owner@beautybook.vn</span>
            </button>
            <button type="button" 
                onclick="document.getElementById('email').value='customer@beautybook.vn'; document.getElementById('password').value='password';"
                class="px-2.5 py-1.5 bg-white rounded-lg border border-purple-200 hover:border-purple-400 hover:bg-purple-50 text-left transition">
                <span class="font-medium text-purple-900 block">💇‍♀️ Khách hàng</span>
                <span class="text-gray-500 text-[11px] block">customer@beautybook.vn</span>
            </button>
        </div>
        <div class="text-gray-500 text-[11px] mt-1.5 text-center">Mật khẩu chung: <code class="bg-white/80 px-1 py-0.5 rounded font-mono text-pink-800">password</code></div>
    </div>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <!-- Email Address -->
        <div>
            <x-input-label for="email" value="Địa chỉ Email" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus autocomplete="username" placeholder="email@example.com" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" value="Mật khẩu" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="current-password"
                            placeholder="Nhập mật khẩu" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="flex items-center justify-between mt-4">
            <label for="remember_me" class="inline-flex items-center cursor-pointer">
                <input id="remember_me" type="checkbox" class="rounded border-gray-300 text-pink-600 shadow-sm focus:ring-pink-500" name="remember">
                <span class="ms-2 text-sm text-gray-600">Ghi nhớ đăng nhập</span>
            </label>

            @if (Route::has('password.request'))
                <a class="text-sm text-pink-600 hover:text-pink-700 hover:underline" href="{{ route('password.request') }}">
                    Quên mật khẩu?
                </a>
            @endif
        </div>

        <div class="mt-6 flex items-center justify-between">
            <a class="text-sm text-gray-600 hover:text-pink-600 hover:underline" href="{{ route('register') }}">
                Chưa có tài khoản? Đăng ký
            </a>

            <button type="submit" class="inline-flex items-center px-5 py-2.5 bg-pink-600 hover:bg-pink-700 active:bg-pink-800 text-white font-medium text-sm rounded-xl shadow-sm transition ease-in-out duration-150">
                Đăng nhập
            </button>
        </div>
    </form>
</x-guest-layout>
