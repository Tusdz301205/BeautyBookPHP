<?php
namespace App\Services\Beautybook;
use Illuminate\Support\Facades\DB;
class SourceReferenceMedia
{
    /** Exact original illustration registry metadata; never treated as a real premises photo. */
    public function for(string $entityType,int|string $id): ?array
    {
        return $this->metadata($entityType,$id)['media'] ?? null;
    }

    public function reference(string $entityType,int|string $id): ?array
    {
        return $this->metadata($entityType,$id)['reference'] ?? null;
    }

    private function metadata(string $entityType,int|string $id): ?array
    {
        $raw=DB::table('beautybook_source_entities')->where('entity_type',strtoupper($entityType))->where('local_id',$id)->value('metadata');
        return $raw ? json_decode($raw,true,512,JSON_THROW_ON_ERROR):null;
    }
}
