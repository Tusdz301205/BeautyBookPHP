<x-owner-layout>
    <x-slot name="title">Thông tin Doanh nghiệp Salon</x-slot>

    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-stone-200/80 shadow-xs max-w-3xl mx-auto space-y-6">
        <div class="border-b border-stone-200 pb-4">
            <h1 class="text-2xl font-bold text-stone-900">Hồ sơ Doanh nghiệp & Thương hiệu</h1>
            <p class="text-xs text-stone-500 mt-1">Thông tin hiển thị công khai trên website cho khách hàng tìm kiếm</p>
        </div>

        <form method="POST" action="{{ route('owner.business.update') }}" enctype="multipart/form-data" class="space-y-6">
            @csrf
            @method('PUT')

            <!-- Logo Upload -->
            <div class="flex items-center gap-6 pb-6 border-b border-stone-100" x-data="{ logoPreview: null }">
                <img :src="logoPreview ? logoPreview : '{{ $business->logo_url }}'" alt="{{ $business->name }}" class="w-20 h-20 rounded-2xl object-cover border-2 border-stone-200 shadow-sm bg-stone-50">
                <div class="space-y-2">
                    <label class="block text-xs font-bold text-stone-700">Logo thương hiệu</label>
                    <input type="file" name="logo" accept="image/*" class="text-xs text-stone-500 file:mr-3 file:py-2 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-pink-50 file:text-pink-700 hover:file:bg-pink-100 cursor-pointer"
                        @change="
                            const reader = new FileReader();
                            reader.onload = (e) => { logoPreview = e.target.result; };
                            reader.readAsDataURL($event.target.files[0]);
                        ">
                    <p class="text-[11px] text-stone-400">Định dạng PNG, JPG, WebP tối đa 2MB</p>
                    <x-input-error :messages="$errors->get('logo')" class="mt-1" />
                </div>
            </div>

            <!-- Business Name -->
            <div>
                <x-input-label for="name" value="Tên Doanh nghiệp / Chuỗi Salon *" />
                <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name', $business->name)" required />
                <x-input-error :messages="$errors->get('name')" class="mt-1" />
            </div>

            <!-- Phone & Email -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="phone" value="Hotline doanh nghiệp" />
                    <x-text-input id="phone" class="block mt-1 w-full" type="tel" name="phone" :value="old('phone', $business->phone)" placeholder="02838999888" />
                    <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="email" value="Email liên hệ kinh doanh" />
                    <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email', $business->email)" placeholder="contact@salondep.vn" />
                    <x-input-error :messages="$errors->get('email')" class="mt-1" />
                </div>
            </div>

            <!-- Website & Tax code -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="website" value="Website chính thức" />
                    <x-text-input id="website" class="block mt-1 w-full" type="url" name="website" :value="old('website', $business->website)" placeholder="https://salondep.vn" />
                    <x-input-error :messages="$errors->get('website')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="tax_code" value="Mã số thuế doanh nghiệp" />
                    <x-text-input id="tax_code" class="block mt-1 w-full" type="text" name="tax_code" :value="old('tax_code', $business->tax_code)" placeholder="0312345678" />
                    <x-input-error :messages="$errors->get('tax_code')" class="mt-1" />
                </div>
            </div>

            <!-- Description -->
            <div>
                <x-input-label for="description" value="Giới thiệu về doanh nghiệp & dịch vụ" />
                <textarea id="description" name="description" rows="4" class="block mt-1 w-full px-3 py-2 bg-stone-50 border border-stone-200 rounded-xl text-sm text-stone-800 focus:bg-white focus:ring-2 focus:ring-pink-500">{{ old('description', $business->description) }}</textarea>
                <x-input-error :messages="$errors->get('description')" class="mt-1" />
            </div>

            <div class="pt-4 border-t border-stone-200 flex justify-end">
                <button type="submit" class="px-6 py-2.5 bg-pink-600 hover:bg-pink-700 text-white font-bold text-xs rounded-xl shadow-xs transition">
                    Cập nhật thông tin Salon
                </button>
            </div>
        </form>
    </div>
</x-owner-layout>
