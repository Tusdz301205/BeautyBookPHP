<x-owner-layout>
    <x-slot name="title">Chỉnh sửa Chi nhánh {{ $branch->name }}</x-slot>

    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-stone-200/80 shadow-xs max-w-3xl mx-auto space-y-6">
        <div class="border-b border-stone-200 pb-4">
            <h1 class="text-2xl font-bold text-stone-900">Chỉnh sửa Chi nhánh & Giờ mở cửa</h1>
            <p class="text-xs text-stone-500 mt-1">{{ $branch->name }}</p>
        </div>

        <form method="POST" action="{{ route('owner.branches.update', $branch->id) }}" class="space-y-6">
            @csrf
            @method('PUT')

            <!-- Basic Info -->
            <div class="space-y-4">
                <div>
                    <x-input-label for="name" value="Tên Chi nhánh *" />
                    <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" :value="old('name', $branch->name)" required />
                    <x-input-error :messages="$errors->get('name')" class="mt-1" />
                </div>

                <div>
                    <x-input-label for="address" value="Địa chỉ cụ thể *" />
                    <x-text-input id="address" class="block mt-1 w-full" type="text" name="address" :value="old('address', $branch->address)" required />
                    <x-input-error :messages="$errors->get('address')" class="mt-1" />
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <x-input-label for="phone" value="Số điện thoại hotline" />
                        <x-text-input id="phone" class="block mt-1 w-full" type="tel" name="phone" :value="old('phone', $branch->phone)" />
                        <x-input-error :messages="$errors->get('phone')" class="mt-1" />
                    </div>
                    <div>
                        <x-input-label for="email" value="Email chi nhánh" />
                        <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email', $branch->email)" />
                        <x-input-error :messages="$errors->get('email')" class="mt-1" />
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-bold text-stone-700 mb-1">Trạng thái hoạt động *</label>
                    <select name="status" class="w-full px-3 py-2 bg-stone-50 border border-stone-200 rounded-xl text-sm focus:bg-white focus:ring-2 focus:ring-pink-500">
                        <option value="active" {{ old('status', $branch->status) === 'active' ? 'selected' : '' }}>Hoạt động bình thường</option>
                        <option value="inactive" {{ old('status', $branch->status) === 'inactive' ? 'selected' : '' }}>Tạm ngưng nhận khách</option>
                    </select>
                </div>
            </div>

            <!-- Working Hours Schedule Table -->
            <div class="pt-6 border-t border-stone-200">
                <h3 class="text-base font-bold text-stone-900 mb-3">Khung giờ mở cửa trong tuần</h3>
                <p class="text-xs text-stone-500 mb-4">Cài đặt giờ mở/đóng cửa để khách hàng chỉ có thể đặt lịch trong khung giờ này</p>

                <div class="border border-stone-200 rounded-2xl overflow-hidden divide-y divide-stone-100 text-xs">
                    @php $days = ['Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy', 'Chủ Nhật']; @endphp
                    @for($d = 0; $d <= 6; $d++)
                        @php $wh = $branch->workingHours->firstWhere('day_of_week', $d); @endphp
                        <div class="p-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-stone-50/50">
                            <span class="font-bold text-stone-800 w-24">{{ $days[$d] }}</span>
                            
                            <div class="flex items-center gap-2">
                                <label class="text-stone-500">Mở:</label>
                                <input type="time" name="working_hours[{{ $d }}][open_time]" value="{{ substr($wh?->open_time ?? '08:30', 0, 5) }}" class="px-2 py-1 bg-white border border-stone-200 rounded-lg text-xs">
                                
                                <label class="text-stone-500 ml-2">Đóng:</label>
                                <input type="time" name="working_hours[{{ $d }}][close_time]" value="{{ substr($wh?->close_time ?? '21:00', 0, 5) }}" class="px-2 py-1 bg-white border border-stone-200 rounded-lg text-xs">
                            </div>

                            <label class="inline-flex items-center gap-1.5 cursor-pointer">
                                <input type="checkbox" name="working_hours[{{ $d }}][is_closed]" value="1" {{ $wh?->is_closed ? 'checked' : '' }} class="rounded border-stone-300 text-pink-600">
                                <span class="text-stone-600 font-medium">Nghỉ (Đóng cửa)</span>
                            </label>
                        </div>
                    @endfor
                </div>
            </div>

            <div class="pt-4 border-t border-stone-200 flex items-center justify-between">
                <a href="{{ route('owner.branches.index') }}" class="text-xs font-semibold text-stone-500 hover:text-stone-800">
                    ← Quay lại
                </a>
                <button type="submit" class="px-6 py-2.5 bg-pink-600 hover:bg-pink-700 text-white font-bold text-xs rounded-xl shadow-xs transition">
                    Lưu cập nhật
                </button>
            </div>
        </form>
    </div>
</x-owner-layout>
