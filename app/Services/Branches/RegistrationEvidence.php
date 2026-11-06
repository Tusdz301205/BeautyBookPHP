<?php

namespace App\Services\Branches;

use Illuminate\Support\Facades\DB;

class RegistrationEvidence
{
    public static function hasPdf(int $businessId, ?int $branchId): bool
    {
        return DB::table('beautybook_documents as documents')
            ->join('beautybook_document_versions as versions', 'versions.document_id', '=', 'documents.id')
            ->join('media_files as media', 'media.id', '=', 'versions.media_id')
            ->where('documents.business_id', $businessId)->where('documents.branch_id', $branchId)
            ->where('documents.status', 'ACTIVE')->whereIn('documents.document_type', $branchId === null ? ['BUSINESS_LICENSE'] : ['BUSINESS_LICENSE', 'OPERATING_LICENSE', 'LOCATION_DOCUMENT'])
            ->whereRaw('versions.version = (SELECT MAX(latest.version) FROM beautybook_document_versions latest WHERE latest.document_id = documents.id)')
            ->where('media.business_id', $businessId)->where('media.branch_id', $branchId)
            ->where('media.visibility', 'PRIVATE')->where('media.mime_type', 'application/pdf')
            ->where('media.entity_type', $branchId === null ? 'BUSINESS_DOCUMENT' : 'BRANCH_DOCUMENT')->exists();
    }
}
