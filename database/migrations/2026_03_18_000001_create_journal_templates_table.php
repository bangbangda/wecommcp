<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建汇报模板配置表
     * 存储企微汇报表单的 template_id 及类型配置
     */
    public function up(): void
    {
        Schema::create('journal_templates', function (Blueprint $table) {
            $table->id();
            $table->string('template_id')->unique()->comment('企微汇报表单 ID');
            $table->string('template_name')->comment('模板名称（日报/周报/月报等）');
            $table->enum('report_type', ['daily', 'weekly', 'monthly', 'other'])->default('other')->comment('汇报类型');
            $table->boolean('is_active')->default(true)->comment('是否启用拉取');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_templates');
    }
};
