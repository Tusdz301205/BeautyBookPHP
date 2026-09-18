<x-owner-layout>
    <x-slot name="title">Quản lý Đánh giá từ Khách hàng</x-slot>

    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-stone-200/80 shadow-xs space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-stone-900">Đánh giá từ Khách hàng</h1>
                <p class="text-xs text-stone-500 mt-1">Lắng nghe phản hồi từ khách hàng và gửi câu trả lời từ đại diện salon</p>
            </div>
            
            <div class="flex items-center gap-2">
                <a href="{{ route('owner.reviews.index') }}" class="px-3 py-1.5 rounded-xl text-xs font-semibold {{ !request('filter') ? 'bg-pink-600 text-white' : 'bg-stone-100 text-stone-600 hover:bg-stone-200' }}">
                    Tất cả
                </a>
                <a href="{{ route('owner.reviews.index', ['filter' => 'unreplied']) }}" class="px-3 py-1.5 rounded-xl text-xs font-semibold {{ request('filter') === 'unreplied' ? 'bg-pink-600 text-white' : 'bg-stone-100 text-stone-600 hover:bg-stone-200' }}">
                    Chưa phản hồi
                </a>
            </div>
        </div>

        @if($reviews->isEmpty())
            <div class="text-center py-16 text-stone-400 text-xs">
                Chưa có đánh giá nào phù hợp với bộ lọc hiện tại.
            </div>
        @else
            <div class="space-y-4">
                @foreach($reviews as $rev)
                    <div class="p-5 rounded-2xl border border-stone-200 bg-stone-50/50 space-y-3" id="rev-card-{{ $rev->id }}">
                        <div class="flex items-center justify-between">
                            <div>
                                <span class="font-bold text-sm text-stone-900">{{ $rev->customer->name }}</span>
                                <span class="text-xs text-stone-400 ml-2">📍 {{ $rev->branch->name }}</span>
                                <span class="text-xs text-stone-400 ml-2">Mã lịch: {{ $rev->booking->booking_code }}</span>
                            </div>
                            <div class="text-amber-400 text-sm font-bold">
                                {{ str_repeat('★', $rev->overall_rating) }}{{ str_repeat('☆', 5 - $rev->overall_rating) }}
                            </div>
                        </div>

                        <p class="text-xs text-stone-700 leading-relaxed">{{ $rev->comment }}</p>
                        <div class="text-[11px] text-stone-400">Gửi lúc {{ $rev->created_at->format('d/m/Y H:i') }}</div>

                        <!-- Current Reply (if exists) -->
                        <div class="reply-box {{ $rev->business_reply ? '' : 'hidden' }} p-3.5 bg-pink-50/80 rounded-xl border border-pink-200 text-xs space-y-1">
                            <span class="font-bold text-pink-900 block text-[11px]">
                                Phản hồi từ Salon <span class="reply-time font-normal text-stone-500">({{ $rev->replied_at?->format('d/m/Y H:i') }})</span>:
                            </span>
                            <p class="text-stone-700 reply-text">{{ $rev->business_reply }}</p>
                        </div>

                        <!-- Reply Form (Accordion / Toggle) -->
                        <div class="pt-2 border-t border-stone-200" x-data="{ openForm: false }">
                            <button type="button" @click="openForm = !openForm" class="text-xs font-bold text-pink-600 hover:text-pink-700 flex items-center gap-1">
                                <span x-text="openForm ? '✕ Đóng form' : '💬 Phản hồi đánh giá'"></span>
                            </button>

                            <form x-show="openForm" x-cloak onsubmit="submitReply(event, {{ $rev->id }})" class="mt-3 space-y-2">
                                <textarea name="business_reply" rows="2" required placeholder="Nhập lời cảm ơn hoặc phản hồi của salon..." class="w-full px-3 py-2 text-xs bg-white border border-stone-200 rounded-xl text-stone-800 focus:ring-pink-500">{{ $rev->business_reply }}</textarea>
                                <button type="submit" class="px-4 py-2 bg-pink-600 hover:bg-pink-700 text-white font-bold text-xs rounded-xl shadow-xs transition">
                                    Gửi phản hồi cho khách
                                </button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="pt-4">
                {{ $reviews->links() }}
            </div>
        @endif
    </div>

    <!-- AJAX Reply Script -->
    <script>
        function submitReply(event, reviewId) {
            event.preventDefault();
            const form = event.target;
            const replyText = form.querySelector('textarea[name="business_reply"]').value;

            fetch(`/owner/reviews/${reviewId}/reply`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ business_reply: replyText })
            })
            .then(res => res.json())
            .then(data => {
                if (data && data.success) {
                    const card = document.getElementById(`rev-card-${reviewId}`);
                    if (card) {
                        const replyBox = card.querySelector('.reply-box');
                        if (replyBox) {
                            replyBox.classList.remove('hidden');
                            const textEl = replyBox.querySelector('.reply-text');
                            if (textEl) textEl.textContent = data.business_reply;
                            const timeEl = replyBox.querySelector('.reply-time');
                            if (timeEl) timeEl.textContent = `(${data.replied_at})`;
                        }
                    }
                    alert(data.message);
                } else {
                    alert(data.message || 'Có lỗi xảy ra.');
                }
            })
            .catch(err => {
                console.error(err);
                alert('Lỗi kết nối máy chủ.');
            });
        }
    </script>
</x-owner-layout>
