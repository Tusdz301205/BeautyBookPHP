<x-owner-layout>
    <x-slot name="title">Thêm Nhân viên mới</x-slot>

    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-stone-200/80 shadow-xs max-w-2xl mx-auto space-y-6"
        x-data="{
            branches: {{ Js::from($branches) }},
            selectedBranchId: '{{ old('branch_id', '') }}',
            get currentServices() {
                const b = this.branches.find(x => x.id == this.selectedBranchId);
                return b ? b.services : [];
            }
        }">

        <div class="border-b border-stone-200 pb-4">
            <h1 class="text-2xl font-bold text-stone-900">Thêm Nhân viên / Kỹ thuật viên</h1>
            <p class="text-xs text-stone-500 mt-1">Phân công vào chi nhánh và gán các dịch vụ nhân viên có thể thực hiện</p>
        </div>

        <form method="POST" action="{{ route('owner.staff.store') }}" enctype="multipart/form-data" class="space-y-6">
            @csrf

            <!-- Branch -->
            <div>
                <label class="block text-sm font-bold text-stone-700 mb-1">Chi nhánh làm việc *</label>
                <select name="branch_id" x-model="selectedBranchId" required class="w-full px-3 py-2 bg-stone-50 border border-stone-200 rounded-xl text-sm focus:bg-white focus:ring-2 focus:ring-pink-500">
                    <option value="">-- Chọn chi nhánh --</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}" {{ old('branch_id') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('branch_id')" class="mt-1" />
            </div>

            <!-- Name & Position -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="name" value="Họ và tên *" />
                    <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name')" required placeholder="Ví dụ: Nguyễn Thị Hương" />
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="position" value="Chức danh / Vị trí" />
                    <x-text-input id="position" class="block mt-1 w-full" type="text" name="position" :value="old('position')" placeholder="Master Stylist, Chuyên viên Spa..." />
                    <x-input-error :messages="$errors->get('position')" class="mt-1" />
                </div>
            </div>

            <!-- Phone & Email -->
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <x-input-label for="phone" value="Số điện thoại" />
                    <x-text-input id="phone" class="block mt-1 w-full" type="tel" name="phone" :value="old('phone')" placeholder="0901234567" />
                    <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                </div>
                <div>
                    <x-input-label for="email" value="Email" />
                    <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" placeholder="staff@salondep.vn" />
                    <x-input-error :messages="$errors->get('email')" class="mt-1" />
                </div>
            </div>

            <!-- Bio -->
            <div>
                <x-input-label for="bio" value="Giới thiệu kinh nghiệm & tay nghề" />
                <textarea id="bio" name="bio" rows="2" class="block mt-1 w-full px-3 py-2 bg-stone-50 border border-stone-200 rounded-xl text-sm" placeholder="Kinh nghiệm, chứng chỉ đào tạo...">{{ old('bio') }}</textarea>
                <x-input-error :messages="$errors->get('bio')" class="mt-1" />
            </div>

            <!-- Avatar -->
            <div>
                <x-input-label for="avatar" value="Ảnh chân dung" />
                <input type="file" name="avatar" accept="image/*" class="mt-1 block w-full text-xs text-stone-500 file:mr-3 file:py-2 file:px-3 file:rounded-xl file:border-0 file:text-xs file:font-semibold file:bg-pink-50 file:text-pink-700 hover:file:bg-pink-100 cursor-pointer">
                <x-input-error :messages="$errors->get('avatar')" class="mt-1" />
            </div>

            <!-- Services Checklist (Dynamic based on selected branch) -->
            <div>
                <label class="block text-sm font-bold text-stone-700 mb-2">Dịch vụ nhân viên có thể thực hiện</label>
                <div class="p-4 bg-stone-50 rounded-2xl border border-stone-200 max-h-48 overflow-y-auto space-y-2">
                    <template x-if="!selectedBranchId">
                        <p class="text-xs text-stone-400 italic">Vui lòng chọn chi nhánh ở trên để hiển thị danh sách dịch vụ.</p>
                    </template>
                    <template x-for="svc in currentServices" :key="svc.id">
                        <label class="flex items-center gap-2.5 text-xs text-stone-800 cursor-pointer hover:bg-white p-1 rounded-lg">
                            <input type="checkbox" name="services[]" :value="svc.id" class="rounded border-stone-300 text-pink-600 focus:ring-pink-500">
                            <span class="font-medium" x-text="svc.name + ' (' + svc.duration_minutes + 'p)'"></span>
                        </label>
                    </template>
                </div>
            </div>

            <!-- Status -->
            <div>
                <label class="block text-sm font-bold text-stone-700 mb-1">Trạng thái làm việc</label>
                <select name="status" class="w-full px-3 py-2 bg-stone-50 border border-stone-200 rounded-xl text-sm focus:bg-white focus:ring-2 focus:ring-pink-500">
                    <option value="active" {{ old('status') === 'active' ? 'selected' : '' }}>Đang làm việc</option>
                    <option value="inactive" {{ old('status') === 'inactive' ? 'selected' : '' }}>Tạm nghỉ</option>
                </select>
            </div>

            <div class="pt-4 border-t border-stone-200 flex items-center justify-between">
                <a href="{{ route('owner.staff.index') }}" class="text-xs font-semibold text-stone-500 hover:text-stone-800">
                    ← Quay lại
                </a>
                <button type="submit" class="px-6 py-2.5 bg-pink-600 hover:bg-pink-700 text-white font-bold text-xs rounded-xl shadow-xs transition">
                    Lưu nhân viên
                </button>
            </div>
        </form>
    </div>
</x-owner-layout>
