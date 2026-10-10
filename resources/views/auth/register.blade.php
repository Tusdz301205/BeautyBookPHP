@extends('layouts.app')

@section('title', 'Tạo tài khoản · BeautyBook')

@section('content')
<section class="split-shell auth-shell">
    <div class="split-copy">
        <p class="eyebrow">Tài khoản khách hàng</p>
        <h1>Dành một khoảng riêng cho bạn.</h1>
        <p class="lede">Tạo tài khoản để đặt lịch, lưu địa điểm yêu thích và quản lý những lần chăm sóc tiếp theo.</p>
    </div>

    <div class="form-panel">
        <form method="POST" action="{{ route('register') }}" class="form-stack">
            @csrf
            <label>
                <span>Họ tên</span>
                <input type="text" name="name" value="{{ old('name') }}" required autofocus autocomplete="name">
            </label>
            @error('name')<p class="field-error">{{ $message }}</p>@enderror

            <label>
                <span>Email</span>
                <input type="email" name="email" value="{{ old('email') }}" required autocomplete="email">
            </label>
            @error('email')<p class="field-error">{{ $message }}</p>@enderror

            <label>
                <span>Mật khẩu</span>
                <input type="password" name="password" required autocomplete="new-password">
            </label>
            @error('password')<p class="field-error">{{ $message }}</p>@enderror

            <label>
                <span>Nhập lại mật khẩu</span>
                <input type="password" name="password_confirmation" required autocomplete="new-password">
            </label>

            <button class="button button-primary" type="submit">Tạo tài khoản</button>
            <a class="text-link" href="{{ route('login') }}">Đã có tài khoản? Đăng nhập</a>
        </form>
    </div>
</section>
@endsection
