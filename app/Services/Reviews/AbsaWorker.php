<?php

namespace App\Services\Reviews;

use App\Models\Review;

class AbsaWorker
{
    public function __construct(private AbsaStore $store, private AbsaClient $client) {}
    public function runBatch(): array
    {
        $result = ['claimed'=>0,'completed'=>0,'failed'=>0,'lost'=>0];
        if (!config('absa.worker_enabled')) return $result + ['disabled'=>true];
        try { $c = $this->client->settings(); }
        catch (AbsaException $e) { return $result + ['serviceError'=>$e->errorCode]; }
        if (!$this->store->ready()) return $result + ['serviceError'=>'STORE_NOT_INSTALLED'];
        $claims = $this->store->claim($c['batch_size'],$c['lease_ms'],$c['max_attempts']);
        $result['claimed'] = count($claims);
        $reviews = Review::whereIn('id',array_column($claims,'reviewId'))->pluck('comment','id');
        $ready = [];
        $fail = function ($claim,$code,$retryable) use (&$result,$c) {
            $result[$this->store->fail($claim,$code,$retryable,$c['max_attempts']) ? 'failed' : 'lost']++;
        };
        foreach ($claims as $claim) {
            $text = $reviews[$claim['reviewId']] ?? null;
            if (is_string($text) && mb_strlen($text) > 4000) $fail($claim,'TEXT_TOO_LONG',false);
            elseif (is_string($text) && trim($text) !== '') $ready[] = ['claim'=>$claim,'text'=>$text];
            else $fail($claim,'NO_TEXT',false);
        }
        if (!$ready) return $result;
        try {
            $items = $this->client->predict(array_map(fn ($row) => ['id'=>$row['claim']['reviewId'],'text'=>$row['text']],$ready));
        } catch (AbsaException $e) {
            foreach ($ready as $row) $fail($row['claim'],$e->errorCode,$e->retryable);
            return $result + ['serviceError'=>$e->errorCode];
        }
        $items = array_column($items,null,'id');
        foreach ($ready as $row) {
            $item = $items[$row['claim']['reviewId']];
            if ($item['status'] === 'error') $fail($row['claim'],$item['error']['code'],!in_array($item['error']['code'],AbsaContract::TERMINAL_ERRORS,true));
            else $result[$this->store->complete($row['claim'],$item,$row['text']) ? 'completed' : 'lost']++;
        }
        return $result;
    }
}
