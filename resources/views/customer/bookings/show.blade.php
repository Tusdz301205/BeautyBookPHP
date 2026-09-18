<x-customer-layout>
    <x-slot name="title">Chi tiết Lịch hẹn #{{ $booking->booking_code }}</x-slot>

    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-stone-200/80 shadow-xs max-w-3xl mx-auto space-y-8">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-stone-200 pb-6">
            <div class="space-y-1">
                <div class="flex items-center gap-3">
                    <span class="font-mono text-xl font-extrabold text-stone-900">{{ $booking->booking_code }}</span>
                    <span class="badge-container">{!! $booking->status_badge !!}</span>
                </div>
                <p class="text-xs text-stone-500">Tạo lúc {{ $booking->created_at->format('d/m/Y H:i') }}</p>
            </div>

            <!-- Action buttons -->
            <div class="flex items-center gap-3">
                @if(in_array($booking->status, ['pending', 'confirmed']))
                    <form method="POST" action="{{ route('customer.bookings.cancel', $booking->id) }}" onsubmit="return confirm('Bạn có chắc chắn muốn hủy lịch hẹn này?');">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="px-4 py-2 bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs font-bold rounded-xl border border-rose-200 transition">
                            Hủy lịch hẹn
                        </button>
                    </form>
                @endif

                @if($booking->status === 'completed' && !$booking->review)
                    <a href="{{ route('customer.reviews.create', $booking->id) }}" class="px-4 py-2 bg-amber-500 hover:bg-amber-600 text-white text-xs font-bold rounded-xl shadow-xs transition flex items-center gap-1.5">
                        <span>⭐</span> Viết đánh giá
                    </a>
                @endif
            </div>
        </div>

        <!-- Appointment Info & Location -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
            <div class="p-5 bg-stone-50 rounded-2xl border border-stone-100 space-y-2">
                <h3 class="text-xs font-bold uppercase tracking-wider text-stone-400">Thời gian hẹn</h3>
                <div class="text-base font-extrabold text-stone-800">
                    🗓 {{ $booking->appointment_date->format('d/m/Y H:i') }}
                </div>
                <div class="text-xs text-stone-500">
                    Thời lượng dự kiến: {{ $booking->total_duration_minutes }} phút
                </div>
            </div>

            <div class="p-5 bg-stone-50 rounded-2xl border border-stone-100 space-y-2">
                <h3 class="text-xs font-bold uppercase tracking-wider text-stone-400">Cơ sở phục vụ</h3>
                <div class="font-bold text-sm text-stone-800">{{ $booking->branch?->business?->name }}</div>
                <div class="text-xs text-stone-600">{{ $booking->branch?->name }}</div>
                <div class="text-xs text-stone-400">📍 {{ $booking->branch?->address }}</div>
                @if($booking->branch?->phone)
                    <div class="text-xs text-pink-600 font-semibold">📞 {{ $booking->branch->phone }}</div>
                @endif
            </div>
        </div>

        <!-- Services Breakdown -->
        <div>
            <h3 class="text-sm font-bold text-stone-900 mb-3">Dịch vụ đã chọn</h3>
            <div class="border border-stone-200 rounded-2xl overflow-hidden divide-y divide-stone-100">
                @foreach($booking->bookingServices as $bs)
                    <div class="p-4 flex items-center justify-between">
                        <div>
                            <div class="font-bold text-sm text-stone-900">{{ $bs->service_name }}</div>
                            <div class="text-xs text-stone-500 mt-0.5 flex items-center gap-3">
                                <span>⏱ {{ $bs->duration_minutes }} phút</span>
                                @if($bs->staff)
                                    <span>👤 Kỹ thuật viên: <strong class="text-stone-700">{{ $bs->staff->name }}</strong></span>
                                @endif
                            </div>
                        </div>
                        <span class="font-bold text-sm text-stone-900">{{ number_format($bs->price, 0, ',', '.') }} đ</span>
                    </div>
                @endforeach
                <div class="p-4 bg-stone-50/70 flex items-center justify-between">
                    <span class="font-bold text-sm text-stone-800">Tổng thanh toán tại cơ sở:</span>
                    <span class="font-extrabold text-base text-pink-600">{{ number_format($booking->total_price, 0, ',', '.') }} đ</span>
                </div>
            </div>
        </div>

        <!-- Customer Notes / Reason -->
        @if($booking->notes || $booking->cancel_reason)
            <div class="p-4 bg-amber-50/50 rounded-2xl border border-amber-200/70 text-xs space-y-1">
                @if($booking->notes)
                    <div><strong>Ghi chú của bạn:</strong> {{ $booking->notes }}</div>
                @endif
                @if($booking->cancel_reason)
                    <div class="text-rose-700"><strong>Lý do hủy:</strong> {{ $booking->cancel_reason }} (bởi {{ $booking->cancelled_by }})</div>
                @endif
            </div>
        @endif

        <!-- Status History Timeline (Audit Trail) -->
        <div>
            <h3 class="text-sm font-bold text-stone-900 mb-4 flex items-center gap-2">
                <span>📋</span>
                <span>Lịch sử trạng thái lịch hẹn</span>
            </h3>

            <div class="relative pl-6 border-l-2 border-stone-200 space-y-6">
                @foreach($booking->statusHistories as $history)
                    <div class="relative">
                        <div class="absolute -left-[31px] top-0 w-4 h-4 rounded-full bg-pink-500 border-2 border-white shadow-xs"></div>
                        <div class="space-y-0.5">
                            <div class="flex items-center gap-2">
                                <span class="font-bold text-xs text-stone-800 uppercase tracking-wide">{{ $history->to_status }}</span>
                                <span class="text-[11px] text-stone-400">{{ $history->created_at->format('d/m/Y H:i:s') }}</span>
                            </div>
                            @if($history->note)
                                <p class="text-xs text-stone-600">{{ $history->note }}</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="pt-4 border-t border-stone-100 flex items-center justify-between">
            <a href="{{ route('customer.bookings.index') }}" class="text-xs font-semibold text-stone-500 hover:text-stone-800">
                ← Quay lại danh sách
            </a>
        </div>
    </div>
</x-customer-layout>
