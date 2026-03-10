<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_module_configs', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->comment('企微 userid');
            $table->string('module')->comment('业务模块标识：schedule / meeting / meeting_room');
            $table->string('key')->comment('配置键名');
            $table->text('value')->comment('配置值');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'module', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_module_configs');
    }
};
