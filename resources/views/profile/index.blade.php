@extends('layouts.app')
@section('title','Hồ sơ cá nhân · BeautyBook')
@section('content')
<section class="page-shell management-shell"><div class="page-heading"><p class="eyebrow">Tài khoản khách hàng</p><h1>Hồ sơ cá nhân</h1><p>{{ $email }}</p></div>
@if($errors->any())<div class="notice danger-panel">{{ $errors->first() }}</div>@endif
<div class="panel"><form class="form-stack" method="post" action="{{ route('profile.update') }}">@csrf @method('PATCH')
<label>Họ tên<input type="text" name="name" required minlength="2" maxlength="255" value="{{ old('name',$name) }}"></label>
<label>Số điện thoại<input name="phone" type="tel" maxlength="32" value="{{ old('phone',$phone) }}"></label>
<label>Ngày sinh<input name="date_of_birth" type="date" max="{{ now()->toDateString() }}" value="{{ old('date_of_birth',$date_of_birth) }}"></label>
<label>Địa chỉ<textarea name="address" maxlength="1000">{{ old('address',$address) }}</textarea></label><button class="button">Lưu hồ sơ</button></form></div>
<div class="panel"><h2>Ảnh đại diện</h2>@if($avatar_url)<img src="{{ $avatar_url }}" alt="Ảnh đại diện của {{ $name }}" width="120" height="120" style="object-fit:cover;border-radius:50%">@endif
<form class="form-stack" method="post" enctype="multipart/form-data" action="{{ route('profile.avatar.upload') }}">@csrf<label>Ảnh JPEG, PNG hoặc WEBP, tối đa 10 MB<input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" required></label><button class="button">Cập nhật ảnh</button></form></div></section>
@endsection
