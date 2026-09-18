<x-owner-layout>
    <x-slot name="title">Danh mục Dịch vụ</x-slot>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start">
        <!-- Add Category Form (Left) -->
        <div class="bg-white rounded-3xl p-6 border border-stone-200/80 shadow-xs space-y-4">
            <h2 class="text-lg font-bold text-stone-900">Thêm Danh mục mới</h2>
            <p class="text-xs text-stone-500">Tạo nhóm dịch vụ để phân loại bảng giá (ví dụ: Chăm sóc da, Cắt tóc...)</p>

            <form method="POST" action="{{ route('owner.categories.store') }}" class="space-y-4">
                @csrf
                <div>
                    <x-input-label for="name" value="Tên danh mục *" />
                    <x-text-input id="name" class="block mt-1 w-full" type="text" name="name" required placeholder="Ví dụ: Chăm sóc da mặt" />
                </div>

                <div>
                    <x-input-label for="description" value="Mô tả ngắn" />
                    <textarea id="description" name="description" rows="2" class="block mt-1 w-full px-3 py-2 bg-stone-50 border border-stone-200 rounded-xl text-sm" placeholder="Mô tả nhóm dịch vụ..."></textarea>
                </div>

                <div>
                    <x-input-label for="sort_order" value="Thứ tự hiển thị" />
                    <x-text-input id="sort_order" class="block mt-1 w-full" type="number" name="sort_order" value="1" min="0" />
                </div>

                <button type="submit" class="w-full py-2.5 bg-pink-600 hover:bg-pink-700 text-white font-bold text-xs rounded-xl shadow-xs transition">
                    + Tạo danh mục
                </button>
            </form>
        </div>

        <!-- Categories List (Right) -->
        <div class="lg:col-span-2 bg-white rounded-3xl p-6 sm:p-8 border border-stone-200/80 shadow-xs space-y-4">
            <div>
                <h1 class="text-2xl font-bold text-stone-900">Danh mục Dịch vụ hiện có</h1>
                <p class="text-xs text-stone-500 mt-1">Đang có {{ $categories->count() }} danh mục dịch vụ</p>
            </div>

            @if($categories->isEmpty())
                <div class="text-center py-12 text-stone-400 text-xs">
                    Chưa có danh mục nào. Hãy tạo danh mục đầu tiên bên cạnh!
                </div>
            @else
                <div class="border border-stone-200 rounded-2xl overflow-hidden divide-y divide-stone-100">
                    @foreach($categories as $cat)
                        <div class="p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3 bg-white hover:bg-stone-50/50 transition">
                            <div class="space-y-1">
                                <div class="flex items-center gap-2">
                                    <h3 class="font-bold text-sm text-stone-900">{{ $cat->name }}</h3>
                                    <span class="px-2 py-0.5 bg-pink-100 text-pink-700 text-[10px] font-bold rounded-md">
                                        {{ $cat->services_count }} dịch vụ
                                    </span>
                                </div>
                                <p class="text-xs text-stone-500">{{ $cat->description ?? 'Chưa có mô tả' }}</p>
                            </div>

                            <div class="flex items-center gap-2 self-end sm:self-center">
                                <form method="POST" action="{{ route('owner.categories.destroy', $cat->id) }}" onsubmit="return confirm('Bạn có chắc chắn muốn xóa danh mục này?');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="px-3 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 text-xs font-bold rounded-lg border border-rose-200 transition">
                                        Xóa
                                    </button>
                                </form>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</x-owner-layout>
