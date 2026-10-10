<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('beautybook_source_entities',function(Blueprint $table){
            $table->id();$table->string('entity_type',24);$table->unsignedBigInteger('local_id');$table->uuid('source_uuid');$table->json('metadata');$table->timestamps();
            $table->unique(['entity_type','source_uuid']);$table->unique(['entity_type','local_id']);
        });
    }
    public function down(): void {Schema::dropIfExists('beautybook_source_entities');}
};
