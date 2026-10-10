<?php

namespace Tests\Feature;

use App\Models\{Business,BranchServiceOffering,StaffProfile};
use App\Services\Beautybook\SourceReferenceMedia;
use Database\Seeders\BeautybookSourceReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BeautybookSourceReferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_district_choices_contain_exact_source_province_relationships(): void
    {
        $fixture=json_decode(file_get_contents(database_path('data/beautybook-source-reference.json')),true,512,JSON_THROW_ON_ERROR);
        $rows=$this->getJson('/api/v1/branches/locations/districts')->assertOk()->json();
        $this->assertCount(array_sum(array_map(fn($province)=>count($province['districts']),$fixture['provinces'])),$rows);
        foreach($fixture['provinces'] as $province){
            foreach($province['districts'] as $name){
                $id=\App\Services\Beautybook\SourceDistrictCatalog::districtId($province['code'],$name);
                $row=collect($rows)->firstWhere('id',$id);
                $this->assertNotNull($row);
                $this->assertSame($name,$row['name']);
                $this->assertSame($province['name'],$row['province']['name']);
                $this->assertSame($province['code'],$row['province']['code']);
            }
        }
    }

    public function test_reference_import_preserves_demo_is_idempotent_and_maps_exact_illustrations(): void
    {
        $this->seed();
        $demo=Business::where('slug','beautybook-demo')->firstOrFail();
        $before=$demo->getRawOriginal();
        $oldOfferingIds=BranchServiceOffering::pluck('id')->all();
        $reference=json_decode(file_get_contents(database_path('data/beautybook-source-reference.json')),true,512,JSON_THROW_ON_ERROR);
        $this->seed(BeautybookSourceReferenceSeeder::class);
        $this->assertSame($before,$demo->fresh()->getRawOriginal());
        $this->assertSame(count($oldOfferingIds)+116,BranchServiceOffering::count());
        $this->assertSame(132,DB::table('beautybook_source_entities')->count());
        foreach(['businesses'=>'BUSINESS','branches'=>'BRANCH','offerings'=>'SERVICE'] as $collection=>$type){
            foreach($reference[$collection] as $entry){
                $map=DB::table('beautybook_source_entities')->where('entity_type',$type)->where('source_uuid',$entry['sourceUuid'])->first();
                $this->assertNotNull($map);
                // MySQL JSON normalizes object key order; compare every value and type after sorting object keys.
                $this->assertSame($this->ordered($entry['media']),$this->ordered(app(SourceReferenceMedia::class)->for($type,$map->local_id)));
                $this->assertSame($this->ordered($entry),$this->ordered(app(SourceReferenceMedia::class)->reference($type,$map->local_id)));
                $this->assertTrue($entry['media']['illustration']);
            }
        }
        $staff=StaffProfile::where('employee_code','like','SOURCE-%')->firstOrFail();
        $this->assertSame('ACTIVE',$staff->status);
        $this->assertTrue($staff->is_bookable);
        $this->assertGreaterThan(0,$staff->offerings()->count());
        $firstCapability=$staff->offerings()->firstOrFail();
        $staff->offerings()->detach($firstCapability->id);
        $staff->update(['full_name'=>'Reference staff subsequently edited']);
        $counts=['businesses'=>Business::count(),'offerings'=>BranchServiceOffering::count(),'staff'=>StaffProfile::count()];
        $this->seed(BeautybookSourceReferenceSeeder::class);
        $this->assertSame($counts,['businesses'=>Business::count(),'offerings'=>BranchServiceOffering::count(),'staff'=>StaffProfile::count()]);
        $this->assertSame('Reference staff subsequently edited',$staff->fresh()->full_name);
        $this->assertFalse($staff->offerings()->where('branch_service_offerings.id',$firstCapability->id)->exists());
        $this->assertSame($before,$demo->fresh()->getRawOriginal());
    }

    private function ordered(array $value): array
    {
        foreach($value as &$child)if(is_array($child))$child=$this->ordered($child);
        unset($child);
        if(!array_is_list($value))ksort($value);
        return $value;
    }
}
