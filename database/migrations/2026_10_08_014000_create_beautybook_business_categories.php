<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('beautybook_business_categories',function(Blueprint $table){
            $table->id();$table->foreignId('business_id')->constrained();$table->foreignId('service_category_id')->constrained();
            $table->foreignId('parent_category_id')->nullable()->constrained('service_categories');$table->string('source_slug');$table->timestamps();
            $table->unique(['business_id','service_category_id'],'bb_business_category_unique');$table->unique(['business_id','source_slug'],'bb_business_category_slug_unique');
        });
    }
    public function down(): void {Schema::dropIfExists('beautybook_business_categories');}
};
