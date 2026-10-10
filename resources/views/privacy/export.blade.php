@extends('layouts.app')
@section('title', 'Tải dữ liệu · BeautyBook')
@section('content')
<section class="page-shell management-shell module-workspace"><h1>Gói dữ liệu đã sẵn sàng</h1><p>Tải trước {{ $package['expires_at'] }}. Gói chỉ dùng được một lần.</p>
<form class="form-stack" method="POST" action="{{ route('privacy.download', $package['id']) }}">@csrf<input type="hidden" name="download_token" value="{{ $package['download_token'] }}"><button class="button button-primary">Tải dữ liệu của tôi</button></form></section>
@endsection
