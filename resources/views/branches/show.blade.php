<x-public-layout>
    <x-slot name="title">{{ $branch->name }} — BeautyBook</x-slot>

    <!-- Leaflet CSS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />

    <!-- Branch Header Banner -->
    <div class="bg-gradient-to-r from-stone-900 via-stone-800 to-stone-900 text-white py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex flex-col md:flex-row items-center justify-between gap-6">
                <div class="space-y-2 text-center md:text-left">
                    <a href="{{ route('salons.show', $branch->business->slug) }}" class="text-xs font-bold text-pink-400 hover:text-pink-300">
                        ← {{ $branch->business->name }}
                    </a>
                    <h1 class="text-3xl sm:text-4xl font-extrabold text-white tracking-tight">{{ $branch->name }}</h1>
                    <p class="text-sm text-stone-300">📍 {{ $branch->address }} • {{ $branch->district?->name }}</p>
                </div>

                <div class="flex items-center gap-3">
                    @if($branch->phone)
                        <a href="tel:{{ $branch->phone }}" class="px-4 py-2.5 bg-stone-800 hover:bg-stone-700 text-stone-200 text-xs font-bold rounded-xl border border-stone-700 transition">
                            📞 Hotline: {{ $branch->phone }}
                        </a>
                    @endif
                    <a href="{{ route('customer.bookings.create', ['branch_id' => $branch->id]) }}" class="px-5 py-2.5 bg-pink-600 hover:bg-pink-700 text-white text-xs font-bold rounded-xl shadow-md transition">
                        ✨ Đặt lịch tại cơ sở này
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 items-start">

            <!-- Left: Services & Staff -->
            <div class="lg:col-span-2 space-y-8">
                <!-- Services at this Branch -->
                <div class="bg-white rounded-3xl p-6 border border-stone-200/80 shadow-xs">
                    <h2 class="text-xl font-bold text-stone-900 mb-6 flex items-center gap-2">
                        <span>💅</span>
                        <span>Dịch vụ tại cơ sở ({{ $branch->services->count() }})</span>
                    </h2>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        @foreach($branch->services as $service)
                            <div class="p-4 rounded-2xl border border-stone-200 hover:border-pink-300 transition flex flex-col justify-between">
                                <div class="space-y-1">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-bold text-stone-900 hover:text-pink-600">
                                            <a href="{{ route('services.show', $service->id) }}">{{ $service->name }}</a>
                                        </span>
                                    </div>
                                    <p class="text-xs text-stone-500 line-clamp-2">{{ $service->description }}</p>
                                    <span class="text-[11px] text-stone-400">⏱ {{ $service->duration_minutes }} phút</span>
                                </div>

                                <div class="mt-4 pt-3 border-t border-stone-100 flex items-center justify-between">
                                    <div>
                                        @if($service->has_discount)
                                            <span class="text-xs font-extrabold text-pink-600">{{ number_format($service->discount_price, 0, ',', '.') }} đ</span>
                                        @else
                                            <span class="text-xs font-extrabold text-stone-900">{{ number_format($service->price, 0, ',', '.') }} đ</span>
                                        @endif
                                    </div>
                                    <a href="{{ route('customer.bookings.create', ['service_id' => $service->id, 'branch_id' => $branch->id]) }}" 
                                        class="px-3 py-1.5 bg-pink-50 hover:bg-pink-100 text-pink-700 text-xs font-bold rounded-lg transition">
                                        Chọn dịch vụ
                                    </a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>

                <!-- Staff at this Branch -->
                <div class="bg-white rounded-3xl p-6 border border-stone-200/80 shadow-xs">
                    <h2 class="text-xl font-bold text-stone-900 mb-6 flex items-center gap-2">
                        <span>👥</span>
                        <span>Đội ngũ Kỹ thuật viên & Stylist</span>
                    </h2>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        @foreach($branch->staff as $member)
                            <div class="p-4 rounded-2xl border border-stone-200 flex items-center gap-4">
                                <img src="{{ $member->avatar_url }}" class="w-14 h-14 rounded-2xl object-cover border border-stone-200">
                                <div>
                                    <h4 class="font-bold text-sm text-stone-800">{{ $member->name }}</h4>
                                    <p class="text-xs text-pink-600 font-medium">{{ $member->position ?? 'Kỹ thuật viên' }}</p>
                                    <p class="text-[11px] text-stone-400 mt-0.5 line-clamp-1">{{ $member->bio }}</p>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <!-- Right: Map & Working Hours -->
            <div class="space-y-6">
                <!-- Interactive Leaflet Map (OpenStreetMap) -->
                <div class="bg-white rounded-3xl p-5 border border-stone-200/80 shadow-xs space-y-3">
                    <h3 class="font-bold text-sm text-stone-900 flex items-center gap-1.5">
                        <span>🗺️</span>
                        <span>Bản đồ vị trí (OpenStreetMap)</span>
                    </h3>
                    <div id="branchMap" class="w-full h-56 rounded-2xl border border-stone-200"></div>
                    <p class="text-xs text-stone-500">{{ $branch->address }}</p>
                </div>

                <!-- Working Hours -->
                <div class="bg-white rounded-3xl p-6 border border-stone-200/80 shadow-xs">
                    <h3 class="font-bold text-sm text-stone-900 mb-4 flex items-center gap-1.5">
                        <span>⏰</span>
                        <span>Giờ hoạt động trong tuần</span>
                    </h3>

                    <div class="divide-y divide-stone-100 text-xs">
                        @foreach($branch->workingHours->sortBy('day_of_week') as $wh)
                            <div class="py-2.5 flex items-center justify-between">
                                <span class="font-medium text-stone-700">{{ $wh->day_name }}</span>
                                @if($wh->is_closed)
                                    <span class="text-rose-600 font-semibold">Nghỉ</span>
                                @else
                                    <span class="text-stone-600 font-mono">{{ substr($wh->open_time, 0, 5) }} - {{ substr($wh->close_time, 0, 5) }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Leaflet JS -->
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const lat = {{ $branch->latitude ?? 10.7745 }};
            const lng = {{ $branch->longitude ?? 106.7032 }};
            const map = L.map('branchMap').setView([lat, lng], 15);

            L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '© OpenStreetMap'
            }).addTo(map);

            L.marker([lat, lng]).addTo(map)
                .bindPopup('<b>{{ $branch->name }}</b><br>{{ $branch->address }}')
                .openPopup();
        });
    </script>
</x-public-layout>
