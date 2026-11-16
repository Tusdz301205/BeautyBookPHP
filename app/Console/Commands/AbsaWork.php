<?php

namespace App\Console\Commands;

use App\Services\Reviews\{AbsaClient, AbsaException, AbsaWorker};
use Illuminate\Console\Command;

class AbsaWork extends Command
{
    protected $signature = 'absa:work {--once : Process one batch and exit}';
    protected $description = 'Process PhoBERT review analyses independently of HTTP requests';
    public function handle(AbsaWorker $worker, AbsaClient $client): int
    {
        if (!config('absa.worker_enabled')) { $this->line('ABSA worker disabled.'); return 0; }
        try { $settings = $client->settings(); }
        catch (AbsaException $e) { $this->error($e->errorCode); return 1; }
        do {
            try { $result = $worker->runBatch(); }
            catch (\Throwable) { $this->error('ABSA batch failed (STORE_ERROR).'); return 1; }
            if (($result['claimed'] ?? 0) || $this->option('once') || isset($result['serviceError'])) $this->line(json_encode($result));
            if ($this->option('once')) return isset($result['serviceError']) ? 1 : 0;
            if (($result['claimed'] ?? 0) < $settings['batch_size'] || isset($result['serviceError'])) usleep($settings['interval_ms']*1000);
        } while (true);
    }
}
