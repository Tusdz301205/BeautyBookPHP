<?php

namespace Tests\Feature;

use App\Models\{Booking, Branch, Review, User};
use App\Services\Reviews\{AbsaClient, AbsaContract, AbsaException, AbsaStore, AbsaWorker};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Http};
use Tests\TestCase;

class AbsaWorkerTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\IdentityDemoSeeder::class);
        config(['absa.worker_enabled'=>true,'absa.token'=>str_repeat('x',32),'absa.service_url'=>'http://absa.test','absa.batch_size'=>4]);
        Http::preventStrayRequests();
    }
    private function review(string $text = 'Nhân viên tận tình, giá hợp lý.'): Review
    {
        $branch = Branch::where('slug','tan-tao')->firstOrFail();
        $customer = User::where('email','customer@beautybook.local')->firstOrFail()->customerProfile;
        $booking = Booking::create(['booking_code'=>'ML-'.bin2hex(random_bytes(5)),'customer_profile_id'=>$customer->id,'business_id'=>$branch->business_id,'branch_id'=>$branch->id,'status'=>'COMPLETED','source'=>'ONLINE_WEB','appointment_start_at'=>now()->subDay(),'appointment_end_at'=>now()->subDay()->addHour(),'total_amount'=>100000,'currency'=>'VND','created_by_user_id'=>$customer->user_id]);
        return Review::create(['booking_id'=>$booking->id,'customer_profile_id'=>$customer->id,'overall_rating'=>5,'comment'=>$text,'status'=>'APPROVED']);
    }
    private function response(array $ids): array
    {
        $m = AbsaContract::MODEL;
        return ['model'=>['id'=>$m['id'],'checkpoint_sha256'=>$m['checkpointSha256'],'taxonomy_version'=>$m['taxonomyVersion'],'preprocessing_version'=>$m['preprocessingVersion'],'aspects'=>AbsaContract::ASPECTS,'labels'=>AbsaContract::LABELS],
            'items'=>array_map(fn ($id) => ['id'=>(string)$id,'status'=>'ok','labels'=>array_fill_keys(AbsaContract::ASPECTS,'POS'),'probabilities'=>array_fill_keys(AbsaContract::ASPECTS,['NOT_MENTIONED'=>0.01,'POS'=>0.97,'NEG'=>0.01,'NEU'=>0.01]),'n_tokens'=>20,'truncated'=>false],$ids)];
    }
    public function test_enqueue_is_idempotent_and_star_only_review_is_not_queued(): void
    {
        $store = app(AbsaStore::class); $review = $this->review();
        $this->assertSame('QUEUED',$store->enqueue($review->id));
        $this->assertSame('EXISTS',$store->enqueue($review->id));
        $this->assertSame('NO_TEXT',$store->enqueue($this->review('  ')->id));
        $this->assertDatabaseCount('review_absa_analyses',1);
    }
    public function test_worker_calls_pinned_model_and_persists_predictions_without_logging_text(): void
    {
        $review = $this->review(); $store = app(AbsaStore::class); $store->enqueue($review->id);
        Http::fake(['absa.test/v1/absa/predict'=>Http::response($this->response([$review->id]))]);
        $result = app(AbsaWorker::class)->runBatch();
        $this->assertSame(['claimed'=>1,'completed'=>1,'failed'=>0,'lost'=>0],$result);
        $row = $store->current()->sole();
        $this->assertSame('COMPLETED',$row->status);$this->assertSame(hash('sha256',$review->comment),$row->text_sha256);
        $this->assertSame('POS',$row->service_quality);$this->assertNull($row->locked_until);
        Http::assertSent(fn ($r) => $r->hasHeader('X-ABSA-Token',str_repeat('x',32)) && $r['reviews'][0]['id']===(string)$review->id && $r['reviews'][0]['text']===$review->comment);
    }
    public function test_outage_backoff_retry_and_terminal_item_error(): void
    {
        $review = $this->review();$store=app(AbsaStore::class);$store->enqueue($review->id);
        $sequence=Http::fakeSequence()->pushStatus(503)->push($this->response([$review->id]));
        $first=app(AbsaWorker::class)->runBatch();$this->assertSame('SERVICE_ERROR',$first['serviceError']);
        $this->assertSame('FAILED',$store->current()->sole()->status);$this->assertTrue((bool)$store->current()->sole()->error_retryable);
        $this->assertSame(0,app(AbsaWorker::class)->runBatch()['claimed']);
        $this->travel(31)->seconds();$this->assertSame(1,app(AbsaWorker::class)->runBatch()['completed']);
        $other=$this->review('mới');$store->enqueue($other->id);$body=$this->response([$other->id]);$body['items']=[['id'=>(string)$other->id,'status'=>'error','error'=>['code'=>'TEXT_TOO_LONG']]];
        $sequence->push($body);$this->assertSame(1,app(AbsaWorker::class)->runBatch()['failed']);
        $this->assertFalse((bool)$store->current()->where('review_id',$other->id)->sole()->error_retryable);
    }
    public function test_expired_claims_and_manual_retry_cannot_be_overwritten_by_old_worker(): void
    {
        $review=$this->review();$store=app(AbsaStore::class);$store->enqueue($review->id);
        $old=$store->claim(4,10000,5)[0];$this->assertCount(0,$store->claim(4,10000,5));
        $this->travel(11)->seconds();$new=$store->claim(4,10000,5)[0];
        $item=$this->response([$review->id])['items'][0];
        $this->assertFalse($store->complete($old,$item,$review->comment));$this->assertFalse($store->fail($old,'SERVICE_ERROR',true,5));
        $this->assertTrue($store->fail($new,'TEXT_TOO_LONG',false,5));
        $this->artisan('absa:backfill',['--retry-failed'=>true,'--execute'=>true,'--expect'=>'1'])->assertSuccessful();
        $fresh=$store->claim(4,10000,5)[0];$this->assertSame($old['attempts'],$fresh['attempts']);
        $this->assertFalse($store->complete($old,$item,$review->comment));$this->assertTrue($store->complete($fresh,$item,$review->comment));
    }
    public function test_oversized_comment_does_not_poison_other_reviews_in_the_same_batch(): void
    {
        $long=$this->review(str_repeat('đ',4001));$valid=$this->review();$store=app(AbsaStore::class);
        $store->enqueue($long->id);$store->enqueue($valid->id);
        Http::fake(['*'=>Http::response($this->response([$valid->id]))]);
        $result=app(AbsaWorker::class)->runBatch();$this->assertSame(1,$result['completed']);$this->assertSame(1,$result['failed']);
        $this->assertSame('TEXT_TOO_LONG',$store->current()->where('review_id',$long->id)->sole()->error_code);
        Http::assertSent(fn ($r) => count($r['reviews'])===1 && $r['reviews'][0]['id']===(string)$valid->id);
    }
    public function test_response_validation_rejects_wrong_model_ids_probabilities_and_taxonomy(): void
    {
        $good=$this->response(['1']);$this->assertCount(1,AbsaContract::validate($good,['1']));
        $variants=[];$bad=$good;$bad['model']['checkpoint_sha256']=str_repeat('0',64);$variants[]=$bad;
        $bad=$good;$bad['items'][0]['id']='2';$variants[]=$bad;
        $bad=$good;$bad['items'][0]['probabilities']['PRICE']['POS']=1.1;$variants[]=$bad;
        $bad=$good;$bad['items'][0]['labels']['PRICE']='NEG';$variants[]=$bad;
        $bad=$good;$bad['model']['labels']=array_reverse(AbsaContract::LABELS);$variants[]=$bad;
        foreach($variants as $bad){try{AbsaContract::validate($bad,['1']);$this->fail('Invalid model output accepted');}catch(AbsaException $e){$this->assertContains($e->errorCode,['INVALID_RESPONSE','MODEL_IDENTITY_MISMATCH']);}}
    }
    public function test_disabled_or_bad_configuration_never_claims_jobs_and_backfill_is_dry_by_default(): void
    {
        $review=$this->review();$store=app(AbsaStore::class);
        $this->artisan('absa:backfill')->expectsOutput('Dry run; nothing written.')->assertSuccessful();$this->assertDatabaseCount('review_absa_analyses',0);
        $this->artisan('absa:backfill',['--execute'=>true,'--expect'=>'2'])->assertFailed();$this->assertDatabaseCount('review_absa_analyses',0);
        $store->enqueue($review->id);config(['absa.worker_enabled'=>false]);$this->assertTrue(app(AbsaWorker::class)->runBatch()['disabled']);
        config(['absa.worker_enabled'=>true,'absa.lease_ms'=>10000]);$this->assertSame('INVALID_CONFIGURATION',app(AbsaWorker::class)->runBatch()['serviceError']);$this->assertSame(0,$store->current()->sole()->attempts);
        Http::assertNothingSent();
    }
}
