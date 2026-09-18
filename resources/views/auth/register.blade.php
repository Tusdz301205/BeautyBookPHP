<x-guest-layout>
    <div class="mb-4 text-center">
        <h2 class="text-2xl font-bold text-gray-900">Tạo tài khoản BeautyBook</h2>
        <p class="text-sm text-gray-600 mt-1">Trải nghiệm dịch vụ làm đẹp & chăm sóc sức khỏe cao cấp</p>
    </div>

    <form method="POST" action="{{ route('register') }}" x-data="{ role: '{{ old('role', 'customer') }}' }">
        @csrf

        <!-- Role Selection -->
        <div class="mb-4">
            <label class="block text-sm font-semibold text-gray-700 mb-2">Bạn tham gia với tư cách:</label>
            <div class="grid grid-cols-2 gap-3">
                <label class="relative flex flex-col items-center justify-center p-3 border rounded-xl cursor-pointer transition-all duration-200"
                    :class="role === 'customer' ? 'border-pink-500 bg-pink-50/50 text-pink-700 ring-2 ring-pink-500' : 'border-gray-200 hover:border-gray-300 text-gray-600'">
                    <input type="radio" name="role" value="customer" class="sr-only" x-model="role">
                    <span class="text-xl mb-1">💇‍♀️</span>
                    <span class="font-medium text-sm">Khách hàng</span>
                    <span class="text-xs text-gray-500 mt-0.5">Đặt lịch làm đẹp</span>
                </label>
                <label class="relative flex flex-col items-center justify-center p-3 border rounded-xl cursor-pointer transition-all duration-200"
                    :class="role === 'business_owner' ? 'border-pink-500 bg-pink-50/50 text-pink-700 ring-2 ring-pink-500' : 'border-gray-200 hover:border-gray-300 text-gray-600'">
                    <input type="radio" name="role" value="business_owner" class="sr-only" x-model="role">
                    <span class="text-xl mb-1">🏢</span>
                    <span class="font-medium text-sm">Chủ Salon / Spa</span>
                    <span class="text-xs text-gray-500 mt-0.5">Quản lý cơ sở & dịch vụ</span>
                </label>
            </div>
            <x-input-error :messages="$errors->get('role')" class="mt-2" />
        </div>

        <!-- Name -->
        <div>
            <x-input-label for="name" value="Họ và tên" />
            <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required autofocus autocomplete="name" placeholder="Nguyễn Văn A" />
            <x-input-error :messages="$errors->get('name')" class="mt-2" />
        </div>

        <!-- Email Address -->
        <div class="mt-4">
            <x-input-label for="email" value="Địa chỉ Email" />
            <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autocomplete="username" placeholder="email@example.com" />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <!-- Phone Number -->
        <div class="mt-4">
            <x-input-label for="phone" value="Số điện thoại" />
            <x-text-input id="phone" class="block mt-1 w-full" type="tel" name="phone" :value="old('phone')" placeholder="0901234567" />
            <x-input-error :messages="$errors->get('phone')" class="mt-2" />
        </div>

        <!-- Business Name (conditional for Business Owner) -->
        <div class="mt-4 p-3 bg-pink-50/40 rounded-xl border border-pink-100 transition-all duration-200" x-show="role === 'business_owner'" x-cloak>
            <x-input-label for="business_name" value="Tên Doanh nghiệp / Salon / Spa" class="text-pink-900 font-semibold" />
            <x-text-input id="business_name" class="block mt-1 w-full" type="text" name="business_name" :value="old('business_name')" placeholder="Ví dụ: Luxe Spa Sài Gòn" />
            <p class="text-xs text-gray-500 mt-1">Hệ thống sẽ tự động khởi tạo cơ sở kinh doanh đầu tiên cho bạn.</p>
            <x-input-error :messages="$errors->get('business_name')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" value="Mật khẩu" />
            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="new-password" placeholder="Tối thiểu 8 ký tự" />
            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Confirm Password -->
        <div class="mt-4">
            <x-input-label for="password_confirmation" value="Xác nhận mật khẩu" />
            <x-text-input id="password_confirmation" class="block mt-1 w-full" type="password" name="password_confirmation" required autocomplete="new-password" placeholder="Nhập lại mật khẩu" />
            <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
        </div>

        <div class="flex items-center justify-between mt-6">
            <a class="underline text-sm text-gray-600 hover:text-pink-600 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-pink-500" href="{{ route('login') }}">
                Đã có tài khoản? Đăng nhập
            </a>

            <button type="submit" class="inline-flex items-center px-5 py-2.5 bg-pink-600 hover:bg-pink-700 active:bg-pink-800 text-white font-medium text-sm rounded-xl shadow-sm transition ease-in-out duration-150">
                Đăng ký ngay
            </button>
        </div>
    </form>
</x-guest-layout>
