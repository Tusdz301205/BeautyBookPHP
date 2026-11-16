<?php

namespace App\Console\Commands;

use App\Models\Review;
use App\Services\Reviews\{AbsaContract, AbsaStore};
use Illuminate\Console\Command;

class AbsaBackfill extends Command
{
    protected $signature = 'absa:backfill {--branch= : Limit to a local branch ID} {--retry-failed} {--execute} {--expect= : Exact count acknowledged before writing}';
    protected $description = 'Preview or enqueue missing current-model analyses; never overwrite completed results';
    public function handle(AbsaStore $store): int
    {
        if (!$store->ready()) { $this->error('STORE_NOT_INSTALLED'); return 1; }
        $branch = $this->option('branch');
        if ($branch !== null && (!ctype_digit($branch) || (int)$branch < 1)) { $this->error('Invalid local branch ID.'); return 1; }
        $reviews = Review::query()->whereRaw("TRIM(COALESCE(comment, '')) <> ''")
            ->when($branch, fn ($q) => $q->whereHas('booking', fn ($b) => $b->where('branch_id',$branch)));
        if ($this->option('retry-failed')) {
            $reviews->whereIn('id',$store->current()->where('status','FAILED')->where(fn ($q) => $q->where('error_retryable',false)->orWhere('attempts','>=',config('absa.max_attempts',5)))->select('review_id'));
        } else $reviews->whereNotIn('id',$store->current()->select('review_id'));
        $ids = $reviews->orderBy('id')->pluck('id');
        $this->line('Eligible reviews: '.$ids->count());
        if (!$this->option('execute')) { $this->line('Dry run; nothing written.'); return 0; }
        if ($this->option('expect') === null || !ctype_digit($this->option('expect')) || (int)$this->option('expect') !== $ids->count()) { $this->error('Exact --expect count required; nothing written.'); return 1; }
        $count = 0;
        foreach ($ids as $id) {
            if ($this->option('retry-failed')) {
                $count += $store->current()->where('review_id',$id)->where('status','FAILED')
                    ->where(fn ($q) => $q->where('error_retryable',false)->orWhere('attempts','>=',config('absa.max_attempts',5)))
                    ->update(['status'=>'PENDING','error_retryable'=>true,'attempts'=>0,'available_at'=>now('UTC'),'locked_until'=>null,'updated_at'=>now('UTC')]);
            } elseif ($store->enqueue($id,'BACKFILL') === 'QUEUED') $count++;
        }
        $this->info('Queued: '.$count);
        return 0;
    }
}
