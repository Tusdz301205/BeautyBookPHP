<x-customer-layout>
    <x-slot name="title">Dịch vụ đã lưu</x-slot>

    <div class="bg-white rounded-3xl p-6 sm:p-8 border border-stone-200/80 shadow-xs space-y-6">
        <div>
            <h1 class="text-2xl font-bold text-stone-900">Dịch vụ yêu thích</h1>
            <p class="text-xs text-stone-500 mt-1">Danh sách các liệu trình bạn đã lưu để tiện đặt lịch khi cần</p>
        </div>

        @if($favorites->isEmpty())
            <div class="text-center py-16 text-stone-400 text-xs">
                <span class="text-4xl block mb-2">💖</span>
                Bạn chưa lưu dịch vụ nào. Hãy dạo quanh mục Khám phá và bấm biểu tượng trái tim để lưu nhé!
            </div>
        @else
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                @foreach($favorites as $service)
                    <div class="fav-card bg-stone-50 rounded-2xl p-4 border border-stone-200 hover:border-pink-200 flex flex-col justify-between transition">
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-bold text-pink-600">{{ $service->branch?->business?->name }}</span>
                                <button type="button" 
                                    onclick="toggleFavorite({{ $service->id }}, this)" 
                                    data-remove-on-unsave="true"
                                    class="text-sm hover:scale-110 transition" title="Bỏ lưu">
                                    <span class="fav-icon">❤️</span>
                                </button>
                            </div>
                            <h3 class="font-bold text-sm text-stone-900 hover:text-pink-600 line-clamp-2">
                                <a href="{{ route('services.show', $service->id) }}">{{ $service->name }}</a>
                            </h3>
                            <p class="text-xs text-stone-500 line-clamp-2">{{ $service->description }}</p>
                            <div class="text-[11px] text-stone-400">📍 {{ $service->branch?->name }} • ⏱ {{ $service->duration_minutes }}p</div>
                        </div>

                        <div class="mt-4 pt-3 border-t border-stone-200 flex items-center justify-between">
                            <div>
                                @if($service->has_discount)
                                    <span class="text-xs font-bold text-pink-600">{{ number_format($service->discount_price, 0, ',', '.') }} đ</span>
                                @else
                                    <span class="text-xs font-bold text-stone-900">{{ number_format($service->price, 0, ',', '.') }} đ</span>
                                @endif
                            </div>
                            <a href="{{ route('customer.bookings.create', ['service_id' => $service->id, 'branch_id' => $service->branch_id]) }}" 
                                class="px-3 py-1.5 bg-pink-600 hover:bg-pink-700 text-white text-xs font-bold rounded-xl transition">
                                Đặt lịch
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="pt-4">
                {{ $favorites->links() }}
            </div>
        @endif
    </div>
</x-customer-layout>
