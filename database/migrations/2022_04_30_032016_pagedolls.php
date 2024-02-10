<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class Pagedolls extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Add columns for pagedolls / references your default pagedoll. If you prefer a png etc make sure to change the extension
        Schema::table('users', function (Blueprint $table) {
            $table->string('pagedoll')->default('default.png');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            //
            $table->dropColumn('pagedoll');
        });
    }
}