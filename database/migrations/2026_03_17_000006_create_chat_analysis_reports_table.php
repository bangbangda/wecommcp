<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建聊天分析用户日报表（Layer 2）
     * 每个内部员工每天一份最终报告，用于推送
     */
    public function up(): void
    {
        Schema::create('chat_analysis_reports', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->comment('用户 userid');
            $table->string('user_name')->nullable()->comment('用户姓名');
            $table->date('date')->comment('报告日期');
            $table->text('report_content')->nullable()->comment('最终报告文本（推送内容）');
            $table->json('insights_snapshot')->nullable()->comment('当日所有洞察的快照');
            $table->timestamp('sent_at')->nullable()->comment('推送时间');
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_analysis_reports');
    }
};
