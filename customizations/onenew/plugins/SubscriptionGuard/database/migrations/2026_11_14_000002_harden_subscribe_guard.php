<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up():void
    {
        if(Schema::hasColumn('v2_subscribe_log','token')){
            // SQLite cannot drop an indexed column until its indexes are removed.
            foreach(Schema::getIndexes('v2_subscribe_log') as $index){
                if(in_array('token',$index['columns'],true)&&!$index['primary'])Schema::table('v2_subscribe_log',fn(Blueprint $t)=>$t->dropIndex($index['name']));
            }
            Schema::table('v2_subscribe_log',fn(Blueprint $t)=>$t->dropColumn('token'));
        }
        if(!Schema::hasColumn('v2_subscribe_abuse','reviewed_at'))Schema::table('v2_subscribe_abuse',fn(Blueprint $t)=>$t->timestamp('reviewed_at')->nullable());
        if(!Schema::hasIndex('v2_subscribe_log','sub_guard_user_created'))Schema::table('v2_subscribe_log',fn(Blueprint $t)=>$t->index(['user_id','created_at'],'sub_guard_user_created'));
    }
    public function down():void
    {
        Schema::table('v2_subscribe_log',fn(Blueprint $t)=>$t->dropIndex('sub_guard_user_created'));
        // Never recreate the obsolete subscription-secret column.
    }
};
