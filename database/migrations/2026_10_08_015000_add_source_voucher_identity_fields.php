<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{Schema,DB};
use Illuminate\Support\Str;
return new class extends Migration {
    public function up(): void {
        Schema::table('customer_profiles',fn(Blueprint $table)=>$table->uuid('beautybook_reference')->nullable()->unique());
        DB::table('customer_profiles')->whereNull('beautybook_reference')->orderBy('id')->chunkById(100,function($profiles){foreach($profiles as $profile)DB::table('customer_profiles')->where('id',$profile->id)->update(['beautybook_reference'=>(string)Str::uuid()]);});
        Schema::table('customer_business_segments',fn(Blueprint $table)=>$table->foreignId('assigned_by_user_id')->nullable()->constrained('users'));
    }
    public function down(): void {
        Schema::table('customer_business_segments',fn(Blueprint $table)=>$table->dropConstrainedForeignId('assigned_by_user_id'));
        Schema::table('customer_profiles',function(Blueprint $table){$table->dropUnique(['beautybook_reference']);$table->dropColumn('beautybook_reference');});
    }
};
