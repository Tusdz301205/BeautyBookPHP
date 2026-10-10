@extends('layouts.app')

@section('title', 'Đăng nhập · BeautyBook')

@section('content')
<section class="split-shell auth-shell">
    <div class="split-copy">
        <img class="bb-auth-image" src="{{ asset('images/beautybook/auth-login-editorial-720.webp') }}" width="720" height="576" alt="">
        <p class="eyebrow">Chào mừng trở lại</p>
        <h1>Một lịch hẹn mới đang chờ.</h1>
        <p class="lede">Đăng nhập để quản lý lịch hẹn, lưu dịch vụ yêu thích và tiếp tục chăm sóc bản thân.</p>
    </div>

    <div class="form-panel">
        <form method="POST" action="{{ route('login') }}" class="form-stack">
            @csrf
            <label>
                <span>Email</span>
                <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
            </label>
            @error('email')<p class="field-error">{{ $message }}</p>@enderror

            <label>
                <span>Mật khẩu</span>
                <input type="password" name="password" required autocomplete="current-password">
            </label>
            @error('password')<p class="field-error">{{ $message }}</p>@enderror

            <label class="check-row">
                <input type="checkbox" name="remember" value="1">
                <span>Giữ đăng nhập</span>
            </label>

            <button class="button button-primary" type="submit">Đăng nhập</button>
            <a class="text-link" href="{{ route('password.request') }}">Quên mật khẩu?</a>
        </form>

        @if (app()->environment('local'))
            <div class="demo-note">
                <strong>Tài khoản demo</strong>
                <span>customer / owner / receptionist / staff / admin @beautybook.local</span>
                <span>Mật khẩu: BeautyBook123!</span>
            </div>
        @endif
    </div>
</section>
@endsection
