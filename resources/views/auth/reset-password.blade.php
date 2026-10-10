@extends('layouts.app')

@section('title', 'Đặt lại mật khẩu · BeautyBook')

@section('content')
<section class="page-shell narrow-shell">
    <p class="eyebrow">Mật khẩu mới</p>
    <h1>Đặt lại thông tin đăng nhập.</h1>
    <form method="POST" action="{{ route('password.store') }}" class="form-stack">
        @csrf
        <input type="hidden" name="token" value="{{ $request->route('token') }}">
        <label>
            <span>Email</span>
            <input type="email" name="email" value="{{ old('email', $request->email) }}" required autocomplete="email">
        </label>
        @error('email')<p class="field-error">{{ $message }}</p>@enderror
        <label>
            <span>Mật khẩu mới</span>
            <input type="password" name="password" required autocomplete="new-password">
        </label>
        @error('password')<p class="field-error">{{ $message }}</p>@enderror
        <label>
            <span>Nhập lại mật khẩu</span>
            <input type="password" name="password_confirmation" required autocomplete="new-password">
        </label>
        <button class="button button-primary" type="submit">Lưu mật khẩu mới</button>
    </form>
</section>
@endsection
