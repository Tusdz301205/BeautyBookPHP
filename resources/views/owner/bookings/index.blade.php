<x-owner-layout>
    <x-slot name="title">Quản lý Lịch hẹn</x-slot>

    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-stone-200/80 shadow-xs space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-stone-900">Quản lý Lịch hẹn Khách hàng</h1>
            <p class="text-xs text-stone-500 mt-1">Tiếp nhận, xác nhận và cập nhật tiến độ phục vụ khách hàng</p>
        </div>

        <!-- Filter Bar -->
        <div class="p-4 bg-stone-50/80 rounded-2xl border border-stone-200">
            <form method="GET" action="{{ route('owner.bookings.index') }}" class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-center">
                <div class="sm:col-span-3">
                    <input type="text" name="q" value="{{ request('q') }}" placeholder="Mã lịch hẹn, tên khách, SĐT..." class="w-full px-3 py-2 text-xs bg-white rounded-xl border border-stone-200">
                </div>
                <div class="sm:col-span-3">
                    <select name="branch_id" class="w-full px-3 py-2 text-xs bg-white rounded-xl border border-stone-200">
                        <option value="">Tất cả chi nhánh</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" {{ request('branch_id') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <select name="status" class="w-full px-3 py-2 text-xs bg-white rounded-xl border border-stone-200">
                        <option value="">Tất cả trạng thái</option>
                        <option value="pending" {{ request('status') == 'pending' ? 'selected' : '' }}>Chờ xác nhận</option>
                        <option value="confirmed" {{ request('status') == 'confirmed' ? 'selected' : '' }}>Đã xác nhận</option>
                        <option value="in_progress" {{ request('status') == 'in_progress' ? 'selected' : '' }}>Đang phục vụ</option>
                        <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>Đã hoàn thành</option>
                        <option value="cancelled" {{ request('status') == 'cancelled' ? 'selected' : '' }}>Đã hủy</option>
                        <option value="no_show" {{ request('status') == 'no_show' ? 'selected' : '' }}>Khách vắng mặt</option>
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <input type="date" name="date" value="{{ request('date') }}" class="w-full px-3 py-2 text-xs bg-white rounded-xl border border-stone-200">
                </div>
                <div class="sm:col-span-2 flex gap-2">
                    <button type="submit" class="w-full py-2 px-3 bg-stone-900 text-white text-xs font-bold rounded-xl hover:bg-black transition">
                        Lọc
                    </button>
                    @if(request()->hasAny(['q', 'branch_id', 'status', 'date']))
                        <a href="{{ route('owner.bookings.index') }}" class="py-2 px-2 bg-stone-200 text-stone-700 text-xs font-bold rounded-xl hover:bg-stone-300 transition text-center">
                            ✕
                        </a>
                    @endif
                </div>
            </form>
        </div>

        <!-- Bookings Table -->
        @if($bookings->isEmpty())
            <div class="text-center py-16 text-stone-400 text-xs">
                Không tìm thấy lịch hẹn nào theo điều kiện lọc.
            </div>
        @else
            <div class="overflow-x-auto border border-stone-200 rounded-2xl">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-stone-100 text-stone-700 font-bold uppercase tracking-wider border-b border-stone-200">
                            <th class="p-3.5">Mã / Khách hàng</th>
                            <th class="p-3.5">Chi nhánh</th>
                            <th class="p-3.5">Dịch vụ & Nhân viên</th>
                            <th class="p-3.5">Thời gian hẹn</th>
                            <th class="p-3.5">Tổng tiền</th>
                            <th class="p-3.5">Trạng thái</th>
                            <th class="p-3.5 text-right">Chuyển trạng thái</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($bookings as $booking)
                            <tr class="hover:bg-stone-50/50 transition" id="owner-bk-row-{{ $booking->id }}">
                                <td class="p-3.5">
                                    <div class="font-mono font-bold text-stone-900">
                                        <a href="{{ route('owner.bookings.show', $booking->id) }}" class="hover:text-pink-600 underline">
                                            {{ $booking->booking_code }}
                                        </a>
                                    </div>
                                    <div class="font-semibold text-stone-800 mt-0.5">{{ $booking->customer->name }}</div>
                                    <div class="text-[11px] text-stone-400">📞 {{ $booking->customer->phone ?? 'Chưa có SĐT' }}</div>
                                </td>

                                <td class="p-3.5 text-stone-700 font-medium">
                                    {{ $booking->branch->name }}
                                </td>

                                <td class="p-3.5">
                                    <div class="font-semibold text-stone-900">{{ $booking->bookingServices->first()?->service_name }}</div>
                                    <div class="text-[11px] text-stone-400 mt-0.5">
                                        👤 NV: {{ $booking->bookingServices->first()?->staff?->name ?? 'Chưa chỉ định' }}
                                    </div>
                                </td>

                                <td class="p-3.5">
                                    <div class="font-bold text-stone-800">{{ $booking->appointment_date->format('d/m/Y H:i') }}</div>
                                    <div class="text-[11px] text-stone-400">Thời lượng: {{ $booking->total_duration_minutes }}p</div>
                                </td>

                                <td class="p-3.5 font-bold font-mono text-stone-900">
                                    {{ number_format($booking->total_price, 0, ',', '.') }} đ
                                </td>

                                <td class="p-3.5 badge-cell">
                                    {!! $booking->status_badge !!}
                                </td>

                                <td class="p-3.5 text-right">
                                    <!-- Instant AJAX Status Changer Dropdown -->
                                    <select onchange="updateBookingStatus({{ $booking->id }}, this.value)" class="text-xs py-1 px-2.5 bg-white border border-stone-200 rounded-lg text-stone-700 font-medium focus:ring-pink-500">
                                        <option value="" disabled selected>Đổi trạng thái...</option>
                                        <option value="confirmed" {{ $booking->status === 'confirmed' ? 'disabled' : '' }}>Xác nhận lịch</option>
                                        <option value="in_progress" {{ $booking->status === 'in_progress' ? 'disabled' : '' }}>Đang phục vụ</option>
                                        <option value="completed" {{ $booking->status === 'completed' ? 'disabled' : '' }}>Hoàn thành</option>
                                        <option value="cancelled" {{ $booking->status === 'cancelled' ? 'disabled' : '' }}>Hủy lịch</option>
                                        <option value="no_show" {{ $booking->status === 'no_show' ? 'disabled' : '' }}>Khách không đến</option>
                                    </select>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pt-4">
                {{ $bookings->links() }}
            </div>
        @endif
    </div>

    <!-- AJAX Booking Status Update Script -->
    <script>
        function updateBookingStatus(bookingId, newStatus) {
            let note = '';
            if (newStatus === 'cancelled') {
                note = prompt("Nhập lý do hủy lịch:", "Salon hủy do bận lịch");
                if (note === null) return;
            }

            fetch(`/owner/bookings/${bookingId}/status`, {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    status: newStatus,
                    note: note
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data && data.success) {
                    const row = document.getElementById(`owner-bk-row-${bookingId}`);
                    if (row) {
                        const badgeCell = row.querySelector('.badge-cell');
                        if (badgeCell) badgeCell.innerHTML = data.new_status_badge;
                    }
                    alert(data.message);
                } else {
                    alert(data.message || 'Có lỗi xảy ra khi cập nhật trạng thái.');
                }
            })
            .catch(err => {
                console.error(err);
                alert('Lỗi kết nối máy chủ.');
            });
        }
    </script>
</x-owner-layout>
