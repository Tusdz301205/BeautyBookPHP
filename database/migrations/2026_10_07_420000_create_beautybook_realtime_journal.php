<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('beautybook_realtime_events',function(Blueprint $table) {
            $table->id(); $table->unsignedBigInteger('booking_id'); $table->unsignedBigInteger('business_id'); $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('customer_user_id')->nullable(); $table->string('event',32); $table->string('status',32)->nullable(); $table->timestamp('created_at',6)->useCurrent();
            $table->index(['business_id','id']); $table->index(['branch_id','id']); $table->index(['customer_user_id','id']);
        });
        // MySQL triggers record bulk Eloquent/query-builder writes in the same
        // transaction. Rolled-back mutations never enter the event stream.
        foreach(['INSERT'=>['NEW','booking_created'],'UPDATE'=>['NEW','booking_updated'],'DELETE'=>['OLD','booking_deleted']] as$operation=>[$row,$event]) {
            $eventSql=$operation==='UPDATE' ? "IF(NEW.deleted_at IS NOT NULL AND OLD.deleted_at IS NULL,'booking_deleted','booking_updated')" : "'$event'";
            DB::unprepared("CREATE TRIGGER bb_booking_".strtolower($operation)." AFTER $operation ON bookings FOR EACH ROW INSERT INTO beautybook_realtime_events (booking_id,business_id,branch_id,customer_user_id,event,status,created_at) VALUES ($row.id,$row.business_id,$row.branch_id,(SELECT user_id FROM customer_profiles WHERE id=$row.customer_profile_id),$eventSql,$row.status,UTC_TIMESTAMP(6))");
        }
        foreach(['INSERT'=>'NEW','UPDATE'=>'NEW','DELETE'=>'OLD'] as$operation=>$row) {
            DB::unprepared("CREATE TRIGGER bb_booking_service_".strtolower($operation)." AFTER $operation ON booking_services FOR EACH ROW INSERT INTO beautybook_realtime_events (booking_id,business_id,branch_id,customer_user_id,event,status,created_at) SELECT b.id,b.business_id,b.branch_id,c.user_id,'booking_updated',b.status,UTC_TIMESTAMP(6) FROM bookings b LEFT JOIN customer_profiles c ON c.id=b.customer_profile_id WHERE b.id=$row.booking_id");
        }
    }
    public function down(): void
    {
        foreach(['booking','booking_service'] as$table) foreach(['insert','update','delete'] as$operation) DB::unprepared("DROP TRIGGER IF EXISTS bb_{$table}_{$operation}");
        Schema::dropIfExists('beautybook_realtime_events');
    }
};
