<x-customer-layout>
    <x-slot name="title">Đặt lịch hẹn mới</x-slot>

    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-stone-200/80 shadow-xs max-w-3xl mx-auto" 
        x-data="bookingForm({
            branches: {{ Js::from($branches) }},
            selectedBranchId: '{{ old('branch_id', $selectedBranchId ?? '') }}',
            selectedServiceId: '{{ old('service_id', $selectedServiceId ?? '') }}',
            selectedStaffId: '{{ old('staff_id', '') }}'
        })">

        <div class="border-b border-stone-200 pb-4 mb-6">
            <h1 class="text-2xl font-bold text-stone-900">Đặt lịch dịch vụ làm đẹp</h1>
            <p class="text-xs text-stone-500 mt-1">Chọn chi nhánh, dịch vụ và thời gian phù hợp với bạn</p>
        </div>

        <form method="POST" action="{{ route('customer.bookings.store') }}" class="space-y-6">
            @csrf

            <!-- 1. Select Branch -->
            <div>
                <label class="block text-sm font-bold text-stone-800 mb-2">1. Chọn Chi nhánh Salon / Spa <span class="text-rose-500">*</span></label>
                <select name="branch_id" x-model="selectedBranchId" @change="onBranchChange()" required class="w-full px-4 py-2.5 bg-stone-50 border border-stone-200 rounded-2xl text-sm font-medium text-stone-800 focus:bg-white focus:ring-2 focus:ring-pink-500">
                    <option value="">-- Chọn cơ sở thực hiện --</option>
                    <template x-for="branch in branches" :key="branch.id">
                        <option :value="branch.id" x-text="branch.business.name + ' — ' + branch.name + ' (' + (branch.district ? branch.district.name : '') + ')'"></option>
                    </template>
                </select>
                <x-input-error :messages="$errors->get('branch_id')" class="mt-1" />
            </div>

            <!-- 2. Select Service -->
            <div>
                <label class="block text-sm font-bold text-stone-800 mb-2">2. Chọn Dịch vụ Làm đẹp <span class="text-rose-500">*</span></label>
                <select name="service_id" x-model="selectedServiceId" @change="onServiceChange()" required :disabled="!selectedBranchId" class="w-full px-4 py-2.5 bg-stone-50 border border-stone-200 rounded-2xl text-sm font-medium text-stone-800 focus:bg-white focus:ring-2 focus:ring-pink-500 disabled:opacity-50">
                    <option value="">-- Chọn dịch vụ --</option>
                    <template x-for="service in currentServices" :key="service.id">
                        <option :value="service.id" x-text="service.name + ' (' + service.duration_minutes + 'p - ' + formatMoney(service.discount_price || service.price) + ')'"></option>
                    </template>
                </select>
                <x-input-error :messages="$errors->get('service_id')" class="mt-1" />
            </div>

            <!-- 3. Select Staff (Optional) -->
            <div>
                <label class="block text-sm font-bold text-stone-800 mb-2">3. Chọn Chuyên viên / Kỹ thuật viên (Không bắt buộc)</label>
                <select name="staff_id" x-model="selectedStaffId" :disabled="!selectedBranchId" class="w-full px-4 py-2.5 bg-stone-50 border border-stone-200 rounded-2xl text-sm font-medium text-stone-800 focus:bg-white focus:ring-2 focus:ring-pink-500 disabled:opacity-50">
                    <option value="">✨ Salon tự sắp xếp chuyên viên phù hợp nhất</option>
                    <template x-for="st in currentStaff" :key="st.id">
                        <option :value="st.id" x-text="st.name + ' (' + (st.position || 'Kỹ thuật viên') + ')'"></option>
                    </template>
                </select>
                <x-input-error :messages="$errors->get('staff_id')" class="mt-1" />
            </div>

            <!-- 4. Date & Time -->
            <div>
                <label class="block text-sm font-bold text-stone-800 mb-2">4. Thời gian hẹn <span class="text-rose-500">*</span></label>
                <input type="datetime-local" 
                    name="appointment_date" 
                    value="{{ old('appointment_date', now()->addDay()->setTime(10, 0)->format('Y-m-d\TH:i')) }}" 
                    required 
                    min="{{ now()->format('Y-m-d\TH:i') }}"
                    class="w-full px-4 py-2.5 bg-stone-50 border border-stone-200 rounded-2xl text-sm font-medium text-stone-800 focus:bg-white focus:ring-2 focus:ring-pink-500">
                <p class="text-[11px] text-stone-400 mt-1">Vui lòng chọn thời gian trong khung giờ hoạt động (08:30 - 21:00).</p>
                <x-input-error :messages="$errors->get('appointment_date')" class="mt-1" />
            </div>

            <!-- 5. Notes -->
            <div>
                <label class="block text-sm font-bold text-stone-800 mb-2">5. Ghi chú thêm cho Salon</label>
                <textarea name="notes" rows="3" placeholder="Ví dụ: Da tôi nhạy cảm, muốn tạo kiểu nhẹ nhàng..." class="w-full px-4 py-2.5 bg-stone-50 border border-stone-200 rounded-2xl text-sm text-stone-800 focus:bg-white focus:ring-2 focus:ring-pink-500">{{ old('notes') }}</textarea>
                <x-input-error :messages="$errors->get('notes')" class="mt-1" />
            </div>

            <!-- Order Summary Card -->
            <template x-if="selectedService">
                <div class="p-4 bg-pink-50/50 rounded-2xl border border-pink-200 space-y-2 text-sm">
                    <div class="font-bold text-pink-900 flex items-center justify-between">
                        <span x-text="selectedService.name"></span>
                        <span x-text="formatMoney(selectedService.discount_price || selectedService.price)"></span>
                    </div>
                    <div class="text-xs text-stone-500 flex items-center justify-between">
                        <span>Thời lượng dự kiến:</span>
                        <span x-text="selectedService.duration_minutes + ' phút'"></span>
                    </div>
                </div>
            </template>

            <!-- Submit Buttons -->
            <div class="flex items-center justify-between pt-4 border-t border-stone-200">
                <a href="{{ route('customer.bookings.index') }}" class="text-xs font-semibold text-stone-500 hover:text-stone-800">
                    ← Quay lại
                </a>
                <button type="submit" class="px-6 py-3 bg-pink-600 hover:bg-pink-700 active:bg-pink-800 text-white font-bold text-sm rounded-2xl shadow-md shadow-pink-500/20 transition">
                    Xác nhận đặt lịch hẹn
                </button>
            </div>
        </form>
    </div>

    <script>
        function bookingForm(config) {
            return {
                branches: config.branches,
                selectedBranchId: config.selectedBranchId,
                selectedServiceId: config.selectedServiceId,
                selectedStaffId: config.selectedStaffId,
                get currentBranch() {
                    return this.branches.find(b => b.id == this.selectedBranchId);
                },
                get currentServices() {
                    return this.currentBranch ? this.currentBranch.services : [];
                },
                get currentStaff() {
                    return this.currentBranch ? this.currentBranch.staff : [];
                },
                get selectedService() {
                    return this.currentServices.find(s => s.id == this.selectedServiceId);
                },
                onBranchChange() {
                    this.selectedServiceId = '';
                    this.selectedStaffId = '';
                },
                onServiceChange() {
                    // keep selection
                },
                formatMoney(val) {
                    return new Intl.NumberFormat('vi-VN', { style: 'currency', currency: 'VND' }).format(val);
                }
            }
        }
    </script>
</x-customer-layout>
