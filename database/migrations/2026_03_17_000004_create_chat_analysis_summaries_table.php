<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建聊天分析对话摘要表（Layer 1）
     * 每对会话搭档每天一条，存储 AI 分析后的结构化摘要
     */
    public function up(): void
    {
        Schema::create('chat_analysis_summaries', function (Blueprint $table) {
            $table->id();
            $table->date('date')->comment('分析日期');
            $table->string('user_a')->comment('对话方 A 的 userid（按字母序较小的）');
            $table->string('user_b')->comment('对话方 B 的 userid');
            $table->string('user_a_name')->nullable()->comment('A 的姓名');
            $table->string('user_b_name')->nullable()->comment('B 的姓名');
            $table->unsignedInteger('message_count')->default(0)->comment('当日消息条数');
            $table->text('summary')->nullable()->comment('对话概要（自然语言）');
            $table->json('raw_analysis')->nullable()->comment('AI 返回的完整结构化 JSON');
            $table->unsignedInteger('token_usage')->default(0)->comment('消耗的 token 数');
            $table->timestamp('created_at')->nullable();

            $table->unique(['date', 'user_a', 'user_b']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_analysis_summaries');
    }
};
