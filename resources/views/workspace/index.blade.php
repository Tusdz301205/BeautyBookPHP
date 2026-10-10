@extends('layouts.app')

@section('title', 'Chọn workspace · BeautyBook')

@section('content')
<section class="page-shell">
    <div class="page-heading">
        <p class="eyebrow">Workspace vận hành</p>
        <h1>Chọn phạm vi bạn đang làm việc.</h1>
        <p>Mỗi lựa chọn được kiểm tra lại từ role assignment đang còn hiệu lực.</p>
    </div>

    <div class="workspace-grid">
        @forelse ($assignments as $assignment)
            <form method="POST" action="{{ route('workspace.store') }}" class="workspace-option {{ $current?->id === $assignment->id ? 'is-current' : '' }}">
                @csrf
                <input type="hidden" name="assignment_id" value="{{ $assignment->id }}">
                <span class="card-label">{{ $assignment->role_name }}</span>
                <strong>{{ $assignment->business?->name ?? 'Nền tảng BeautyBook' }}</strong>
                <span>{{ $assignment->branch?->name ?? $assignment->scope_key }}</span>
                <button class="button button-secondary" type="submit">
                    {{ $current?->id === $assignment->id ? 'Đang sử dụng' : 'Chọn workspace' }}
                </button>
            </form>
        @empty
            <div class="notice">Tài khoản này chưa có workspace vận hành.</div>
        @endforelse
    </div>
</section>
@endsection
