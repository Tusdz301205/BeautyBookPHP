<?php

namespace App\Console\Commands;

use App\Services\Reviews\{AbsaClient, AbsaContract, AbsaException, AbsaStore};
use Illuminate\Console\Command;

class AbsaCheck extends Command
{
    protected $signature = 'absa:check';
    protected $description = 'Read-only check of review analysis store, model identity and inference service';
    public function handle(AbsaStore $store, AbsaClient $client): int
    {
        $ready = $store->ready();
        $this->line('Store: '.($ready ? 'installed' : 'missing'));
        $this->line('Worker configured: '.(config('absa.worker_enabled') ? 'enabled' : 'disabled'));
        if ($ready) $this->line(json_encode($store->current()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total','status')));
        try {
            $health = $client->health();
            AbsaContract::validate(['model'=>$health['model'] ?? null,'items'=>[]], []);
            $this->info('Service: ready; pinned PhoBERT Beauty v2 identity matches.');
        } catch (AbsaException $e) { $this->error($e->errorCode); return 1; }
        return $ready ? 0 : 1;
    }
}
