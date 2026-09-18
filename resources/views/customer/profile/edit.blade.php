<x-customer-layout>
    <x-slot name="title">Thông tin cá nhân</x-slot>

    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-stone-200/80 shadow-xs max-w-2xl mx-auto space-y-6">
        <div class="border-b border-stone-200 pb-4">
            <h1 class="text-2xl font-bold text-stone-900">Thông tin cá nhân</h1>
            <p class="text-xs text-stone-500 mt-1">Cập nhật hồ sơ tài khoản và hình đại diện</p>
        </div>

        <form method="POST" action="{{ route('customer.profile.update') }}" enctype="multipart/form-data" class="space-y-6">
            @csrf
            @method('PUT')

            <!-- Avatar Preview & Upload -->
            <div class="flex items-center gap-6 pb-6 border-b border-stone-100" x-data="{ photoName: null, photoPreview: null }">
                <div class="relative">
                    <img :src="photoPreview ? photoPreview : '{{ $user->avatar_url }}'" alt="{{ $user->name }}" class="w-20 h-20 rounded-full object-cover border-2 border-stone-200 shadow-sm">
                </div>

                <div class="space-y-2">
                    <label class="block text-xs font-bold text-stone-700">Ảnh đại diện</label>
                    <input type="file" name="avatar" accept="image/*" class="text-xs text-stone-500 file:mr-3 file:py-2 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-pink-50 file:text-pink-700 hover:file:bg-pink-100 cursor-pointer"
                        @change="
                            photoName = $event.target.files[0].name;
                            const reader = new FileReader();
                            reader.onload = (e) => { photoPreview = e.target.result; };
                            reader.readAsDataURL($event.target.files[0]);
                        ">
                    <p class="text-[11px] text-stone-400">Định dạng JPG, PNG, WebP tối đa 2MB</p>
                    <x-input-error :messages="$errors->get('avatar')" class="mt-1" />
                </div>
            </div>

            <!-- Full Name -->
            <div>
                <x-input-label for="name" value="Họ và tên" />
                <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name', $user->name)" required />
                <x-input-error :messages="$errors->get('name')" class="mt-1" />
            </div>

            <!-- Email (readonly) -->
            <div>
                <x-input-label for="email" value="Địa chỉ Email (Không đổi)" />
                <x-text-input id="email" class="block mt-1 w-full bg-stone-100 text-stone-500 cursor-not-allowed" type="email" :value="$user->email" disabled />
            </div>

            <!-- Phone -->
            <div>
                <x-input-label for="phone" value="Số điện thoại" />
                <x-text-input id="phone" class="block mt-1 w-full" type="tel" name="phone" :value="old('phone', $user->phone)" />
                <x-input-error :messages="$errors->get('phone')" class="mt-1" />
            </div>

            <!-- Gender & Birthday -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="gender" value="Giới tính" />
                    <select id="gender" name="gender" class="block mt-1 w-full px-3 py-2 bg-stone-50 border border-stone-200 rounded-xl text-sm text-stone-800 focus:bg-white focus:ring-2 focus:ring-pink-500">
                        <option value="">-- Chọn giới tính --</option>
                        <option value="male" {{ old('gender', $user->gender) === 'male' ? 'selected' : '' }}>Nam</option>
                        <option value="female" {{ old('gender', $user->gender) === 'female' ? 'selected' : '' }}>Nữ</option>
                        <option value="other" {{ old('gender', $user->gender) === 'other' ? 'selected' : '' }}>Khác</option>
                    </select>
                    <x-input-error :messages="$errors->get('gender')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="date_of_birth" value="Ngày sinh" />
                    <x-text-input id="date_of_birth" class="block mt-1 w-full" type="date" name="date_of_birth" :value="old('date_of_birth', $user->date_of_birth?->format('Y-m-d'))" />
                    <x-input-error :messages="$errors->get('date_of_birth')" class="mt-1" />
                </div>
            </div>

            <!-- Address -->
            <div>
                <x-input-label for="address" value="Địa chỉ liên hệ" />
                <textarea id="address" name="address" rows="2" class="block mt-1 w-full px-3 py-2 bg-stone-50 border border-stone-200 rounded-xl text-sm text-stone-800 focus:bg-white focus:ring-2 focus:ring-pink-500">{{ old('address', $user->address) }}</textarea>
                <x-input-error :messages="$errors->get('address')" class="mt-1" />
            </div>

            <div class="pt-4 border-t border-stone-200 flex justify-end">
                <button type="submit" class="px-6 py-2.5 bg-pink-600 hover:bg-pink-700 text-white font-bold text-xs rounded-xl shadow-xs transition">
                    Lưu thay đổi
                </button>
            </div>
        </form>
    </div>
</x-customer-layout>
