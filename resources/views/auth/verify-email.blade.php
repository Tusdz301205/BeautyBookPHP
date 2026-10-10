@extends('layouts.app')

@section('title', 'Xác minh email · BeautyBook')

@section('content')
<section class="page-shell narrow-shell">
    <p class="eyebrow">Xác minh email</p>
    <h1>Kiểm tra hộp thư của bạn.</h1>
    <p class="lede">Bạn cần xác minh địa chỉ email trước khi mở không gian BeautyBook.</p>

    @if (session('status') === 'verification-link-sent')
        <div class="notice">Liên kết xác minh mới đã được gửi.</div>
    @endif

    <div class="action-row">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button class="button button-primary" type="submit">Gửi lại liên kết</button>
        </form>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="button button-secondary" type="submit">Đăng xuất</button>
        </form>
    </div>
</section>
@endsection
