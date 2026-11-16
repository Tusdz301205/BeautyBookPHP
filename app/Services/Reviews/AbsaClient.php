<?php

namespace App\Services\Reviews;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

class AbsaClient
{
    public function settings(): array
    {
        $c = config('absa');
        foreach (['interval_ms'=>[1000,3600000], 'batch_size'=>[1,32], 'timeout_ms'=>[1000,600000], 'max_attempts'=>[1,20], 'lease_ms'=>[10000,3600000]] as $key=>[$min,$max]) {
            if (filter_var($c[$key], FILTER_VALIDATE_INT) === false || $c[$key] < $min || $c[$key] > $max) throw new AbsaException('INVALID_CONFIGURATION', false);
            $c[$key] = (int) $c[$key];
        }
        $url = parse_url($c['service_url']);
        if ($c['lease_ms'] <= $c['timeout_ms'] || !$url || !in_array($url['scheme'] ?? '', ['http','https'], true) || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || strlen($c['token']) < 24) throw new AbsaException('INVALID_CONFIGURATION', false);
        return $c;
    }

    private function request(string $path, ?array $body = null): mixed
    {
        $c = $this->settings();
        try {
            $http = Http::withHeaders(['X-ABSA-Token'=>$c['token']])->acceptJson()->timeout($c['timeout_ms']/1000)->connectTimeout(min(10, $c['timeout_ms']/1000))->withoutRedirecting();
            $response = $body === null ? $http->get(rtrim($c['service_url'], '/').$path) : $http->post(rtrim($c['service_url'], '/').$path, $body);
        } catch (ConnectionException $e) {
            throw new AbsaException(str_contains($e->getMessage(), 'cURL error 28') ? 'SERVICE_TIMEOUT' : 'SERVICE_UNAVAILABLE');
        }
        if ($response->successful()) return $response->json();
        if ($response->status() === 401) throw new AbsaException('SERVICE_UNAUTHORIZED');
        if ($response->status() >= 400 && $response->status() < 500) throw new AbsaException('SERVICE_REJECTED_REQUEST', false);
        throw new AbsaException($response->json('error.code') === 'SEGMENTER_FAILED' ? 'SEGMENTER_FAILED' : 'SERVICE_ERROR');
    }

    public function health(): mixed { return $this->request('/health'); }
    public function predict(array $rows): array { return AbsaContract::validate($this->request('/v1/absa/predict', ['reviews'=>$rows]), array_column($rows, 'id')); }
}
