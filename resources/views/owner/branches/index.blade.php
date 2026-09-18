<x-owner-layout>
    <x-slot name="title">Quản lý Chi nhánh</x-slot>

    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-stone-200/80 shadow-xs space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-stone-900">Danh sách Chi nhánh</h1>
                <p class="text-xs text-stone-500 mt-1">Quản lý các cơ sở, địa chỉ, số điện thoại và giờ mở cửa</p>
            </div>
            <a href="{{ route('owner.branches.create') }}" class="inline-flex items-center gap-2 px-4 py-2.5 bg-pink-600 hover:bg-pink-700 text-white font-bold text-xs rounded-xl shadow-xs transition self-start sm:self-center">
                <span>+</span> Thêm Chi nhánh mới
            </a>
        </div>

        @if($branches->isEmpty())
            <div class="text-center py-16 text-stone-400 text-xs">
                Chưa có chi nhánh nào. Hãy thêm chi nhánh đầu tiên của bạn!
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($branches as $branch)
                    <div class="p-5 rounded-2xl border border-stone-200 hover:border-pink-300 bg-stone-50/50 flex flex-col justify-between transition space-y-4">
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <span class="px-2.5 py-1 text-[10px] font-bold rounded-full {{ $branch->status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-stone-200 text-stone-600' }}">
                                    {{ $branch->status === 'active' ? 'Đang hoạt động' : 'Ngưng hoạt động' }}
                                </span>
                                <span class="text-xs text-stone-400">{{ $branch->district?->province?->name }}</span>
                            </div>

                            <h3 class="font-bold text-base text-stone-900">{{ $branch->name }}</h3>
                            <p class="text-xs text-stone-500">📍 {{ $branch->address }}</p>
                            @if($branch->phone)
                                <p class="text-xs text-stone-600 font-medium">📞 {{ $branch->phone }}</p>
                            @endif

                            <div class="pt-2 flex items-center gap-4 text-xs text-stone-500 border-t border-stone-200">
                                <span>💅 <strong>{{ $branch->services_count }}</strong> dịch vụ</span>
                                <span>👥 <strong>{{ $branch->staff_count }}</strong> nhân viên</span>
                                <span>📅 <strong>{{ $branch->bookings_count }}</strong> lịch</span>
                            </div>
                        </div>

                        <div class="pt-3 border-t border-stone-200 flex items-center justify-between">
                            <a href="{{ route('branches.show', $branch->id) }}" target="_blank" class="text-xs text-stone-500 hover:text-pink-600">
                                Xem trước ↗
                            </a>
                            <div class="flex items-center gap-2">
                                <a href="{{ route('owner.branches.edit', $branch->id) }}" class="px-3 py-1.5 bg-white hover:bg-stone-100 text-stone-700 text-xs font-bold rounded-lg border border-stone-200 transition">
                                    Chỉnh sửa & Giờ mở
                                </a>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-owner-layout>
