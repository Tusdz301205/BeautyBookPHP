@extends('layouts.app')
@section('title', 'Thanh toán '.$booking->booking_code)
@section('content')
<section class="page-shell management-shell module-workspace finance-workspace">
<div class="page-heading"><div><p class="eyebrow">Chi tiết thanh toán</p><h1>Thanh toán {{ $booking->booking_code }}</h1><p>Kiểm tra số tiền đã thu và các bước xử lý giao dịch của lịch hẹn.</p></div></div>
<p><a href="{{ route('finance.advanced') }}">Liệu trình và hóa đơn</a></p>
@if($role==='CUSTOMER'&&$balance['net_cents'] >= $balance['total_cents'])
<details class="panel module-disclosure"><summary>Yêu cầu hóa đơn</summary><form class="form-stack" method="POST" action="{{ route('finance.invoice-requests.store',$booking) }}">@csrf<input type="hidden" name="idempotency_key" value="{{ (string)\Illuminate\Support\Str::uuid() }}"><label>Tên người mua <input type="text" required name="buyer_name" value="{{ auth()->user()->name }}" maxlength="160"></label><label>Email <input type="email" name="buyer_email" value="{{ auth()->user()->email }}"></label><label>Mã số thuế <input type="text" name="buyer_tax_code" maxlength="80"></label><label>Địa chỉ <input type="text" name="buyer_address" maxlength="500"></label><button class="button button-primary">Gửi yêu cầu</button></form></details>
@endif
@if(in_array($role,['BUSINESS_OWNER','RECEPTIONIST'])&&$balance['net_cents'] >= $balance['total_cents'])
<form class="form-stack" method="POST" action="{{ route('finance.invoices.store',$booking) }}">@csrf<label>Thuế suất % <input type="number" min="0" max="100" step="0.01" name="tax_rate" value="0"></label><input type="hidden" name="tax_inclusive" value="1"><button class="button button-primary">Phát hành hóa đơn</button></form>
@endif
<dl class="summary-grid">@foreach(['total_cents'=>'Tổng tiền','collected_cents'=>'Đã thu xác minh','pending_cents'=>'Chờ xác minh','refunded_cents'=>'Đã hoàn','due_cents'=>'Còn phải thu'] as $key => $label)<div><dt>{{ $label }}</dt><dd>{{ \App\Services\Finance\Money::decimal($balance[$key]) }} {{ $booking->currency }}</dd></div>@endforeach</dl>
@if(in_array($role, ['BUSINESS_OWNER','RECEPTIONIST']) && $balance['collectable_cents'] > 0)
<form class="form-stack" method="POST" action="{{ route('finance.collect', $booking) }}">@csrf<h2>Ghi nhận thanh toán</h2>
<input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
<label>Phương thức <select name="method"><option value="CASH">Tiền mặt</option><option value="BANK_TRANSFER">Chuyển khoản ngân hàng · cần xác minh</option></select></label>
<label>Số tiền <input type="number" name="amount" step="0.01" min="0.01" max="{{ \App\Services\Finance\Money::decimal($balance['collectable_cents']) }}" value="{{ \App\Services\Finance\Money::decimal($balance['collectable_cents']) }}" required></label><button class="button button-primary">Ghi nhận</button></form>
@endif
@foreach($transactions as $transaction)
<article class="card"><h2>Giao dịch {{ $loop->iteration }}</h2><p>{{ $transaction->method }} · {{ \App\Services\Finance\Money::decimal($transaction->amount_cents) }} {{ $transaction->currency }} · @include('finance.status', ['value' => $transaction->status])</p>
@if($transaction->settlement_reference)<p>Mã đối soát: {{ $transaction->settlement_reference }}</p>@endif
@if($transaction->status === 'PENDING' && in_array($role, ['BUSINESS_OWNER','RECEPTIONIST']))
<form class="form-stack" method="POST" action="{{ route('finance.transaction', [$transaction, 'verify']) }}">@csrf<label>Mã đối soát <input type="text" required name="settlement_reference" maxlength="200"></label><label>Chứng từ xác minh <textarea name="evidence[receipt]" required maxlength="2000"></textarea></label><button class="button button-primary">Xác minh chuyển khoản</button></form>
@endif
@if($transaction->status === 'VERIFIED' && ! $transaction->reversal && $role !== 'CUSTOMER')
<details class="panel module-disclosure"><summary>Đảo giao dịch ghi nhận sai</summary><form class="form-stack" method="POST" action="{{ route('finance.transaction', [$transaction, 'reverse']) }}">@csrf<input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><label>Lý do <textarea name="reason" required maxlength="1000"></textarea></label><button class="button button-primary">Ghi bút toán đảo</button></form></details>
@if(in_array($role,['BUSINESS_OWNER','PLATFORM_ADMIN']))
<details class="panel module-disclosure"><summary>Yêu cầu hoàn tiền</summary><form class="form-stack" method="POST" action="{{ route('finance.transaction', [$transaction, 'refund']) }}">@csrf<label>Số tiền <input type="number" name="amount" step="0.01" min="0.01" required></label><label>Lý do <textarea name="reason" required maxlength="1000"></textarea></label><button class="button button-primary">Tạo yêu cầu</button></form></details>
@endif
@endif
@foreach($transaction->refunds as $refund)
<section><h3>Yêu cầu hoàn tiền · <span class="badge">@include('finance.status', ['value' => $refund->status])</span></h3><p>{{ \App\Services\Finance\Money::decimal($refund->amount_cents) }} {{ $transaction->currency }} · {{ $refund->reason }}</p>
@if($refund->status === 'PENDING' && in_array($role,['BUSINESS_OWNER','PLATFORM_ADMIN']) && $refund->requested_by !== auth()->id())
<form class="form-stack" method="POST" action="{{ route('finance.refund', [$refund, 'review']) }}">@csrf<label>Quyết định <select name="approve"><option value="1">Duyệt</option><option value="0">Từ chối</option></select></label><label>Ghi chú <textarea name="note" maxlength="1000"></textarea></label><button class="button button-primary">Lưu quyết định</button></form>
@endif
@if($role === 'PLATFORM_ADMIN' && in_array($refund->status,['APPROVED','PROCESSING']))
<form class="form-stack" method="POST" action="{{ route('finance.refund', [$refund, 'process']) }}">@csrf<label>Thao tác <select name="action">@if($refund->status === 'APPROVED')<option value="START">Bắt đầu xử lý</option>@else<option value="CONFIRM">Xác nhận đã hoàn</option><option value="FAIL">Ghi nhận thất bại</option>@endif</select></label><label>Mã biên nhận khi xác nhận <input type="text" name="settlement_reference" maxlength="200"></label><label>Lý do khi thất bại <textarea name="failure_reason" maxlength="1000"></textarea></label><button class="button button-primary">Cập nhật xử lý</button></form>
@endif
@if($refund->settlement_reference)<p>Biên nhận hoàn: {{ $refund->settlement_reference }}</p>@endif
@if($refund->failure_reason)<p>Lý do thất bại: {{ $refund->failure_reason }}</p>@endif
</section>
@endforeach
</article>
@endforeach
<h2>Sổ ghi nhận tiền</h2>
@forelse($ledger as $entry)<p>#{{ $entry->id }} · {{ $entry->type }} · {{ $entry->direction }} · {{ \App\Services\Finance\Money::decimal($entry->amount_cents) }} {{ $booking->currency }} · {{ $entry->created_at->format('d/m/Y H:i') }}</p>@empty<div class="empty-state panel"><h2>Chưa có dữ liệu</h2><p>Chưa có bút toán đã xác minh.</p></div>@endforelse
</section>
@endsection
