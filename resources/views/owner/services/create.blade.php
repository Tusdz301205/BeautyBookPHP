<x-owner-layout>
    <x-slot name="title">Thêm Dịch vụ mới</x-slot>

    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-stone-200/80 shadow-xs max-w-3xl mx-auto space-y-6">
        <div class="border-b border-stone-200 pb-4">
            <h1 class="text-2xl font-bold text-stone-900">Thêm Dịch vụ Làm đẹp mới</h1>
            <p class="text-xs text-stone-500 mt-1">Thiết lập thông tin bảng giá, thời lượng và hình ảnh dịch vụ</p>
        </div>

        <form method="POST" action="{{ route('owner.services.store') }}" enctype="multipart/form-data" class="space-y-6">
            @csrf

            <!-- Branch & Category -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-bold text-stone-700 mb-1">Áp dụng tại Chi nhánh *</label>
                    <select name="branch_id" required class="w-full px-3 py-2 bg-stone-50 border border-stone-200 rounded-xl text-sm focus:bg-white focus:ring-2 focus:ring-pink-500">
                        <option value="">-- Chọn chi nhánh --</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" {{ old('branch_id') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('branch_id')" class="mt-1" />
                </div>

                <div>
                    <label class="block text-sm font-bold text-stone-700 mb-1">Danh mục Dịch vụ</label>
                    <select name="category_id" class="w-full px-3 py-2 bg-stone-50 border border-stone-200 rounded-xl text-sm focus:bg-white focus:ring-2 focus:ring-pink-500">
                        <option value="">-- Chưa phân loại --</option>
                        @foreach($categories as $c)
                            <option value="{{ $c->id }}" {{ old('category_id') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                        @endforeach
                    </select>
                    <x-input-error :messages="$errors->get('category_id')" class="mt-1" />
                </div>
            </div>

            <!-- Service Name -->
            <div>
                <x-input-label for="name" value="Tên Dịch vụ *" />
                <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required placeholder="Ví dụ: Cắt tóc tạo kiểu Hàn Quốc..." />
                <x-input-error :messages="$errors->get('name')" class="mt-1" />
            </div>

            <!-- Pricing & Duration -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <x-input-label for="price" value="Giá gốc (VND) *" />
                    <x-text-input id="price" class="block mt-1 w-full" type="number" name="price" :value="old('price')" required min="0" step="1000" placeholder="500000" />
                    <x-input-error :messages="$errors->get('price')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="discount_price" value="Giá khuyến mãi (VND)" />
                    <x-text-input id="discount_price" class="block mt-1 w-full" type="number" name="discount_price" :value="old('discount_price')" min="0" step="1000" placeholder="400000 (nếu có)" />
                    <x-input-error :messages="$errors->get('discount_price')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="duration_minutes" value="Thời lượng (Phút) *" />
                    <x-text-input id="duration_minutes" class="block mt-1 w-full" type="number" name="duration_minutes" :value="old('duration_minutes', 60)" required min="5" step="5" />
                    <x-input-error :messages="$errors->get('duration_minutes')" class="mt-1" />
                </div>
            </div>

            <!-- Description -->
            <div>
                <x-input-label for="description" value="Mô tả chi tiết liệu trình" />
                <textarea id="description" name="description" rows="4" class="block mt-1 w-full px-3 py-2 bg-stone-50 border border-stone-200 rounded-xl text-sm text-stone-800 focus:bg-white focus:ring-2 focus:ring-pink-500" placeholder="Quy trình từng bước, các sản phẩm dùng kèm, đối tượng phù hợp...">{{ old('description') }}</textarea>
                <x-input-error :messages="$errors->get('description')" class="mt-1" />
            </div>

            <!-- Image Upload -->
            <div>
                <x-input-label for="image" value="Ảnh minh họa dịch vụ" />
                <input type="file" name="image" accept="image/*" class="mt-1 block w-full text-xs text-stone-500 file:mr-3 file:py-2 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-pink-50 file:text-pink-700 hover:file:bg-pink-100 cursor-pointer">
                <p class="text-[11px] text-stone-400 mt-1">Định dạng JPG, PNG, WebP tối đa 4MB</p>
                <x-input-error :messages="$errors->get('image')" class="mt-1" />
            </div>

            <!-- Options -->
            <div class="flex items-center gap-6 pt-2">
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="is_bookable" value="1" {{ old('is_bookable', true) ? 'checked' : '' }} class="rounded border-stone-300 text-pink-600 focus:ring-pink-500">
                    <span class="text-xs text-stone-700 font-semibold">Cho phép khách đặt lịch trực tuyến</span>
                </label>

                <div class="flex items-center gap-2">
                    <label class="text-xs text-stone-700 font-semibold">Trạng thái:</label>
                    <select name="status" class="px-2 py-1 bg-stone-50 border border-stone-200 rounded-lg text-xs">
                        <option value="active" {{ old('status') === 'active' ? 'selected' : '' }}>Hoạt động</option>
                        <option value="inactive" {{ old('status') === 'inactive' ? 'selected' : '' }}>Tạm dừng</option>
                    </select>
                </div>
            </div>

            <div class="pt-4 border-t border-stone-200 flex items-center justify-between">
                <a href="{{ route('owner.services.index') }}" class="text-xs font-semibold text-stone-500 hover:text-stone-800">
                    ← Quay lại
                </a>
                <button type="submit" class="px-6 py-2.5 bg-pink-600 hover:bg-pink-700 text-white font-bold text-xs rounded-xl shadow-xs transition">
                    Lưu dịch vụ
                </button>
            </div>
        </form>
    </div>
</x-owner-layout>
