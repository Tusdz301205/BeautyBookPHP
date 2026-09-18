<x-customer-layout>
    <x-slot name="title">Đánh giá Dịch vụ #{{ $booking->booking_code }}</x-slot>

    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-stone-200/80 shadow-xs max-w-2xl mx-auto space-y-6"
        x-data="{ rating: {{ old('overall_rating', 5) }} }">

        <div class="border-b border-stone-200 pb-4">
            <h1 class="text-2xl font-bold text-stone-900">Đánh giá trải nghiệm dịch vụ</h1>
            <p class="text-xs text-stone-500 mt-1">Lịch hẹn #{{ $booking->booking_code }} tại {{ $booking->branch?->business?->name }}</p>
        </div>

        <form method="POST" action="{{ route('customer.reviews.store') }}" class="space-y-6">
            @csrf
            <input type="hidden" name="booking_id" value="{{ $booking->id }}">

            <!-- Rating Stars Selection -->
            <div>
                <label class="block text-sm font-bold text-stone-800 mb-2">Mức độ hài lòng của bạn <span class="text-rose-500">*</span></label>
                <div class="flex items-center gap-2">
                    <input type="hidden" name="overall_rating" :value="rating">
                    <template x-for="i in 5" :key="i">
                        <button type="button" @click="rating = i" class="text-3xl transition transform hover:scale-110">
                            <span :class="i <= rating ? 'text-amber-400' : 'text-stone-300'">★</span>
                        </button>
                    </template>
                    <span class="text-xs font-semibold text-stone-600 ml-2" x-text="rating + ' / 5 sao'"></span>
                </div>
                <x-input-error :messages="$errors->get('overall_rating')" class="mt-1" />
            </div>

            <!-- Comment -->
            <div>
                <label class="block text-sm font-bold text-stone-800 mb-2">Nhận xét chi tiết <span class="text-rose-500">*</span></label>
                <textarea name="comment" rows="4" required placeholder="Chia sẻ cảm nhận về tay nghề kỹ thuật viên, không gian, sự sạch sẽ và thái độ phục vụ..." class="w-full px-4 py-3 bg-stone-50 border border-stone-200 rounded-2xl text-sm text-stone-800 focus:bg-white focus:ring-2 focus:ring-pink-500">{{ old('comment') }}</textarea>
                <x-input-error :messages="$errors->get('comment')" class="mt-1" />
            </div>

            <!-- Anonymous Option -->
            <div>
                <label class="flex items-center gap-2.5 cursor-pointer">
                    <input type="checkbox" name="is_anonymous" value="1" {{ old('is_anonymous') ? 'checked' : '' }} class="rounded border-stone-300 text-pink-600 focus:ring-pink-500">
                    <span class="text-xs text-stone-700 font-medium">Ẩn danh tính khi hiển thị công khai trên trang salon</span>
                </label>
            </div>

            <div class="flex items-center justify-between pt-4 border-t border-stone-200">
                <a href="{{ route('customer.bookings.show', $booking->id) }}" class="text-xs font-semibold text-stone-500 hover:text-stone-800">
                    ← Hủy & Quay lại
                </a>
                <button type="submit" class="px-6 py-2.5 bg-pink-600 hover:bg-pink-700 text-white font-bold text-xs rounded-xl shadow-xs transition">
                    Gửi đánh giá
                </button>
            </div>
        </form>
    </div>
</x-customer-layout>
