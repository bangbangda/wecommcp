<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建聊天分析结构化洞察表
     * 存储从对话中提取的待办、决策、时间节点、未回复等事项，含生命周期状态
     */
    public function up(): void
    {
        Schema::create('chat_analysis_insights', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['todo', 'decision', 'deadline', 'pending'])->comment('洞察类型');
            $table->string('owner_userid')->comment('责任人 userid');
            $table->string('owner_name')->nullable()->comment('责任人姓名');
            $table->string('content')->comment('事项内容');
            $table->enum('priority', ['high', 'medium', 'low'])->nullable()->comment('优先级（仅 todo）');
            $table->enum('status', ['open', 'completed', 'expired', 'reminded', 'ignored'])
                ->default('open')->comment('生命周期状态');
            $table->string('source_userid')->nullable()->comment('对话中的另一方 userid');
            $table->string('source_name')->nullable()->comment('对话中的另一方姓名');
            $table->date('source_date')->comment('发现日期');
            $table->date('deadline_date')->nullable()->comment('截止日期（仅 deadline 类型）');
            $table->text('context')->nullable()->comment('原文摘要，便于追溯');
            $table->unsignedBigInteger('summary_id')->nullable()->comment('关联的对话摘要 ID');
            $table->timestamp('completed_at')->nullable()->comment('完成时间');
            $table->timestamps();

            $table->index(['owner_userid', 'status', 'type']);
            $table->index('source_date');
            $table->index('summary_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_analysis_insights');
    }
};
