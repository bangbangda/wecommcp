<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建聊天分析配置表
     * key-value 结构，按 group 分组管理分析模块的运行参数
     */
    public function up(): void
    {
        Schema::create('chat_analysis_configs', function (Blueprint $table) {
            $table->id();
            $table->string('group')->comment('配置分组：schedule / ai / scope / lifecycle');
            $table->string('key')->comment('配置键');
            $table->json('value')->nullable()->comment('配置值（JSON）');
            $table->string('description')->nullable()->comment('配置说明');
            $table->timestamps();

            $table->unique(['group', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_analysis_configs');
    }
};
