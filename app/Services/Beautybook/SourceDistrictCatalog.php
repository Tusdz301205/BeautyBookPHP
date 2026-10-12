<?php

namespace App\Services\Beautybook;

use Illuminate\Support\Str;

class SourceDistrictCatalog
{
    /** Memoized immutable fixture rows; bind this service with scoped(), never singleton(). */
    private ?array $rows = null;

    /** All geographic choices are copied from original seed.ts; identifiers are stable port identifiers. */
    public function all(): array
    {
        if ($this->rows !== null) {
            return $this->rows;
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
