@extends('layouts.app')

@section('title', 'Nhân sự '.$business->name.' · BeautyBook')

@section('content')
<section class="page-shell owner-shell management-shell">
    <div class="page-heading management-heading">
        <div>
            <p class="eyebrow">Nhân sự doanh nghiệp</p>
            <h1>Hồ sơ, capability và lời mời.</h1>
            <p>Capability chỉ được gán cho offering đang hoạt động tại đúng chi nhánh của nhân viên.</p>
        </div>
        <div class="action-row compact-actions">
            <a class="button button-secondary" href="{{ route('owner.catalog.index', $business) }}">Catalog</a>
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
        <div class="management-sidebar">
            <form class="form-panel form-stack" method="POST" action="{{ route('owner.staff.store', $business) }}">
                @csrf
                <div><p class="card-label">Hồ sơ mới</p><h2>Thêm nhân viên</h2></div>
                <label>Họ tên<input type="text" name="full_name" value="{{ old('full_name') }}" required></label>
                <div class="form-grid-two">
                    <label>Chi nhánh
                        <select name="branch_id" required>
                            <option value="">Chọn chi nhánh</option>
                            @foreach ($business->branches as $branch)
                                <option value="{{ $branch->id }}" @selected((string) old('branch_id') === (string) $branch->id)>{{ $branch->public_name ?: $branch->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label>Mã nhân viên<input type="text" name="employee_code" value="{{ old('employee_code') }}"></label>
                </div>
                <div class="form-grid-two">
                    <label>Vị trí<input type="text" name="position" value="{{ old('position') }}"></label>
                    <label>Kinh nghiệm (năm)<input type="number" name="experience_years" value="{{ old('experience_years') }}" min="0" max="80"></label>
                </div>
                <label>Giới thiệu<textarea name="bio" rows="3">{{ old('bio') }}</textarea></label>
                <div class="form-grid-two">
                    <label>Trạng thái
                        <select name="status" required>
                            <option value="PROFILE_ONLY">PROFILE_ONLY</option>
                            <option value="INVITED">INVITED</option>
                            <option value="ACTIVE">ACTIVE</option>
                            <option value="LOCKED">LOCKED</option>
                            <option value="INACTIVE">INACTIVE</option>
                        </select>
                    </label>
                    <label>Ngày vào làm<input type="date" name="hired_at" value="{{ old('hired_at') }}"></label>
                </div>
                <label class="inline-check"><input type="checkbox" name="public_visible" value="1" @checked(old('public_visible'))> Hiển thị công khai</label>
                <label class="inline-check"><input type="checkbox" name="is_bookable" value="1" @checked(old('is_bookable'))> Có thể nhận lịch</label>
                <button class="button button-primary" type="submit">Tạo hồ sơ</button>
            </form>

            <form class="form-panel form-stack" method="POST" action="{{ route('owner.staff.invite', $business) }}">
                @csrf
                <div><p class="card-label">Lời mời</p><h2>Mời tài khoản vận hành</h2></div>
                <label>Email<input type="email" name="email" required></label>
                <div class="form-grid-two">
                    <label>Vai trò
                        <select name="role_code" required><option value="STAFF">STAFF</option><option value="RECEPTIONIST">RECEPTIONIST</option></select>
                    </label>
                    <label>Chi nhánh
                        <select name="branch_id"><option value="">Toàn doanh nghiệp</option>@foreach ($business->branches as $branch)<option value="{{ $branch->id }}">{{ $branch->public_name ?: $branch->name }}</option>@endforeach</select>
                    </label>
                </div>
                <label>Gắn hồ sơ nhân viên
                    <select name="staff_profile_id"><option value="">Không gắn</option>@foreach ($staffProfiles as $staff)<option value="{{ $staff->id }}">{{ $staff->full_name }}</option>@endforeach</select>
                </label>
                <button class="button button-secondary" type="submit">Tạo lời mời</button>
            </form>
        </div>

        <div class="management-list">
            @forelse ($staffProfiles as $staff)
                <article class="panel management-card">
                    <div class="section-heading-row compact-heading">
                        <div>
                            <p class="card-label">{{ $staff->status }} · {{ $staff->branch?->public_name ?: $staff->branch?->name }}</p>
                            <h2>{{ $staff->full_name }}</h2>
                            <p class="muted-note">{{ $staff->position ?: 'Chưa có vị trí' }} · {{ $staff->offerings->count() }} capability</p>
                        </div>
                        <form method="POST" action="{{ route('owner.staff.archive', [$business, $staff]) }}">
                            @csrf
                            @method('DELETE')
                            <button class="button button-danger" type="submit">Lưu trữ</button>
                        </form>
                    </div>

                    <form class="form-stack management-inner-form" method="POST" action="{{ route('owner.staff.update', [$business, $staff]) }}">
                        @csrf
                        @method('PUT')
                        <div class="form-grid-two">
                            <label>Họ tên<input type="text" name="full_name" value="{{ $staff->full_name }}" required></label>
                            <label>Chi nhánh
                                <select name="branch_id" required>@foreach ($business->branches as $branch)<option value="{{ $branch->id }}" @selected($staff->branch_id === $branch->id)>{{ $branch->public_name ?: $branch->name }}</option>@endforeach</select>
                            </label>
                        </div>
                        <div class="form-grid-three">
                            <label>Vị trí<input type="text" name="position" value="{{ $staff->position }}"></label>
                            <label>Mã nhân viên<input type="text" name="employee_code" value="{{ $staff->employee_code }}"></label>
                            <label>Kinh nghiệm<input type="number" name="experience_years" value="{{ $staff->experience_years }}" min="0" max="80"></label>
                        </div>
                        <label>Giới thiệu<textarea name="bio" rows="3">{{ $staff->bio }}</textarea></label>
                        <div class="form-grid-two">
                            <label>Trạng thái
                                <select name="status" required>
                                    @foreach (['PROFILE_ONLY', 'INVITED', 'ACTIVE', 'LOCKED', 'INACTIVE'] as $status)
                                        <option value="{{ $status }}" @selected($staff->status === $status)>{{ $status }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>Ngày vào làm<input type="date" name="hired_at" value="{{ $staff->hired_at?->format('Y-m-d') }}"></label>
                        </div>
                        <div class="inline-choice-row">
                            <label class="inline-check"><input type="checkbox" name="public_visible" value="1" @checked($staff->public_visible)> Hiển thị công khai</label>
                            <label class="inline-check"><input type="checkbox" name="is_bookable" value="1" @checked($staff->is_bookable)> Có thể nhận lịch</label>
                        </div>
                        <button class="button button-secondary" type="submit">Lưu hồ sơ</button>
                    </form>

                    <section class="capability-panel">
                        <div>
                            <p class="card-label">Capability</p>
                            <h3>Dịch vụ nhân viên có thể thực hiện</h3>
                        </div>
                        <form class="form-stack" method="POST" action="{{ route('owner.staff.capabilities', [$business, $staff]) }}">
                            @csrf
                            @method('PUT')
                            <div class="choice-grid">
                                @forelse ($offerings->where('branch_id', $staff->branch_id) as $offering)
                                    <label class="check-card">
                                        <input type="checkbox" name="offering_ids[]" value="{{ $offering->id }}" @checked($staff->offerings->contains('id', $offering->id))>
                                        <span>{{ $offering->service?->name }} · {{ number_format((float) $offering->price, 0, ',', '.') }} đ</span>
                                    </label>
                                @empty
                                    <p class="muted-note">Chi nhánh này chưa có offering đang nhận lịch.</p>
                                @endforelse
                            </div>
                            <button class="button button-secondary" type="submit">Lưu capability</button>
                        </form>
                    </section>

                    <form class="media-form" method="POST" enctype="multipart/form-data" action="{{ route('owner.media.store', $business) }}">
                        @csrf
                        <input type="hidden" name="entity_type" value="STAFF">
                        <input type="hidden" name="entity_id" value="{{ $staff->id }}">
                        <label>Ảnh nhân viên<input type="file" name="file" accept="image/jpeg,image/png,image/webp" required></label>
                        <select name="visibility" aria-label="Quyền xem media"><option value="PUBLIC">Public</option><option value="PRIVATE">Private</option></select>
                        <button class="button button-secondary" type="submit">Tải ảnh</button>
                    </form>
                </article>
            @empty
                <div class="panel empty-state"><p class="card-label">Chưa có nhân sự</p><h2>Tạo hồ sơ đầu tiên</h2></div>
            @endforelse
        </div>
    </div>

    @if ($invitations->isNotEmpty() || $mediaFiles->isNotEmpty())
        <div class="review-grid">
            <div class="panel">
                <p class="card-label">Lời mời gần đây</p>
                <div class="stack-list">
                    @forelse ($invitations as $invitation)
                        <div class="list-row"><div><strong>{{ $invitation->email }}</strong><span>{{ $invitation->role_code }} · {{ $invitation->status }}</span></div><span>{{ $invitation->expires_at?->format('d/m/Y') }}</span></div>
                    @empty
                        <p class="muted-note">Chưa có lời mời.</p>
                    @endforelse
                </div>
            </div>
            <div class="panel">
                <p class="card-label">Media gần đây</p>
                <div class="media-list">
                    @forelse ($mediaFiles as $media)
                        <a class="media-row" href="{{ route('media.show', $media) }}" target="_blank" rel="noopener"><span>{{ $media->original_name ?: basename($media->path) }}</span><small>{{ $media->visibility }} · {{ number_format($media->file_size / 1024, 1) }} KB</small></a>
                    @empty
                        <p class="muted-note">Chưa có ảnh nhân viên.</p>
                    @endforelse
                </div>
            </div>
        </div>
    @endif
</section>
@endsection
