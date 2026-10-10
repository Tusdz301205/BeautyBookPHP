<?php

namespace App\Services\Scheduling;

use App\Exceptions\BookingConflictException;
use App\Models\Booking;
use App\Models\BookingContact;
use App\Models\BookingPricingSnapshot;
use App\Models\BookingService;
use App\Models\BookingStatusHistory;
use App\Models\Branch;
use App\Models\Combo;
use App\Models\CustomerProfile;
use App\Models\IdempotencyRecord;
use App\Services\Booking\AvailabilityService;
use App\Services\Booking\BookingAccessService;
use App\Services\Booking\BookingPolicyService;
use App\Services\Pricing\QuoteService;
use App\Services\Promotions\PromotionPricingService;
use App\Services\Operations\LoyaltyService;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateComboBookingService
{
    public function __construct(
        private ComboService $combos,
        private BookingAccessService $access,
        private BookingPolicyService $policy,
        private QuoteService $quotes,
        private AvailabilityService $availability,
        private PromotionPricingService $promotions,
        private LoyaltyService $loyalty,
    ) {
    }

    public function create(Branch $branch, Combo $combo, array $context, array $data): array
    {
        $payload = ['branch_id' => $branch->id, 'combo_id' => $combo->id, 'workspace_key' => $context['workspace_key'], 'data' => collect($data)->except('idempotency_key')->all()];
        ksort($payload['data']);
        foreach (['staff_assignments', 'variant_assignments'] as $field) {
            if (isset($payload['data'][$field])) {
                ksort($payload['data'][$field]);
            }
        }
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($branch, $combo, $context, $data, $hash) {
            DB::table('idempotency_records')->insertOrIgnore([
                'actor_user_id' => $context['actor_user_id'], 'workspace_key' => $context['workspace_key'],
                'idempotency_key' => $data['idempotency_key'], 'payload_hash' => $hash,
                'status' => 'PROCESSING', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $idempotency = IdempotencyRecord::query()->where('actor_user_id', $context['actor_user_id'])
                ->where('workspace_key', $context['workspace_key'])->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->firstOrFail();
            if (! hash_equals($idempotency->payload_hash, $hash)) {
                throw new BookingConflictException('Idempotency-Key đã được dùng cho nội dung đặt lịch khác.');
            }
            $branch = Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $business = $branch->business()->lockForUpdate()->firstOrFail();
            $this->access->assertCurrentBranch($branch, $business, $context);
            if ($idempotency->status === 'COMPLETED' && $idempotency->booking_id) {
                $booking = Booking::whereKey($idempotency->booking_id)->where('branch_id', $branch->id)->where('business_id', $branch->business_id)->with(['services', 'contact', 'pricingSnapshot'])->firstOrFail();
                return ['booking' => $booking, 'replayed' => true];
            }
            $customerId = null;
            if ($context['kind'] === 'CUSTOMER') {
                $customer = CustomerProfile::whereKey($context['customer_profile_id'])->where('user_id', $context['actor_user_id'])->lockForUpdate()->firstOrFail();
                $customerId = $customer->id;
                $this->policy->assertSelfBookingAllowed($customerId, $branch->business_id, ($data['violation_acknowledged'] ?? false) === true);
            }
            $combo = $this->combos->resolveForBooking($branch, $combo->id, true);
            $combo->load(['items' => fn ($q) => $q->lockForUpdate()]);
            if($data['source_timeline']??false)$data=$this->resolveAuto($branch,$combo,$context,$data,true);
            $items = $this->plan($branch, $combo, $context, $data, $customerId, true);
            $allocations = $this->allocate(PromotionPricingService::cents($combo->combo_price), $items);
            $pricingItems = [];
            foreach ($items as $index => $item) {
                $pricingItems[] = ['branch_service_offering_id' => $item['quote']['offering']->id, 'allocated_amount' => PromotionPricingService::money($allocations[$index])];
            }
            $pricing = $this->promotions->quoteCombo($branch, $combo->id, $pricingItems, $customerId, $data['voucher_code'] ?? null, true);
            if ($customerId !== null) {
                $pricing['loyalty'] = $this->loyalty->preview($customerId, $branch, (int) ($data['loyalty_points'] ?? 0), $pricing['final_amount'], true);
                $loyaltyDiscount = PromotionPricingService::cents($pricing['loyalty']['discount_amount']);
                $pricing['discount_amount'] = PromotionPricingService::money(PromotionPricingService::cents($pricing['discount_amount']) + $loyaltyDiscount);
                $pricing['final_amount'] = PromotionPricingService::money(PromotionPricingService::cents($pricing['final_amount']) - $loyaltyDiscount);
            } elseif (($data['loyalty_points'] ?? 0) > 0) {
                throw new DomainException('Đổi điểm cần hồ sơ khách hàng.');
            }
            $source = match ($context['kind']) {
                'CUSTOMER' => 'ONLINE_WEB', 'OWNER' => 'STAFF_CREATED',
                'RECEPTIONIST' => in_array($data['source'] ?? null, ['WALK_IN', 'PHONE'], true) ? $data['source'] : throw new DomainException('Lễ tân phải chọn kênh WALK_IN hoặc PHONE.'),
                default => throw new DomainException('Vai trò không được phép tạo lịch.'),
            };
            $booking = Booking::create([
                'business_id' => $branch->business_id, 'branch_id' => $branch->id, 'customer_profile_id' => $customerId,
                'created_by_user_id' => $context['actor_user_id'], 'booking_code' => 'BB-'.now()->format('Ymd').'-'.Str::upper(Str::random(12)),
                'appointment_start_at' => $items[0]['window']['item_start_at'], 'appointment_end_at' => $items[array_key_last($items)]['window']['item_end_at'],
                'status' => 'PENDING', 'source' => $source, 'pending_expires_at' => now()->addMinutes((int) app(\App\Services\Platform\PlatformSettingsService::class)->value('pendingHoldMinutes')),
                'currency' => 'VND', 'total_amount' => $pricing['final_amount'], 'note' => $data['note'] ?? null,
            ]);
            $this->promotions->reserve($booking, $pricing);
            $this->loyalty->redeem($booking, $pricing);
            $snapshots = [];
            foreach ($items as $index => $item) {
                $quote = $item['quote'];
                $offering = $quote['offering'];
                $variant = $quote['variant'];
                $window = $item['window'];
                $allocated = PromotionPricingService::money($allocations[$index]);
                BookingService::create([
                    'booking_id' => $booking->id, 'business_id' => $branch->business_id, 'branch_id' => $branch->id,
                    'branch_service_offering_id' => $offering->id, 'business_service_id' => $offering->business_service_id,
                    'canonical_service_id' => $offering->service->canonical_service_id, 'staff_profile_id' => $item['staff_id'],
                    'service_variant_id' => $variant?->id, 'price_at_booking' => $allocated, 'currency' => 'VND',
                    'duration_minutes' => $quote['duration_minutes'], 'buffer_before_minutes' => $quote['buffer_before_minutes'],
                    'buffer_after_minutes' => $quote['buffer_after_minutes'], 'service_name_snapshot' => $offering->service->name,
                    'variant_name_snapshot' => $variant?->name, 'sort_order' => $index, 'status' => 'SCHEDULED', 'revision' => 1,
                    'item_start_at' => $window['item_start_at'], 'item_end_at' => $window['item_end_at'],
                    'occupied_start_at' => $window['occupied_start_at'], 'occupied_end_at' => $window['occupied_end_at'],
                ]);
                $snapshots[] = ['branch_service_offering_id' => $offering->id, 'business_service_id' => $offering->business_service_id,
                    'service_name' => $offering->service->name, 'service_variant_id' => $variant?->id, 'variant_name' => $variant?->name,
                    'staff_profile_id' => $item['staff_id'], 'unit_price' => $quote['unit_price'], 'allocated_amount' => $allocated,
                    'duration_minutes' => $quote['duration_minutes'], 'transition_minutes' => $item['transition_minutes'],
                    'sort_order' => $index, 'item_start_at' => $window['item_start_at']->toISOString(), 'item_end_at' => $window['item_end_at']->toISOString()];
            }
            app(\App\Services\Finance\FinancialPolicyService::class)->capturePolicy($booking);
            BookingPricingSnapshot::create([
                'booking_id' => $booking->id, 'currency' => 'VND', 'subtotal_amount' => $combo->combo_price,
                'discount_amount' => $pricing['discount_amount'], 'final_amount' => $pricing['final_amount'], 'quote_version' => 'combo-v1',
                'source_snapshot' => ['contact_consent'=>($data['contact_consent']??false)===true,'contact_consent_at'=>($data['contact_consent']??false)?now()->toIso8601String():null,'combo_id' => $combo->id, 'combo_version' => $combo->version, 'combo_name' => $combo->name,
                    'combo_price' => $combo->combo_price, 'staff_assignment_mode' => $combo->staff_assignment_mode,
                    'allocation_method' => 'PROPORTIONAL_CENTS_REMAINDER_LAST', 'items' => $snapshots, 'discount_rules' => $pricing['discount_rules'], 'loyalty' => $pricing['loyalty'] ?? null],
            ]);
            BookingStatusHistory::create(['booking_id' => $booking->id, 'actor_user_id' => $context['actor_user_id'], 'from_status' => null, 'to_status' => 'PENDING', 'reason' => 'COMBO_BOOKING_CREATED']);
            if ($customerId === null || ($data['contact_consent']??false)===true) {
                $contact = $data['contact'] ?? [];
                if (trim($contact['full_name'] ?? '') === '' || (trim($contact['phone'] ?? '') === '' && trim($contact['email'] ?? '') === '')) {
                    throw new DomainException('Đặt lịch vận hành cần tên khách và số điện thoại hoặc email.');
                }
                BookingContact::create(['booking_id' => $booking->id, 'full_name' => trim($contact['full_name']), 'phone' => $contact['phone'] ?? null, 'email' => $contact['email'] ?? null]);
            }
            DB::table('combo_booking_usages')->insert(['combo_id' => $combo->id, 'booking_id' => $booking->id, 'status' => 'RESERVED', 'created_at' => now(), 'updated_at' => now()]);
            $combo->increment('used_count');
            $idempotency->update(['status' => 'COMPLETED', 'booking_id' => $booking->id, 'http_status' => 201]);
            return ['booking' => $booking->load(['services', 'contact', 'pricingSnapshot']), 'replayed' => false];
        }, 3);
    }

    public function previewPrice(Branch $branch,Combo $combo,array $context,array $data):array
    {
        $combo=$this->combos->resolveForBooking($branch,$combo->id);
        $items=$this->plan($branch,$combo,$context,$data,$context['customer_profile_id']);
        $allocations=$this->allocate(PromotionPricingService::cents($combo->combo_price),$items);
        $pricingItems=[];foreach($items as $index=>$item)$pricingItems[]=['branch_service_offering_id'=>$item['quote']['offering']->id,'allocated_amount'=>PromotionPricingService::money($allocations[$index])];
        return $this->promotions->quoteCombo($branch,$combo->id,$pricingItems,$context['customer_profile_id'],$data['voucher_code']??null)+['subtotal_amount'=>$combo->combo_price,'duration_minutes'=>array_sum(array_map(fn($i)=>$i['quote']['duration_minutes'],$items)),'items'=>$pricingItems];
    }

    public function resolveAuto(Branch $branch,Combo $combo,array $context,array $data,bool $lock=false):array
    {
        if(!empty($data['staff_profile_id'])){if($combo->staff_assignment_mode==='PER_SERVICE_PROVIDER')foreach($combo->items as$item)$data['staff_assignments'][$item->branch_service_offering_id]=$data['staff_profile_id'];return $data;}
        $query=\App\Models\StaffProfile::where('branch_id',$branch->id)->where('business_id',$branch->business_id)->where('status','ACTIVE')->where('is_bookable',true)->when($context['kind']==='CUSTOMER',fn($q)=>$q->where('public_visible',true))->orderBy('id');
        if($lock)$query->lockForUpdate();$ids=$query->pluck('id');$failure=null;
        if($combo->staff_assignment_mode==='PER_SERVICE_PROVIDER'){
            $cursor=CarbonImmutable::parse($data['start_at'],$branch->timezone?:'Asia/Ho_Chi_Minh');$data['staff_assignments']=[];
            foreach($combo->items as$item){$quote=$this->quotes->quote($branch,$item->branch_service_offering_id,$data['variant_assignments'][$item->branch_service_offering_id]??null,$lock);$found=null;
                foreach($ids as$id){try{$staff=$this->availability->resolveStaff($branch,$quote['offering'],$id,$context['kind']==='CUSTOMER',$lock);$q=$quote;$q['duration_minutes']*=$item->quantity;$this->availability->check($branch,$staff,$q,$cursor->format('Y-m-d\TH:i'),$context['customer_profile_id'],$lock);$found=$id;break;}catch(DomainException $e){$failure=$e;}}
                if(!$found)throw $failure??new DomainException('Không có nhân viên phù hợp cho dịch vụ trong combo.');$data['staff_assignments'][$item->branch_service_offering_id]=$found;$cursor=$cursor->addMinutes($quote['duration_minutes']*$item->quantity+$item->transition_minutes);
            }
            return $data;
        }
        foreach($ids as$id){$candidate=$data+['staff_assignments'=>[],'variant_assignments'=>[]];$candidate['staff_profile_id']=$id;foreach($combo->items as$item)$candidate['staff_assignments'][$item->branch_service_offering_id]=$id;try{$this->plan($branch,$combo,$context,$candidate,$context['customer_profile_id'],$lock);return $candidate;}catch(DomainException $e){$failure=$e;}}
        throw $failure??new DomainException('Không còn nhân viên phù hợp cho toàn bộ combo.');
    }

    public function plan(Branch $branch, Combo $combo, array $context, array $data, ?int $customerId, bool $lock = false): array
    {
        $cursor = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['start_at'], $branch->timezone ?: 'Asia/Ho_Chi_Minh');
        $items = [];
        foreach ($combo->items as $comboItem) {
            $offeringId = $comboItem->branch_service_offering_id;
            $variantId = $data['variant_assignments'][$offeringId] ?? null;
            $staffId = $combo->staff_assignment_mode === 'SINGLE_PROVIDER' ? ($data['staff_profile_id'] ?? null) : ($data['staff_assignments'][$offeringId] ?? null);
            // Base quotes intentionally exclude customer promotions: the combo has one fixed subtotal.
            $quote = $this->quotes->quote($branch, $offeringId, $variantId, $lock,null,null,0,($data['source_timeline']??false)?$cursor:null,$staffId);
            if (! $staffId) {
                throw new DomainException('Chọn nhân viên cho tất cả dịch vụ trong combo.');
            }
            $staff = $this->availability->resolveStaff($branch, $quote['offering'], $staffId, $context['kind'] === 'CUSTOMER', $lock);
            for ($quantity = 0; $quantity < $comboItem->quantity; $quantity++) {
                $window = $this->availability->check($branch, $staff, $quote, $cursor->format('Y-m-d\TH:i'), $customerId, $lock);
                foreach ($items as $previous) {
                    if (!($data['source_timeline']??false) && $previous['staff_id'] === $staffId && $previous['window']['occupied_end_at']->greaterThan($window['occupied_start_at'])) {
                        throw new DomainException('Khoảng đệm của các dịch vụ trong combo bị trùng.');
                    }
                }
                $transition = $quantity === $comboItem->quantity - 1 ? $comboItem->transition_minutes : 0;
                $items[] = ['quote' => $quote, 'staff_id' => $staffId, 'window' => $window, 'transition_minutes' => $transition];
                $cursor = $cursor->addMinutes($quote['duration_minutes'] + $transition);
            }
        }
        return $items;
    }

    private function allocate(int $total, array $items): array
    {
        $weights = array_map(fn ($item) => PromotionPricingService::cents($item['quote']['unit_price']), $items);
        $weightTotal = array_sum($weights);
        if ($weightTotal === 0) {
            $weights = array_fill(0, count($items), 1);
            $weightTotal = count($items);
        }
        $amounts = [];
        $remaining = $total;
        foreach ($weights as $index => $weight) {
            $amount = $index === array_key_last($weights) ? $remaining : $this->proportion($total, $weight, $weightTotal);
            $amounts[] = $amount;
            $remaining -= $amount;
        }
        return $amounts;
    }

    private function proportion(int $total, int $weight, int $denominator): int
    {
        // Binary long division keeps cents exact without overflowing total * weight.
        $quotient = 0;
        $remainder = 0;
        foreach (str_split(decbin($weight)) as $bit) {
            $quotient *= 2;
            $remainder = $remainder * 2 + ($bit === '1' ? $total : 0);
            $quotient += intdiv($remainder, $denominator);
            $remainder %= $denominator;
        }
        return $quotient;
    }
}
