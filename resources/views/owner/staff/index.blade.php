<x-owner-layout>
    <x-slot name="title">Quản lý Nhân viên & Kỹ thuật viên</x-slot>

    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-stone-200/80 shadow-xs space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-stone-900">Đội ngũ Nhân viên & Kỹ thuật viên</h1>
                <p class="text-xs text-stone-500 mt-1">Phân công kỹ thuật viên theo chi nhánh và dịch vụ chuyên môn</p>
            </div>
            <a href="{{ route('owner.staff.create') }}" class="inline-flex items-center gap-2 px-4 py-2.5 bg-pink-600 hover:bg-pink-700 text-white font-bold text-xs rounded-xl shadow-xs transition self-start sm:self-center">
                <span>+</span> Thêm Nhân viên mới
            </a>
        </div>

        @if($staffMembers->isEmpty())
            <div class="text-center py-16 text-stone-400 text-xs">
                Chưa có nhân viên nào. Hãy thêm nhân viên đầu tiên!
            </div>
        @else
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($staffMembers as $member)
                    <div class="p-5 rounded-2xl border border-stone-200 hover:border-pink-300 bg-stone-50/50 flex flex-col justify-between transition space-y-4">
                        <div class="space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold {{ $member->status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-stone-200 text-stone-600' }}">
                                    {{ $member->status === 'active' ? 'Sẵn sàng phục vụ' : 'Đang nghỉ' }}
                                </span>
                                <span class="text-xs text-stone-400">📍 {{ $member->branch->name }}</span>
                            </div>

                            <div class="flex items-center gap-3.5">
                                <img src="{{ $member->avatar_url }}" class="w-14 h-14 rounded-2xl object-cover border border-stone-200 bg-white">
                                <div>
                                    <h3 class="font-bold text-base text-stone-900">{{ $member->name }}</h3>
                                    <p class="text-xs text-pink-600 font-semibold">{{ $member->position ?? 'Kỹ thuật viên' }}</p>
                                    @if($member->phone)
                                        <p class="text-[11px] text-stone-400">📞 {{ $member->phone }}</p>
                                    @endif
                                </div>
                            </div>

                            @if($member->bio)
                                <p class="text-xs text-stone-600 line-clamp-2 leading-relaxed">{{ $member->bio }}</p>
                            @endif

                            <!-- Assigned Services Chips -->
                            <div class="pt-2 border-t border-stone-200">
                                <span class="text-[11px] font-bold text-stone-500 block mb-1.5">
                                    Dịch vụ phụ trách ({{ $member->services->count() }}):
                                </span>
                                <div class="flex flex-wrap gap-1">
                                    @forelse($member->services->take(3) as $s)
                                        <span class="px-2 py-0.5 bg-white border border-stone-200 text-stone-700 text-[10px] font-medium rounded-md">
                                            {{ $s->name }}
                                        </span>
                                    @empty
                                        <span class="text-[11px] text-stone-400 italic">Chưa gán dịch vụ</span>
                                    @endforelse
                                    @if($member->services->count() > 3)
                                        <span class="text-[10px] text-stone-400 font-semibold">+{{ $member->services->count() - 3 }}</span>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <!-- Card Action Buttons -->
                        <div class="pt-3 border-t border-stone-200 flex items-center justify-between">
                            <form method="POST" action="{{ route('owner.staff.toggle-status', $member->id) }}">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="text-xs font-semibold text-stone-500 hover:text-stone-800">
                                    {{ $member->status === 'active' ? 'Tạm dừng nhận khách' : 'Bật nhận khách' }}
                                </button>
                            </form>

                            <a href="{{ route('owner.staff.edit', $member->id) }}" class="px-3 py-1.5 bg-white hover:bg-stone-100 text-stone-700 text-xs font-bold rounded-lg border border-stone-200 transition">
                                Sửa thông tin
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="pt-4">
                {{ $staffMembers->links() }}
            </div>
        @endif
    </div>
</x-owner-layout>
