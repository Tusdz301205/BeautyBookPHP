<?php

namespace App\Services\Reviews;

final class AbsaContract
{
    public const ASPECTS = ['SERVICE_QUALITY', 'STAFF_ATTITUDE', 'WAIT_TIME', 'PRICE'];
    public const LABELS = ['NOT_MENTIONED', 'POS', 'NEG', 'NEU'];
    public const COLUMNS = ['SERVICE_QUALITY'=>'service_quality', 'STAFF_ATTITUDE'=>'staff_attitude', 'WAIT_TIME'=>'wait_time', 'PRICE'=>'price'];
    public const MODEL = ['id'=>'phobert-beauty-v2', 'checkpointSha256'=>'6146252c727eb76b966621f3496c422ba2590f782923386e300cf4dbb2f6b7cc', 'taxonomyVersion'=>'beauty-4-v1', 'preprocessingVersion'=>'prep-v1'];
    public const TERMINAL_ERRORS = ['EMPTY_TEXT','INVALID_TYPE','INVALID_ENCODING','INVALID_CONTROL_CHARACTER','TEXT_TOO_LONG','SEGMENTATION_EMPTY','NO_TEXT'];

    public static function validate(mixed $body, array $ids): array
    {
        if (!is_array($body) || !is_array($body['model'] ?? null) || !is_array($body['items'] ?? null)) throw new AbsaException('INVALID_RESPONSE');
        $model = $body['model'];
        foreach (['id'=>'id','checkpoint_sha256'=>'checkpointSha256','taxonomy_version'=>'taxonomyVersion','preprocessing_version'=>'preprocessingVersion'] as $key=>$expected) {
            if (($model[$key] ?? null) !== self::MODEL[$expected]) throw new AbsaException('MODEL_IDENTITY_MISMATCH');
        }
        if (($model['aspects'] ?? null) !== self::ASPECTS || ($model['labels'] ?? null) !== self::LABELS) throw new AbsaException('MODEL_IDENTITY_MISMATCH');
        if (count($body['items']) !== count($ids)) throw new AbsaException('INVALID_RESPONSE');
        $seen = [];
        foreach ($body['items'] as $item) {
            $id = $item['id'] ?? null;
            if (!is_string($id) || !in_array($id, $ids, true) || isset($seen[$id])) throw new AbsaException('INVALID_RESPONSE');
            $seen[$id] = true;
            if (($item['status'] ?? null) === 'error') {
                if (!is_string($item['error']['code'] ?? null) || !preg_match('/^[A-Z_]{3,48}$/D', $item['error']['code'])) throw new AbsaException('INVALID_RESPONSE');
                continue;
            }
            if (($item['status'] ?? null) !== 'ok' || !is_int($item['n_tokens'] ?? null) || $item['n_tokens'] < 1 || !is_bool($item['truncated'] ?? null)) throw new AbsaException('INVALID_RESPONSE');
            foreach (['labels','probabilities'] as $field) {
                $keys = array_keys(is_array($item[$field] ?? null) ? $item[$field] : []); sort($keys); $expected = self::ASPECTS; sort($expected);
                if ($keys !== $expected) throw new AbsaException('INVALID_RESPONSE');
            }
            foreach (self::ASPECTS as $aspect) {
                $probs = $item['probabilities'][$aspect];
                if (!is_array($probs)) throw new AbsaException('INVALID_RESPONSE');
                $keys = array_keys($probs); sort($keys); $expected = self::LABELS; sort($expected);
                if ($keys !== $expected) throw new AbsaException('INVALID_RESPONSE');
                $values = array_map(fn ($label) => $probs[$label], self::LABELS);
                foreach ($values as $value) if ((!is_float($value) && !is_int($value)) || !is_finite((float) $value) || $value < 0 || $value > 1) throw new AbsaException('INVALID_RESPONSE');
                if (abs(array_sum($values)-1) > 0.001 || self::LABELS[array_search(max($values), $values, true)] !== $item['labels'][$aspect]) throw new AbsaException('INVALID_RESPONSE');
            }
        }
        return $body['items'];
    }
}
