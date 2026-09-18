<x-customer-layout>
    <x-slot name="title">Lịch hẹn của tôi</x-slot>

    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-stone-200/80 shadow-xs space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-stone-900">Lịch hẹn của tôi</h1>
                <p class="text-xs text-stone-500 mt-1">Theo dõi trạng thái và chi tiết các lịch làm đẹp đã đặt</p>
            </div>
            <a href="{{ route('customer.bookings.create') }}" class="inline-flex items-center gap-2 px-4 py-2.5 bg-pink-600 hover:bg-pink-700 text-white font-bold text-xs rounded-xl shadow-xs transition self-start sm:self-center">
                <span>✨</span> Đặt lịch mới
            </a>
        </div>

        <!-- Filter Tabs -->
        <div class="flex flex-wrap items-center gap-2 border-b border-stone-200 pb-3">
            <a href="{{ route('customer.bookings.index') }}" class="px-3.5 py-1.5 rounded-xl text-xs font-semibold {{ !request('status') ? 'bg-pink-600 text-white' : 'text-stone-600 hover:bg-stone-100' }}">
                Tất cả
            </a>
            <a href="{{ route('customer.bookings.index', ['status' => 'pending']) }}" class="px-3.5 py-1.5 rounded-xl text-xs font-semibold {{ request('status') == 'pending' ? 'bg-pink-600 text-white' : 'text-stone-600 hover:bg-stone-100' }}">
                Chờ xác nhận
            </a>
            <a href="{{ route('customer.bookings.index', ['status' => 'confirmed']) }}" class="px-3.5 py-1.5 rounded-xl text-xs font-semibold {{ request('status') == 'confirmed' ? 'bg-pink-600 text-white' : 'text-stone-600 hover:bg-stone-100' }}">
                Đã xác nhận
            </a>
            <a href="{{ route('customer.bookings.index', ['status' => 'completed']) }}" class="px-3.5 py-1.5 rounded-xl text-xs font-semibold {{ request('status') == 'completed' ? 'bg-pink-600 text-white' : 'text-stone-600 hover:bg-stone-100' }}">
                Đã hoàn thành
            </a>
            <a href="{{ route('customer.bookings.index', ['status' => 'cancelled']) }}" class="px-3.5 py-1.5 rounded-xl text-xs font-semibold {{ request('status') == 'cancelled' ? 'bg-pink-600 text-white' : 'text-stone-600 hover:bg-stone-100' }}">
                Đã hủy
            </a>
        </div>

        <!-- Bookings List -->
        @if($bookings->isEmpty())
            <div class="text-center py-16 text-stone-400 text-xs">
                <span class="text-4xl block mb-2">📅</span>
                Không có lịch hẹn nào ở trạng thái này.
            </div>
        @else
            <div class="space-y-4">
                @foreach($bookings as $booking)
                    <div class="p-5 rounded-2xl border border-stone-200 hover:border-pink-200 transition bg-stone-50/50 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4" id="booking-row-{{ $booking->id }}">
                        <div class="space-y-1.5 flex-1">
                            <div class="flex items-center gap-2.5">
                                <span class="font-mono text-xs font-extrabold text-stone-700">{{ $booking->booking_code }}</span>
                                <span class="badge-container">{!! $booking->status_badge !!}</span>
                                <span class="text-xs text-stone-400">• {{ $booking->branch?->business?->name }}</span>
                            </div>

                            <h3 class="font-bold text-base text-stone-900">
                                {{ $booking->bookingServices->first()?->service_name ?? 'Dịch vụ làm đẹp' }}
                            </h3>

                            <div class="text-xs text-stone-500 flex flex-wrap gap-x-4 gap-y-1 pt-1">
                                <span>📍 {{ $booking->branch?->name }}</span>
                                <span>🗓 {{ $booking->appointment_date->format('d/m/Y H:i') }} ({{ $booking->total_duration_minutes }}p)</span>
                                <span>💰 <strong class="text-stone-800">{{ number_format($booking->total_price, 0, ',', '.') }} đ</strong></span>
                            </div>
                        </div>

                        <!-- Actions -->
                        <div class="flex items-center gap-2.5 flex-shrink-0 self-end sm:self-center">
                            @if(in_array($booking->status, ['pending', 'confirmed']))
                                <button type="button" 
                                    onclick="cancelBooking({{ $booking->id }})" 
                                    class="cancel-btn px-3 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs font-bold rounded-xl border border-rose-200 transition">
                                    Hủy lịch
                                </button>
                            @endif

                            @if($booking->status === 'completed' && !$booking->review)
                                <a href="{{ route('customer.reviews.create', $booking->id) }}" class="px-3 py-1.5 bg-amber-50 hover:bg-amber-100 text-amber-800 text-xs font-bold rounded-xl border border-amber-200 transition flex items-center gap-1">
                                    <span>⭐</span> Viết đánh giá
                                </a>
                            @endif

                            <a href="{{ route('customer.bookings.show', $booking->id) }}" class="px-3.5 py-1.5 bg-white border border-stone-200 hover:border-stone-300 text-stone-700 text-xs font-bold rounded-xl shadow-2xs transition">
                                Chi tiết
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>

            <!-- Pagination -->
            <div class="pt-4">
                {{ $bookings->links() }}
            </div>
        @endif
    </div>

    <!-- AJAX Cancel Booking Script -->
    <script>
        function cancelBooking(bookingId) {
            const reason = prompt("Lý do bạn muốn hủy lịch hẹn này (không bắt buộc):", "Tôi có việc bận đột xuất");
            if (reason === null) return; // User cancelled prompt

            fetch(`/customer/bookings/${bookingId}/cancel`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ cancel_reason: reason })
            })
            .then(res => res.json())
            .then(data => {
                if (data && data.success) {
                    const row = document.getElementById(`booking-row-${bookingId}`);
                    if (row) {
                        const badgeContainer = row.querySelector('.badge-container');
                        if (badgeContainer) badgeContainer.innerHTML = data.new_status_badge;
                        const cancelBtn = row.querySelector('.cancel-btn');
                        if (cancelBtn) cancelBtn.remove();
                    }
                    alert(data.message);
                } else {
                    alert(data.message || 'Không thể hủy lịch hẹn.');
                }
            })
            .catch(err => {
                console.error(err);
                alert('Có lỗi xảy ra khi hủy lịch.');
            });
        }
    </script>
</x-customer-layout>
