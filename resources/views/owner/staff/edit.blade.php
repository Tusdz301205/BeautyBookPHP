<x-owner-layout>
    <x-slot name="title">Sửa thông tin {{ $staff->name }}</x-slot>

    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-stone-200/80 shadow-xs max-w-2xl mx-auto space-y-6">
        <div class="border-b border-stone-200 pb-4">
            <h1 class="text-2xl font-bold text-stone-900">Sửa thông tin Nhân viên</h1>
            <p class="text-xs text-stone-500 mt-1">{{ $staff->name }}</p>
        </div>

        <form method="POST" action="{{ route('owner.staff.update', $staff->id) }}" enctype="multipart/form-data" class="space-y-6">
            @csrf
            @method('PUT')

            <!-- Branch -->
            <div>
                <label class="block text-sm font-bold text-stone-700 mb-1">Chi nhánh làm việc *</label>
                <select name="branch_id" required class="w-full px-3 py-2 bg-stone-50 border border-stone-200 rounded-xl text-sm focus:bg-white focus:ring-2 focus:ring-pink-500">
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ old('branch_id', $staff->branch_id) == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Name & Position -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="name" value="Họ và tên *" />
                    <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name', $staff->name)" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="position" value="Chức danh / Vị trí" />
                    <x-text-input id="position" class="block mt-1 w-full" type="text" name="position" :value="old('position', $staff->position)" />
                    <x-input-error :messages="$errors->get('position')" class="mt-1" />
                </div>
            </div>

            <!-- Phone & Email -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="phone" value="Số điện thoại" />
                    <x-text-input id="phone" class="block mt-1 w-full" type="tel" name="phone" :value="old('phone', $staff->phone)" />
                    <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="email" value="Email" />
                    <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email', $staff->email)" />
                    <x-input-error :messages="$errors->get('email')" class="mt-1" />
                </div>
            </div>

            <!-- Bio -->
            <div>
                <x-input-label for="bio" value="Giới thiệu kinh nghiệm & tay nghề" />
                <textarea id="bio" name="bio" rows="2" class="block mt-1 w-full px-3 py-2 bg-stone-50 border border-stone-200 rounded-xl text-sm">{{ old('bio', $staff->bio) }}</textarea>
                <x-input-error :messages="$errors->get('bio')" class="mt-1" />
            </div>

            <!-- Current Avatar & Upload New -->
            <div class="space-y-2">
                <x-input-label for="avatar" value="Ảnh chân dung (Tải lên để thay đổi)" />
                <div class="flex items-center gap-4">
                    <img src="{{ $staff->avatar_url }}" class="w-14 h-14 rounded-xl object-cover border border-stone-200">
                    <input type="file" name="avatar" accept="image/*" class="text-xs text-stone-500 file:mr-3 file:py-2 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-pink-50 file:text-pink-700 hover:file:bg-pink-100 cursor-pointer">
                </div>
                <x-input-error :messages="$errors->get('avatar')" class="mt-1" />
            </div>

            <!-- Services Checklist for this Branch -->
            @php $branchServices = $staff->branch->services; @endphp
            <div>
                <label class="block text-sm font-bold text-stone-700 mb-2">Dịch vụ nhân viên thực hiện ({{ $staff->branch->name }})</label>
                <div class="p-4 bg-stone-50 rounded-2xl border border-stone-200 max-h-48 overflow-y-auto space-y-2">
                    @foreach($branchServices as $svc)
                        <label class="flex items-center gap-2.5 text-xs text-stone-800 cursor-pointer hover:bg-white p-1 rounded-lg">
                            <input type="checkbox" name="services[]" value="{{ $svc->id }}" 
                                {{ $staff->services->contains($svc->id) ? 'checked' : '' }}
                                class="rounded border-stone-300 text-pink-600 focus:ring-pink-500">
                            <span class="font-medium">{{ $svc->name }} ({{ $svc->duration_minutes }}p)</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <!-- Status -->
            <div>
                <label class="block text-sm font-bold text-stone-700 mb-1">Trạng thái làm việc</label>
                <select name="status" class="w-full px-3 py-2 bg-stone-50 border border-stone-200 rounded-xl text-sm focus:bg-white focus:ring-2 focus:ring-pink-500">
                    <option value="active" {{ old('status', $staff->status) === 'active' ? 'selected' : '' }}>Đang làm việc</option>
                    <option value="inactive" {{ old('status', $staff->status) === 'inactive' ? 'selected' : '' }}>Tạm nghỉ</option>
                </select>
            </div>

            <div class="pt-4 border-t border-stone-200 flex items-center justify-between">
                <a href="{{ route('owner.staff.index') }}" class="text-xs font-semibold text-stone-500 hover:text-stone-800">
                    ← Quay lại
                </a>
                <button type="submit" class="px-6 py-2.5 bg-pink-600 hover:bg-pink-700 text-white font-bold text-xs rounded-xl shadow-xs transition">
                    Lưu cập nhật
                </button>
            </div>
        </form>
    </div>
</x-owner-layout>
