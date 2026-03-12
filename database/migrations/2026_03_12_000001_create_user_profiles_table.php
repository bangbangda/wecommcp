<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->unique()->comment('企微 userid，一人一行');
            $table->string('bot_name', 50)->nullable()->comment('机器人昵称，如"小微"');
            $table->string('user_nickname', 50)->nullable()->comment('用户希望被怎么称呼，如"老板"');
            $table->text('persona')->nullable()->comment('AI 润色后的人设描述');
            $table->text('greeting_template')->nullable()->comment('开场白模板，支持 {nickname}/{weekday} 变量');
            $table->text('catchphrases')->nullable()->comment('常用语/口头禅，换行分隔');
            $table->text('taboos')->nullable()->comment('禁忌项，换行分隔');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};
