<x-owner-layout>
    <x-slot name="title">Chi tiết Lịch hẹn #{{ $booking->booking_code }}</x-slot>

    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-stone-200/80 shadow-xs max-w-3xl mx-auto space-y-8">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-stone-200 pb-6">
            <div class="space-y-1">
                <div class="flex items-center gap-3">
                    <span class="font-mono text-xl font-extrabold text-stone-900">{{ $booking->booking_code }}</span>
                    {!! $booking->status_badge !!}
                </div>
                <p class="text-xs text-stone-500">Khách đặt lúc {{ $booking->created_at->format('d/m/Y H:i') }}</p>
            </div>

            <!-- Status Transition Form -->
            <form method="POST" action="{{ route('owner.bookings.status', $booking->id) }}" class="flex items-center gap-2">
                @csrf
                @method('PATCH')
                <select name="status" class="text-xs py-2 px-3 bg-stone-50 border border-stone-200 rounded-xl font-bold text-stone-800 focus:ring-pink-500">
                    <option value="confirmed" {{ $booking->status === 'confirmed' ? 'selected' : '' }}>Xác nhận lịch</option>
                    <option value="in_progress" {{ $booking->status === 'in_progress' ? 'selected' : '' }}>Đang phục vụ</option>
                    <option value="completed" {{ $booking->status === 'completed' ? 'selected' : '' }}>Đã hoàn thành</option>
                    <option value="cancelled" {{ $booking->status === 'cancelled' ? 'selected' : '' }}>Hủy lịch hẹn</option>
                    <option value="no_show" {{ $booking->status === 'no_show' ? 'selected' : '' }}>Khách vắng mặt</option>
                </select>
                <button type="submit" class="px-4 py-2 bg-pink-600 hover:bg-pink-700 text-white font-bold text-xs rounded-xl shadow-xs transition">
                    Lưu trạng thái
                </button>
            </form>
        </div>

        <!-- Customer & Branch Contact -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
            <div class="p-5 bg-stone-50 rounded-2xl border border-stone-100 space-y-2">
                <h3 class="text-xs font-bold uppercase tracking-wider text-stone-400">Thông tin Khách hàng</h3>
                <div class="font-bold text-base text-stone-900">{{ $booking->customer->name }}</div>
                <div class="text-xs text-stone-600">📞 SĐT: <strong>{{ $booking->customer->phone ?? 'Chưa cung cấp' }}</strong></div>
                <div class="text-xs text-stone-500">✉️ Email: {{ $booking->customer->email }}</div>
            </div>

            <div class="p-5 bg-stone-50 rounded-2xl border border-stone-100 space-y-2">
                <h3 class="text-xs font-bold uppercase tracking-wider text-stone-400">Chi nhánh & Thời gian</h3>
                <div class="font-bold text-sm text-stone-900">{{ $booking->branch->name }}</div>
                <div class="text-xs text-stone-600">🗓 Giờ hẹn: <strong class="text-pink-700">{{ $booking->appointment_date->format('d/m/Y H:i') }}</strong></div>
                <div class="text-xs text-stone-400">Thời lượng dự kiến: {{ $booking->total_duration_minutes }} phút</div>
            </div>
        </div>

        <!-- Service Snapshot -->
        <div>
            <h3 class="text-sm font-bold text-stone-900 mb-3">Chi tiết Dịch vụ</h3>
            <div class="border border-stone-200 rounded-2xl overflow-hidden divide-y divide-stone-100">
                @foreach($booking->bookingServices as $bs)
                    <div class="p-4 flex items-center justify-between">
                        <div>
                            <div class="font-bold text-sm text-stone-900">{{ $bs->service_name }}</div>
                            <div class="text-xs text-stone-500 mt-0.5">
                                Kỹ thuật viên: <strong class="text-stone-700">{{ $bs->staff?->name ?? 'Tự động sắp xếp' }}</strong>
                            </div>
                        </div>
                        <span class="font-bold text-sm text-stone-900 font-mono">{{ number_format($bs->price, 0, ',', '.') }} đ</span>
                    </div>
                @endforeach
                <div class="p-4 bg-stone-50 flex items-center justify-between">
                    <span class="font-bold text-sm text-stone-800">Tổng thu tại quầy:</span>
                    <span class="font-extrabold text-base text-pink-600 font-mono">{{ number_format($booking->total_price, 0, ',', '.') }} đ</span>
                </div>
            </div>
        </div>

        @if($booking->notes)
            <div class="p-4 bg-stone-50 rounded-2xl border border-stone-200 text-xs">
                <strong>Ghi chú từ khách:</strong> {{ $booking->notes }}
            </div>
        @endif

        <!-- Status History Timeline -->
        <div>
            <h3 class="text-sm font-bold text-stone-900 mb-4">Nhật ký trạng thái (Audit Trail)</h3>
            <div class="relative pl-6 border-l-2 border-stone-200 space-y-4">
                @foreach($booking->statusHistories as $h)
                    <div class="relative text-xs">
                        <div class="absolute -left-[31px] top-0 w-4 h-4 rounded-full bg-pink-500 border-2 border-white"></div>
                        <span class="font-bold text-stone-800 uppercase">{{ $h->to_status }}</span>
                        <span class="text-stone-400 ml-2">{{ $h->created_at->format('d/m/Y H:i:s') }}</span>
                        @if($h->note)
                            <p class="text-stone-600 mt-0.5">{{ $h->note }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        <div class="pt-4 border-t border-stone-100 flex items-center justify-between">
            <a href="{{ route('owner.bookings.index') }}" class="text-xs font-semibold text-stone-500 hover:text-stone-800">
                ← Quay lại danh sách
            </a>
        </div>
    </div>
</x-owner-layout>
