@extends('layouts.app')

@section('title', 'Quên mật khẩu · BeautyBook')

@section('content')
<section class="page-shell narrow-shell">
    <p class="eyebrow">Khôi phục truy cập</p>
    <h1>Nhận liên kết đặt lại mật khẩu.</h1>
    <form method="POST" action="{{ route('password.email') }}" class="form-stack">
        @csrf
        <label>
            <span>Email</span>
            <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
        </label>
        @error('email')<p class="field-error">{{ $message }}</p>@enderror
        <button class="button button-primary" type="submit">Gửi liên kết</button>
    </form>
</section>
@endsection
