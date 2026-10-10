@extends('layouts.app')

@section('title', 'Bảo mật · BeautyBook')

@section('content')
<section class="page-shell">
    <div class="page-heading">
        <p class="eyebrow">Bảo mật tài khoản</p>
        <h1>Bảo mật tài khoản</h1>
        <p>Thu hồi toàn bộ phiên yêu cầu xác nhận lại mật khẩu hiện tại.</p>
    </div>

    <div class="security-grid">
        <div class="panel">
            <h2>Quyền truy cập</h2>
            <div class="assignment-list">
                @foreach ($assignments as $assignment)
                    <div>
                        <strong>{{ $assignment->role_name }}</strong>
                        <span>{{ $assignment->business?->name ?? $assignment->scope_key }}{{ $assignment->branch ? ' · '.$assignment->branch->name : '' }}</span>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="panel danger-panel">
            <h2>Thu hồi phiên</h2>
            <p>Đăng xuất tài khoản này khỏi tất cả phiên được lưu trong hệ thống.</p>
            <form method="POST" action="{{ route('security.sessions.revoke') }}" class="form-stack">
                @csrf
                <label>
                    <span>Mật khẩu hiện tại</span>
                    <input type="password" name="password" required autocomplete="current-password">
                </label>
                @error('password')<p class="field-error">{{ $message }}</p>@enderror
                <button class="button button-danger" type="submit">Thu hồi tất cả phiên</button>
            </form>
        </div>
    </div>
</section>
@endsection
