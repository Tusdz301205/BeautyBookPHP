@extends('layouts.app')
@section('title', 'Nhật ký kiểm toán · BeautyBook')
@section('content')
<section class="page-shell"><h1>Nhật ký kiểm toán</h1>@forelse($logs as $log)<article class="panel"><strong>{{ $log->action }} · {{ $log->entity_type }}</strong><p>{{ $log->created_at }} · Tài khoản #{{ $log->user_id }}</p><p>{{ $log->metadata }}</p></article>@empty<p>Chưa có bản ghi.</p>@endforelse{{ $logs->links() }}</section>
@endsection
