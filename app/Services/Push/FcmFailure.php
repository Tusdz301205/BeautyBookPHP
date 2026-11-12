<?php

namespace App\Services\Push;

class FcmFailure extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly bool $retryable, public readonly bool $invalidToken = false, public readonly int $retryAfterSeconds = 0)
    {
        parent::__construct($errorCode);
    }

    /** INVALID_ARGUMENT may mean a bad payload, so only UNREGISTERED revokes the device. */
    public static function fromResponse(int $status, mixed $body, ?string $retryAfter): self
    {
        $code = null;
        foreach ((array) data_get($body, 'error.details', []) as $detail) {
            if (is_array($detail) && is_string($detail['errorCode'] ?? null)) { $code = $detail['errorCode']; break; }
        }
        $after = 0;
        if ($retryAfter !== null) $after = ctype_digit($retryAfter) ? (int) $retryAfter : max(0, (strtotime($retryAfter) ?: time()) - time());

        return new self($code === 'UNREGISTERED' ? 'UNREGISTERED' : 'FCM_'.$status, $status === 429 || $status >= 500, $code === 'UNREGISTERED', min(86400, $after));
    }
}
