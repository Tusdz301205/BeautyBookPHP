<x-owner-layout>
    <x-slot name="title">Thêm Chi nhánh mới</x-slot>

    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-stone-200/80 shadow-xs max-w-2xl mx-auto space-y-6"
        x-data="{
            provinces: {{ Js::from($provinces) }},
            selectedProvinceId: '',
            get districts() {
                const prov = this.provinces.find(p => p.id == this.selectedProvinceId);
                return prov ? prov.districts : [];
            }
        }">

        <div class="border-b border-stone-200 pb-4">
            <h1 class="text-2xl font-bold text-stone-900">Thêm Chi nhánh mới</h1>
            <p class="text-xs text-stone-500 mt-1">Mở rộng mạng lưới phục vụ khách hàng của bạn</p>
        </div>

        <form method="POST" action="{{ route('owner.branches.store') }}" class="space-y-6">
            @csrf

            <!-- Name -->
            <div>
                <x-input-label for="name" value="Tên Chi nhánh *" />
                <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required placeholder="Ví dụ: Chi nhánh Quận 1, Flagship Hoàn Kiếm..." />
                <x-input-error :messages="$errors->get('name')" class="mt-1" />
            </div>

            <!-- Province & District -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-bold text-stone-700 mb-1">Tỉnh / Thành phố</label>
                    <select x-model="selectedProvinceId" class="w-full px-3 py-2 bg-stone-50 border border-stone-200 rounded-xl text-sm focus:bg-white focus:ring-2 focus:ring-pink-500">
                        <option value="">-- Chọn Tỉnh / TP --</option>
                        <template x-for="p in provinces" :key="p.id">
                            <option :value="p.id" x-text="p.name"></option>
                        </template>
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-bold text-stone-700 mb-1">Quận / Huyện</label>
                    <select name="district_id" class="w-full px-3 py-2 bg-stone-50 border border-stone-200 rounded-xl text-sm focus:bg-white focus:ring-2 focus:ring-pink-500">
                        <option value="">-- Chọn Quận / Huyện --</option>
                        <template x-for="d in districts" :key="d.id">
                            <option :value="d.id" x-text="d.name" :selected="d.id == '{{ old('district_id') }}'"></option>
                        </template>
                    </select>
                    <x-input-error :messages="$errors->get('district_id')" class="mt-1" />
                </div>
            </div>

            <!-- Address -->
            <div>
                <x-input-label for="address" value="Địa chỉ cụ thể (Số nhà, đường, phường) *" />
                <x-text-input id="address" class="block mt-1 w-full" type="text" name="address" :value="old('address')" required placeholder="Ví dụ: 88 Nguyễn Huệ, P. Bến Nghé" />
                <x-input-error :messages="$errors->get('address')" class="mt-1" />
            </div>

            <!-- Phone & Email -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="phone" value="Số điện thoại hotline cơ sở" />
                    <x-text-input id="phone" class="block mt-1 w-full" type="tel" name="phone" :value="old('phone')" placeholder="02838999881" />
                    <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="email" value="Email chi nhánh" />
                    <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" placeholder="branch@salondep.vn" />
                    <x-input-error :messages="$errors->get('email')" class="mt-1" />
                </div>
            </div>

            <!-- Latitude & Longitude (for map) -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="latitude" value="Vĩ độ (Latitude, tùy chọn cho Bản đồ)" />
                    <x-text-input id="latitude" class="block mt-1 w-full" type="number" step="any" name="latitude" :value="old('latitude', '10.7745')" placeholder="10.7745" />
                    <x-input-error :messages="$errors->get('latitude')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="longitude" value="Kinh độ (Longitude, tùy chọn cho Bản đồ)" />
                    <x-text-input id="longitude" class="block mt-1 w-full" type="number" step="any" name="longitude" :value="old('longitude', '106.7032')" placeholder="106.7032" />
                    <x-input-error :messages="$errors->get('longitude')" class="mt-1" />
                </div>
            </div>

            <!-- Status -->
            <div>
                <label class="block text-sm font-bold text-stone-700 mb-1">Trạng thái hoạt động *</label>
                <select name="status" class="w-full px-3 py-2 bg-stone-50 border border-stone-200 rounded-xl text-sm focus:bg-white focus:ring-2 focus:ring-pink-500">
                    <option value="active" {{ old('status') === 'active' ? 'selected' : '' }}>Hoạt động bình thường</option>
                    <option value="inactive" {{ old('status') === 'inactive' ? 'selected' : '' }}>Tạm ngưng nhận khách</option>
                </select>
                <x-input-error :messages="$errors->get('status')" class="mt-1" />
            </div>

            <div class="pt-4 border-t border-stone-200 flex items-center justify-between">
                <a href="{{ route('owner.branches.index') }}" class="text-xs font-semibold text-stone-500 hover:text-stone-800">
                    ← Quay lại
                </a>
                <button type="submit" class="px-6 py-2.5 bg-pink-600 hover:bg-pink-700 text-white font-bold text-xs rounded-xl shadow-xs transition">
                    Lưu chi nhánh
                </button>
            </div>
        </form>
    </div>
</x-owner-layout>
