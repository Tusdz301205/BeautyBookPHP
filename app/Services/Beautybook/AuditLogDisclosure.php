<?php

namespace App\Services\Beautybook;

/**
 * Audit metadata may contain arbitrary request payloads, tokens or personal data.
 * Return only known, non-sensitive state fields to the audit UI.
 */
final class AuditLogDisclosure
{
    private const STATE_FIELDS = [
        'status', 'from_status', 'to_status', 'old_status', 'new_status',
        'state', 'from_state', 'to_state', 'action', 'version',
        'is_active', 'active', 'role', 'role_name',
    ];

    public static function changes(mixed $metadata): array
    {
        if (!is_array($metadata)) return ['reason' => null, 'oldData' => null, 'newData' => null];

        // A free-text reason can contain private details. Keep those in server-side audit storage.
        return [
            'reason' => null,
            'oldData' => self::safeState($metadata['before'] ?? null),
            'newData' => self::safeState($metadata['after'] ?? $metadata),
        ];
    }

    private static function safeState(mixed $data): ?array
    {
        if (!is_array($data)) return null;

        $filtered = [];
        foreach (self::STATE_FIELDS as $field) {
            if (!array_key_exists($field, $data)) continue;
            $value = $data[$field];
            if (is_scalar($value) || $value === null) $filtered[$field] = $value;
        }

        return $filtered ?: null;
    }
}
