<?php

namespace App\Services\Branches;

use App\Models\Branch;
use Illuminate\Support\Facades\DB;

class BranchLocation
{
    public static function same(array $left, array $right): bool
    {
        // MySQL JSON normalizes object key order; compare typed values, not order.
        ksort($left);
        ksort($right);

        return $left === $right;
    }

    public static function snapshot(Branch $branch): array
    {
        $metadata = json_decode(DB::table('beautybook_branch_settings')->where('branch_id', $branch->id)->value('metadata') ?? '{}', true);

        return [
            'addressLine' => $branch->address_line,
            'districtId' => $metadata['districtId'] ?? null,
            'ward' => $metadata['ward'] ?? null,
            'registrationType' => $branch->registration_type,
        ];
    }
}
