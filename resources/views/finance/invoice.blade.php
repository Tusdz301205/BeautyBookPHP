@extends('layouts.app')
@section('title','Hóa đơn '.$invoice->invoice_number)
@section('content')
<section class="page-shell management-shell module-workspace finance-workspace">
<div class="page-heading"><div><p class="eyebrow">Chứng từ thanh toán</p><h1>Hóa đơn {{ $invoice->invoice_number }}</h1><p>Thông tin người mua, dịch vụ và lịch sử phát hành hóa đơn.</p></div></div><p>@include('finance.status', ['value' => $invoice->status]) · Phiên bản {{ $invoice->version }}</p>
<h2>{{ $seller['business_name'] }}</h2><p>{{ $seller['branch_name'] }} · {{ $seller['address'] }}</p>
<h2>Người mua</h2><p>{{ $buyer['name'] ?? '' }} · {{ $buyer['email'] ?? '' }}</p><p>{{ $buyer['tax_code'] ?? '' }} · {{ $buyer['address'] ?? '' }}</p>
<div class="table-scroll" role="region" aria-label="Chi tiết giao dịch" tabindex="0"><table><thead><tr><th>Dịch vụ</th><th>Giá</th><th>Giảm</th><th>Thuế</th><th>Thành tiền</th></tr></thead><tbody>@foreach($lines as $line)<tr><td>{{ $line['description'] }}</td>@foreach(['unit_price_cents','discount_cents','tax_cents','total_cents'] as $key)<td>{{ \App\Services\Finance\Money::decimal($line[$key]) }}</td>@endforeach</tr>@endforeach</tbody></table></div>
<p>Thuế {{ \App\Services\Finance\Money::decimal($invoice->tax_rate_basis_points) }}% {{ $invoice->tax_inclusive?'đã bao gồm trong giá':'cộng vào giá' }}: {{ \App\Services\Finance\Money::decimal($invoice->tax_cents) }} VND</p><p><strong>Tổng: {{ \App\Services\Finance\Money::decimal($invoice->total_cents) }} VND</strong></p>
@if($role==='BUSINESS_OWNER'&&$invoice->status==='ISSUED')
<details class="panel module-disclosure"><summary>Hủy hóa đơn</summary><form class="form-stack" method="POST" action="{{ route('finance.invoices.action',[$invoice->id,'cancel']) }}">@csrf<label>Lý do <textarea name="reason" required maxlength="1000"></textarea></label><button class="button button-secondary">Hủy</button></form></details>
<details class="panel module-disclosure"><summary>Phát hành hóa đơn thay thế</summary><form class="form-stack" method="POST" action="{{ route('finance.invoices.action',[$invoice->id,'reissue']) }}">@csrf<label>Lý do <textarea name="reason" required maxlength="1000"></textarea></label><label>Tên người mua mới <input type="text" name="buyer_name" maxlength="160"></label><label>Email <input type="email" name="buyer_email"></label><label>Mã số thuế <input type="text" name="buyer_tax_code" maxlength="80"></label><label>Địa chỉ <input type="text" name="buyer_address" maxlength="500"></label><button class="button button-primary">Phát hành thay thế</button></form></details>
@endif
<h2>Lịch sử hóa đơn</h2>@foreach($events as $event)<p>{{ $event->action }} · {{ $event->reason }} · {{ $event->created_at }}</p>@endforeach
</section>
@endsection
