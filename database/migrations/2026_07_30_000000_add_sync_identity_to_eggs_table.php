<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class AddSyncIdentityToEggsTable extends Migration
{
    public function up()
    {
        Schema::table('eggs', function (Blueprint $table) {
            $table->string('sync_key', 128)->nullable()->after('nest_id');
            $table->char('sync_hash', 64)->nullable()->after('sync_key');
            $table->unique(['nest_id', 'sync_key'], 'eggs_nest_id_sync_key_unique');
        });
    }

    public function down()
    {
        Schema::table('eggs', function (Blueprint $table) {
            $table->dropUnique('eggs_nest_id_sync_key_unique');
            $table->dropColumn(['sync_key', 'sync_hash']);
        });
    }
}
