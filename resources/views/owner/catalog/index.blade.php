@extends('layouts.app')

@section('title', 'Catalog '.$business->name.' · BeautyBook')

@section('content')
<section class="page-shell owner-shell management-shell">
    <div class="page-heading management-heading">
        <div>
            <p class="eyebrow">Catalog doanh nghiệp</p>
            <h1>Dịch vụ, offering và biến thể.</h1>
            <p>Giữ giá mặc định ở cấp doanh nghiệp, sau đó điều chỉnh riêng theo từng chi nhánh khi cần.</p>
        </div>
        <div class="action-row compact-actions">
            <a class="button button-secondary" href="{{ route('owner.staff.index', $business) }}">Nhân sự</a>
            <a class="button button-secondary" href="{{ route('owner.businesses.edit', $business) }}">Hồ sơ doanh nghiệp</a>
        </div>
    </div>

    @if ($errors->any())
        <div class="notice danger-panel" role="alert">
            @foreach ($errors->all() as $error)
                <div>{{ $error }}</div>
            @endforeach
        </div>
    @endif

    <div class="management-grid">
        <form class="form-panel form-stack sticky-management-form" method="POST" action="{{ route('owner.catalog.store', $business) }}">
            @csrf
            <div>
                <p class="card-label">Dịch vụ mới</p>
                <h2>Tạo dịch vụ cho nhiều chi nhánh</h2>
            </div>
            <label>Tên dịch vụ<input type="text" name="name" value="{{ old('name') }}" required></label>
            <label>Slug<input type="text" name="slug" value="{{ old('slug') }}" placeholder="cat-tao-kieu" required></label>
            <div class="form-grid-two">
                <label>Nhóm dịch vụ
                    <select name="service_category_id" required>
                        <option value="">Chọn nhóm</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) old('service_category_id') === (string) $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Canonical service
                    <select name="canonical_service_id">
                        <option value="">Chưa ánh xạ</option>
                        @foreach ($canonicals as $canonical)
                            <option value="{{ $canonical->id }}" @selected((string) old('canonical_service_id') === (string) $canonical->id)>{{ $canonical->name }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <label>Mô tả<textarea name="description" rows="3">{{ old('description') }}</textarea></label>
            <label>Từ khóa<input type="text" name="keywords[]" value="{{ old('keywords.0') }}" placeholder="tóc nữ"></label>
            <div class="form-grid-two">
                <label>Giá mặc định<input type="number" name="base_price" value="{{ old('base_price') }}" min="0" step="1000" required></label>
                <label>Thời lượng mặc định (phút)<input type="number" name="base_duration_minutes" value="{{ old('base_duration_minutes', 60) }}" min="5" max="720" required></label>
            </div>
            <fieldset class="choice-fieldset">
                <legend>Chi nhánh áp dụng</legend>
                <div class="choice-grid">
                    @foreach ($business->branches as $branch)
                        <label class="check-card">
                            <input type="checkbox" name="branch_ids[]" value="{{ $branch->id }}" @checked(in_array($branch->id, old('branch_ids', [])))>
                            <span>{{ $branch->public_name ?: $branch->name }}</span>
                        </label>
                    @endforeach
                </div>
            </fieldset>
            <button class="button button-primary" type="submit">Tạo dịch vụ</button>
        </form>

        <div class="management-list">
            @forelse ($services as $service)
                <article class="panel management-card">
                    <div class="section-heading-row compact-heading">
                        <div>
                            <p class="card-label">{{ $service->category?->name }} · {{ $service->status }}</p>
                            <h2>{{ $service->name }}</h2>
                            <p class="muted-note">{{ $service->canonicalService?->name ?: 'Chưa ánh xạ canonical' }} · {{ number_format((float) $service->base_price, 0, ',', '.') }} đ · {{ $service->base_duration_minutes }} phút</p>
                        </div>
                        <form method="POST" action="{{ route('owner.catalog.archive', [$business, $service]) }}">
                            @csrf
                            @method('DELETE')
                            <button class="button button-danger" type="submit">Lưu trữ</button>
                        </form>
                    </div>

                    <details class="management-details">
                        <summary>Sửa thông tin dịch vụ</summary>
                        <form class="form-stack management-inner-form" method="POST" action="{{ route('owner.catalog.update', [$business, $service]) }}">
                            @csrf
                            @method('PUT')
                            <div class="form-grid-two">
                                <label>Tên<input type="text" name="name" value="{{ $service->name }}" required></label>
                                <label>Slug<input type="text" name="slug" value="{{ $service->slug }}" required></label>
                            </div>
                            <div class="form-grid-two">
                                <label>Nhóm
                                    <select name="service_category_id" required>
                                        @foreach ($categories as $category)
                                            <option value="{{ $category->id }}" @selected($service->service_category_id === $category->id)>{{ $category->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                                <label>Canonical
                                    <select name="canonical_service_id">
                                        <option value="">Chưa ánh xạ</option>
                                        @foreach ($canonicals as $canonical)
                                            <option value="{{ $canonical->id }}" @selected($service->canonical_service_id === $canonical->id)>{{ $canonical->name }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            </div>
                            <label>Mô tả<textarea name="description" rows="3">{{ $service->description }}</textarea></label>
                            <label>Từ khóa<input type="text" name="keywords[]" value="{{ $service->keywords[0] ?? '' }}"></label>
                            <div class="form-grid-three">
                                <label>Giá mặc định<input type="number" name="base_price" value="{{ $service->base_price }}" min="0" step="1000" required></label>
                                <label>Thời lượng<input type="number" name="base_duration_minutes" value="{{ $service->base_duration_minutes }}" min="5" max="720" required></label>
                                <label>Trạng thái
                                    <select name="status" required>
                                        <option value="ACTIVE" @selected($service->status === 'ACTIVE')>ACTIVE</option>
                                        <option value="INACTIVE" @selected($service->status === 'INACTIVE')>INACTIVE</option>
                                    </select>
                                </label>
                            </div>
                            <button class="button button-secondary" type="submit">Lưu dịch vụ</button>
                        </form>
                    </details>

                    <div class="offering-grid">
                        @foreach ($service->offerings as $offering)
                            <section class="offering-card">
                                <div class="offering-card-heading">
                                    <div>
                                        <strong>{{ $offering->branch?->public_name ?: $offering->branch?->name }}</strong>
                                        <span>{{ $offering->bookable ? 'Đang nhận lịch' : 'Tạm ngưng' }}</span>
                                    </div>
                                    <span>{{ number_format((float) $offering->price, 0, ',', '.') }} đ</span>
                                </div>

                                <form class="form-stack compact-form" method="POST" action="{{ route('owner.offerings.update', [$business, $offering]) }}">
                                    @csrf
                                    @method('PUT')
                                    <div class="form-grid-two">
                                        <label>Giá<input type="number" name="price" value="{{ $offering->price }}" min="0" step="1000" required></label>
                                        <label>Phút<input type="number" name="duration_minutes" value="{{ $offering->duration_minutes }}" min="5" max="720" required></label>
                                    </div>
                                    <label class="inline-check"><input type="checkbox" name="bookable" value="1" @checked($offering->bookable)> Cho phép đặt lịch</label>
                                    <button class="button button-secondary" type="submit">Lưu offering</button>
                                </form>

                                <details class="management-details">
                                    <summary>Thêm biến thể</summary>
                                    <form class="form-stack compact-form" method="POST" action="{{ route('owner.variants.store', [$business, $offering]) }}">
                                        @csrf
                                        <div class="form-grid-two">
                                            <label>Mã<input type="text" name="code" placeholder="LONG-HAIR" required></label>
                                            <label>Tên<input type="text" name="name" placeholder="Tóc dài" required></label>
                                        </div>
                                        <div class="form-grid-three">
                                            <label>Loại giá
                                                <select name="price_type" required>
                                                    <option value="FIXED">FIXED</option>
                                                    <option value="FROM">FROM</option>
                                                    <option value="RANGE">RANGE</option>
                                                    <option value="QUOTE">QUOTE</option>
                                                </select>
                                            </label>
                                            <label>Giá từ<input type="number" name="price" min="0" step="1000"></label>
                                            <label>Giá tối đa<input type="number" name="max_price" min="0" step="1000"></label>
                                        </div>
                                        <div class="form-grid-two">
                                            <label>Phút<input type="number" name="duration_minutes" min="5" max="720"></label>
                                            <label>Phút tối đa<input type="number" name="max_duration_minutes" min="5" max="720"></label>
                                        </div>
                                        <div class="form-grid-three">
                                            <label>Buffer trước<input type="number" name="buffer_before_minutes" value="0" min="0" max="240" required></label>
                                            <label>Buffer sau<input type="number" name="buffer_after_minutes" value="0" min="0" max="240" required></label>
                                            <label>Trạng thái
                                                <select name="status" required><option value="ACTIVE">ACTIVE</option><option value="INACTIVE">INACTIVE</option></select>
                                            </label>
                                        </div>
                                        <label class="inline-check"><input type="checkbox" name="consultation_required" value="1"> Cần tư vấn trước</label>
                                        <button class="button button-secondary" type="submit">Thêm biến thể</button>
                                    </form>
                                </details>

                                @if ($offering->variants->isNotEmpty())
                                    <div class="variant-list">
                                        @foreach ($offering->variants as $variant)
                                            <div class="variant-row">
                                                <div>
                                                    <strong>{{ $variant->name }}</strong>
                                                    <span>{{ $variant->code }} · {{ $variant->price_type }} · {{ $variant->status }}</span>
                                                </div>
                                                <form method="POST" action="{{ route('owner.variants.archive', [$business, $offering, $variant]) }}">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button class="text-link danger-link" type="submit">Lưu trữ</button>
                                                </form>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                <form class="media-form" method="POST" enctype="multipart/form-data" action="{{ route('owner.media.store', $business) }}">
                                    @csrf
                                    <input type="hidden" name="entity_type" value="SERVICE">
                                    <input type="hidden" name="entity_id" value="{{ $offering->id }}">
                                    <label>Ảnh dịch vụ<input type="file" name="file" accept="image/jpeg,image/png,image/webp" required></label>
                                    <select name="visibility" aria-label="Quyền xem media"><option value="PUBLIC">Public</option><option value="PRIVATE">Private</option></select>
                                    <button class="button button-secondary" type="submit">Tải ảnh</button>
                                </form>
                            </section>
                        @endforeach
                    </div>
                </article>
            @empty
                <div class="panel empty-state">
                    <p class="card-label">Catalog trống</p>
                    <h2>Tạo dịch vụ đầu tiên</h2>
                    <p class="muted-note">Dịch vụ mới sẽ tạo offering tại các chi nhánh bạn chọn.</p>
                </div>
            @endforelse
        </div>
    </div>

    @if ($mediaFiles->isNotEmpty())
        <div class="panel">
            <div class="section-heading-row compact-heading">
                <div><p class="card-label">Media gần đây</p><h2>Ảnh dịch vụ đã tải</h2></div>
            </div>
            <div class="media-list">
                @foreach ($mediaFiles as $media)
                    <a class="media-row" href="{{ route('media.show', $media) }}" target="_blank" rel="noopener">
                        <span>{{ $media->original_name ?: basename($media->path) }}</span>
                        <small>{{ $media->visibility }} · {{ number_format($media->file_size / 1024, 1) }} KB</small>
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</section>
@endsection
