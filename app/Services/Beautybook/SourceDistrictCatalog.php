<?php

namespace App\Services\Beautybook;

use Illuminate\Support\Str;

class SourceDistrictCatalog
{
    /** Request-scoped geographic choices from the completed import or the original fixture. */
    private ?array $rows = null;

    /** Keep imported administrative identities; use the seed fixture before a full import exists. */
    public function all(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
        }

        if (\Illuminate\Support\Facades\Schema::hasTable('glowme_import_runs')
            && \Illuminate\Support\Facades\DB::table('glowme_import_runs')->where('status', 'COMPLETE')->exists()) {
            $ids = \Illuminate\Support\Facades\DB::table('glowme_import_ids')->whereIn('source_table', ['public.provinces', 'public.districts'])->get()->groupBy('source_table');
            $provinceIds = ($ids['public.provinces'] ?? collect())->pluck('local_id', 'source_id');
            $districtIds = ($ids['public.districts'] ?? collect())->pluck('local_id', 'source_id');
            $provinces = [];
            foreach (\Illuminate\Support\Facades\DB::table('glowme_import_rows')->where('source_table', 'public.provinces')->pluck('payload') as $payload) {
                $row = json_decode($payload, true, 512, JSON_THROW_ON_ERROR); $provinces[$row['id']] = $row;
            }
            $rows = [];
            foreach (\Illuminate\Support\Facades\DB::table('glowme_import_rows')->where('source_table', 'public.districts')->pluck('payload') as $payload) {
                $row = json_decode($payload, true, 512, JSON_THROW_ON_ERROR); $province = $provinces[$row['province_id']];
                $rows[] = ['id' => (string) $districtIds[$row['id']], 'name' => $row['name'], 'provinceId' => (string) $provinceIds[$province['id']], 'province' => ['id' => (string) $provinceIds[$province['id']], 'name' => $province['name'], 'code' => $province['code']]];
            }
            // GlowMe's new administrative model has wards directly under provinces and no districts.
            if (! $rows) {
                $wardIds = \Illuminate\Support\Facades\DB::table('glowme_import_ids')->where('source_table', 'public.wards')->pluck('local_id', 'source_id');
                foreach (\Illuminate\Support\Facades\DB::table('glowme_import_rows')->where('source_table', 'public.wards')->pluck('payload') as $payload) {
                    $row = json_decode($payload, true, 512, JSON_THROW_ON_ERROR); $province = $provinces[$row['province_id']];
                    $rows[] = ['id' => 'glowme-ward-'.$wardIds[$row['id']], 'name' => $row['name'], 'level' => 'WARD', 'wardCode' => $row['code'], 'provinceId' => (string) $provinceIds[$province['id']], 'province' => ['id' => (string) $provinceIds[$province['id']], 'name' => $province['name'], 'code' => $province['code']]];
                }
            }
            return $this->rows = $rows;
        }

        $fixture=json_decode(file_get_contents(database_path('data/beautybook-source-reference.json')),true,512,JSON_THROW_ON_ERROR);
        $rows=[];
        foreach($fixture['provinces'] as $province){
            foreach($province['districts'] as $name){
                $rows[]=['id'=>self::districtId($province['code'],$name),'name'=>$name,'provinceId'=>'source-'.Str::slug($province['code']),'province'=>['id'=>'source-'.Str::slug($province['code']),'name'=>$province['name'],'code'=>$province['code']]];
            }
        }
        return $this->rows = $rows;
    }

    public static function districtId(string $provinceCode,string $name): string
    {
        return 'source-'.Str::slug($provinceCode.'-'.$name);
    }
}
