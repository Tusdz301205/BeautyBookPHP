<x-owner-layout>
    <x-slot name="title">Quản lý Dịch vụ</x-slot>

    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-stone-200/80 shadow-xs space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold text-stone-900">Bảng giá & Dịch vụ</h1>
                <p class="text-xs text-stone-500 mt-1">Quản lý các dịch vụ làm đẹp cung cấp tại các chi nhánh</p>
            </div>
            <a href="{{ route('owner.services.create') }}" class="inline-flex items-center gap-2 px-4 py-2.5 bg-pink-600 hover:bg-pink-700 text-white font-bold text-xs rounded-xl shadow-xs transition self-start sm:self-center">
                <span>+</span> Thêm Dịch vụ mới
            </a>
        </div>

        <!-- Filter & Search Bar -->
        <div class="p-4 bg-stone-50/80 rounded-2xl border border-stone-200">
            <form method="GET" action="{{ route('owner.services.index') }}" class="grid grid-cols-1 sm:grid-cols-12 gap-3 items-center">
                <div class="sm:col-span-4">
                    <input type="text" name="q" value="{{ request('q') }}" placeholder="Tìm theo tên dịch vụ..." class="w-full px-3 py-2 text-xs bg-white rounded-xl border border-stone-200">
                </div>
                <div class="sm:col-span-3">
                    <select name="branch_id" class="w-full px-3 py-2 text-xs bg-white rounded-xl border border-stone-200">
                        <option value="">Tất cả chi nhánh</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}" {{ request('branch_id') == $b->id ? 'selected' : '' }}>{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-3">
                    <select name="category_id" class="w-full px-3 py-2 text-xs bg-white rounded-xl border border-stone-200">
                        <option value="">Tất cả danh mục</option>
                        @foreach($categories as $c)
                            <option value="{{ $c->id }}" {{ request('category_id') == $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2 flex gap-2">
                    <button type="submit" class="w-full py-2 px-3 bg-stone-900 text-white text-xs font-bold rounded-xl hover:bg-black transition">
                        Lọc
                    </button>
                    @if(request()->hasAny(['q', 'branch_id', 'category_id']))
                        <a href="{{ route('owner.services.index') }}" class="py-2 px-2 bg-stone-200 text-stone-700 text-xs font-bold rounded-xl hover:bg-stone-300 transition text-center">
                            ✕
                        </a>
                    @endif
                </div>
            </form>
        </div>

        <!-- Services Table -->
        @if($services->isEmpty())
            <div class="text-center py-16 text-stone-400 text-xs">
                Chưa có dịch vụ nào phù hợp với điều kiện tìm kiếm.
            </div>
        @else
            <div class="overflow-x-auto border border-stone-200 rounded-2xl">
                <table class="w-full text-left border-collapse text-xs">
                    <thead>
                        <tr class="bg-stone-100 text-stone-700 font-bold uppercase tracking-wider border-b border-stone-200">
                            <th class="p-3.5">Dịch vụ</th>
                            <th class="p-3.5">Chi nhánh</th>
                            <th class="p-3.5">Danh mục</th>
                            <th class="p-3.5">Thời lượng</th>
                            <th class="p-3.5">Giá gốc / Ưu đãi</th>
                            <th class="p-3.5">Trạng thái</th>
                            <th class="p-3.5 text-right">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($services as $svc)
                            <tr class="hover:bg-stone-50/50 transition">
                                <td class="p-3.5">
                                    <div class="flex items-center gap-3">
                                        <img src="{{ $svc->cover_image_url }}" class="w-10 h-10 rounded-xl object-cover border border-stone-200">
                                        <div>
                                            <a href="{{ route('services.show', $svc->id) }}" target="_blank" class="font-bold text-stone-900 hover:text-pink-600 block">
                                                {{ $svc->name }} ↗
                                            </a>
                                            <span class="text-[11px] text-stone-400 line-clamp-1">{{ $svc->description }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td class="p-3.5 text-stone-700 font-medium">{{ $svc->branch->name }}</td>
                                <td class="p-3.5">
                                    <span class="px-2 py-0.5 bg-pink-50 text-pink-700 font-semibold rounded-md text-[10px]">
                                        {{ $svc->category?->name ?? 'Chưa phân loại' }}
                                    </span>
                                </td>
                                <td class="p-3.5 text-stone-600 font-mono">{{ $svc->duration_minutes }}p</td>
                                <td class="p-3.5">
                                    @if($svc->has_discount)
                                        <span class="text-stone-400 line-through text-[11px] block">{{ number_format($svc->price, 0, ',', '.') }} đ</span>
                                        <span class="font-extrabold text-pink-600 font-mono">{{ number_format($svc->discount_price, 0, ',', '.') }} đ</span>
                                    @else
                                        <span class="font-bold text-stone-900 font-mono">{{ number_format($svc->price, 0, ',', '.') }} đ</span>
                                    @endif
                                </td>
                                <td class="p-3.5">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $svc->status === 'active' ? 'bg-emerald-100 text-emerald-800' : 'bg-stone-200 text-stone-600' }}">
                                        {{ $svc->status === 'active' ? 'Hoạt động' : 'Tạm dừng' }}
                                    </span>
                                </td>
                                <td class="p-3.5 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        <a href="{{ route('owner.services.edit', $svc->id) }}" class="px-2.5 py-1 bg-white hover:bg-stone-100 text-stone-700 text-xs font-bold rounded-lg border border-stone-200 transition">
                                            Sửa
                                        </a>
                                        <form method="POST" action="{{ route('owner.services.destroy', $svc->id) }}" onsubmit="return confirm('Ngưng hoạt động dịch vụ này?');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="px-2.5 py-1 bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs font-bold rounded-lg border border-rose-200 transition">
                                                Tắt
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pt-4">
                {{ $services->links() }}
            </div>
        @endif
    </div>
</x-owner-layout>
