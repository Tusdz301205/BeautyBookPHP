<?php
namespace App\Services\Beautybook;
use Illuminate\Support\Facades\DB;
class SourceReferenceMedia
{
    /** Request-owned snapshot; bind this service with scoped(), never singleton(). */
    private array $metadataCache = [];

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
        $connection = DB::connection();
        $entityType = strtoupper($entityType);
        $key = serialize([$connection->getName(), $connection->getDatabaseName(), $entityType, (string) $id]);
        if (array_key_exists($key, $this->metadataCache)) {
            return $this->metadataCache[$key];
        }

        $raw=$connection->table('beautybook_source_entities')->where('entity_type',$entityType)->where('local_id',$id)->value('metadata');
        return $this->metadataCache[$key] = $raw ? json_decode($raw,true,512,JSON_THROW_ON_ERROR):null;
    }
}
