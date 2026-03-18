<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建汇报记录表
     * 存储从企微拉取的汇报详情，含解析后的纯文本内容
     */
    public function up(): void
    {
        Schema::create('journal_records', function (Blueprint $table) {
            $table->id();
            $table->string('journal_uuid')->unique()->comment('汇报记录 ID');
            $table->string('template_id')->comment('汇报表单 ID');
            $table->string('template_name')->nullable()->comment('汇报表单名称');
            $table->string('submitter_userid')->comment('提交者 userid');
            $table->string('submitter_name')->nullable()->comment('提交者姓名');
            $table->timestamp('report_time')->nullable()->comment('汇报时间');
            $table->text('content')->nullable()->comment('解析后的汇报纯文本内容');
            $table->json('raw_apply_data')->nullable()->comment('原始 apply_data 表单数据');
            $table->json('receivers')->nullable()->comment('汇报接收人 userid 列表');
            $table->unsignedInteger('comments_count')->default(0)->comment('评论数量');
            $table->timestamp('created_at')->nullable();

            $table->index(['submitter_userid', 'report_time']);
            $table->index(['template_id', 'report_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_records');
    }
};
