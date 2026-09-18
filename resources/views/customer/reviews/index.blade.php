<x-customer-layout>
    <x-slot name="title">Đánh giá của tôi</x-slot>

    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-stone-200/80 shadow-xs space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-stone-900">Đánh giá dịch vụ đã trải nghiệm</h1>
            <p class="text-xs text-stone-500 mt-1">Ý kiến đóng góp quý báu giúp các Salon & Spa nâng cao chất lượng phục vụ</p>
        </div>

        @if($reviews->isEmpty())
            <div class="text-center py-16 text-stone-400 text-xs">
                <span class="text-4xl block mb-2">⭐</span>
                Bạn chưa viết đánh giá nào. Sau khi hoàn thành một lịch hẹn, hãy để lại cảm nhận của bạn nhé!
            </div>
        @else
            <div class="space-y-4">
                @foreach($reviews as $rev)
                    <div class="p-5 bg-stone-50/70 rounded-2xl border border-stone-200 space-y-3">
                        <div class="flex items-center justify-between">
                            <div>
                                <h3 class="font-bold text-sm text-stone-900">{{ $rev->branch?->business?->name }} — {{ $rev->branch?->name }}</h3>
                                <p class="text-xs text-stone-500">Mã lịch hẹn: {{ $rev->booking?->booking_code }}</p>
                            </div>
                            <div class="text-amber-400 font-bold text-sm">
                                {{ str_repeat('★', $rev->overall_rating) }}{{ str_repeat('☆', 5 - $rev->overall_rating) }}
                            </div>
                        </div>

                        <p class="text-xs text-stone-700 leading-relaxed">{{ $rev->comment }}</p>
                        <div class="text-[11px] text-stone-400">Đăng ngày {{ $rev->created_at->format('d/m/Y H:i') }}</div>

                        @if($rev->business_reply)
                            <div class="p-3 bg-pink-50/70 rounded-xl border-l-2 border-pink-500 text-xs space-y-1">
                                <span class="font-bold text-pink-900 text-[11px]">Phản hồi từ cơ sở ({{ $rev->replied_at?->format('d/m/Y') }}):</span>
                                <p class="text-stone-700">{{ $rev->business_reply }}</p>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="pt-4">
                {{ $reviews->links() }}
            </div>
        @endif
    </div>
</x-customer-layout>
