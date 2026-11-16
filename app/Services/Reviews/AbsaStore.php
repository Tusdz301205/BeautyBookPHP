<?php

namespace App\Services\Reviews;

use App\Models\Review;
use Illuminate\Support\Facades\{DB, Log, Schema};

class AbsaStore
{
    public function ready(): bool { return Schema::hasTable('review_absa_analyses'); }
    public function current()
    {
        return DB::table('review_absa_analyses')->where('model_id', AbsaContract::MODEL['id'])->where('checkpoint_sha256', AbsaContract::MODEL['checkpointSha256']);
    }
    public function enqueue(int $reviewId, string $source = 'REVIEW_CREATED'): string
    {
        try {
            if (!$this->ready()) return 'UNAVAILABLE';
            $review = Review::find($reviewId);
            if (!$review || trim($review->comment ?? '') === '') return 'NO_TEXT';
            $model = AbsaContract::MODEL;
            $inserted = DB::table('review_absa_analyses')->insertOrIgnore([
                'review_id'=>$reviewId, 'model_id'=>$model['id'], 'checkpoint_sha256'=>$model['checkpointSha256'],
                'taxonomy_version'=>$model['taxonomyVersion'], 'preprocessing_version'=>$model['preprocessingVersion'],
                'source'=>$source, 'available_at'=>now('UTC'), 'created_at'=>now('UTC'), 'updated_at'=>now('UTC'),
            ]);
            return $inserted ? 'QUEUED' : 'EXISTS';
        } catch (\Throwable) {
            Log::warning('ABSA enqueue failed; review is saved.');
            return 'ERROR';
        }
    }

    public function claim(int $limit, int $leaseMs, int $maxAttempts): array
    {
        return DB::transaction(function () use ($limit,$leaseMs,$maxAttempts) {
            $now = now('UTC');
            $this->current()->where('status','PROCESSING')->where('locked_until','<=',$now)->where('attempts','>=',$maxAttempts)
                ->update(['status'=>'FAILED','locked_until'=>null,'error_retryable'=>false,'error_code'=>'PROCESSING_TIMEOUT','error_detail'=>'Lease expired after the final attempt','updated_at'=>$now]);
            $rows = $this->current()->where('attempts','<',$maxAttempts)->where(function ($q) use ($now) {
                $q->where(fn ($p) => $p->where('status','PENDING')->where('available_at','<=',$now))
                    ->orWhere(fn ($p) => $p->where('status','FAILED')->where('error_retryable',true)->where('available_at','<=',$now))
                    ->orWhere(fn ($p) => $p->where('status','PROCESSING')->where('locked_until','<=',$now));
            })->orderBy('available_at')->orderBy('id')->limit($limit)->lock('FOR UPDATE SKIP LOCKED')->get();
            $claims = [];
            foreach ($rows as $row) {
                $token = (string) \Illuminate\Support\Str::uuid();
                $this->current()->where('id',$row->id)->update(['status'=>'PROCESSING','attempts'=>$row->attempts+1,'claim_token'=>$token,'locked_until'=>$now->copy()->addMilliseconds($leaseMs),'started_at'=>$now,'updated_at'=>$now]);
                $claims[] = ['id'=>$row->id,'reviewId'=>(string)$row->review_id,'attempts'=>$row->attempts+1,'token'=>$token];
            }
            return $claims;
        }, 3);
    }

    private function fenced(array $claim)
    {
        return $this->current()->where('id',$claim['id'])->where('status','PROCESSING')->where('attempts',$claim['attempts'])->where('claim_token',$claim['token']);
    }
    public function complete(array $claim, array $item, string $text): bool
    {
        $data = ['status'=>'COMPLETED','probabilities'=>json_encode($item['probabilities'], JSON_THROW_ON_ERROR),'n_tokens'=>$item['n_tokens'],'truncated'=>$item['truncated'],'text_sha256'=>hash('sha256',$text),'completed_at'=>now('UTC'),'locked_until'=>null,'error_code'=>null,'error_detail'=>null,'error_retryable'=>true,'updated_at'=>now('UTC')];
        foreach (AbsaContract::COLUMNS as $aspect=>$column) $data[$column] = $item['labels'][$aspect];
        return $this->fenced($claim)->update($data) === 1;
    }
    public function fail(array $claim, string $code, bool $retryable, int $maxAttempts): bool
    {
        $retryable = $retryable && $claim['attempts'] < $maxAttempts;
        $delay = $retryable ? min(30000 * 2 ** max(0,$claim['attempts']-1), 1800000) : 0;
        return $this->fenced($claim)->update(['status'=>'FAILED','locked_until'=>null,'error_code'=>preg_match('/^[A-Z_]{3,48}$/D',$code) ? $code : 'UNKNOWN_ERROR','error_detail'=>$code,'error_retryable'=>$retryable,'available_at'=>now('UTC')->addMilliseconds($delay),'updated_at'=>now('UTC')]) === 1;
    }
}
