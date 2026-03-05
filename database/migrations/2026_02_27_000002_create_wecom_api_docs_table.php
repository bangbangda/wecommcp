<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wecom_api_docs', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('doc_id')->unique()->comment('官方文档 ID');
            $table->unsignedInteger('category_id')->comment('分类 ID');
            $table->unsignedInteger('parent_id')->default(0)->comment('父节点 ID（用于构建树）');
            $table->string('title')->comment('文档标题');
            $table->string('category_path')->default('')->comment('完整分类路径');
            $table->longText('raw_content')->nullable()->comment('接口返回的原始 JSON');
            $table->longText('parsed_content')->nullable()->comment('解析后的正文内容');
            $table->tinyInteger('type')->default(0)->comment('0=分类节点, 1=文档页');
            $table->tinyInteger('status')->default(0)->comment('0=待抓取, 1=已抓取, 2=抓取失败');
            $table->timestamp('fetched_at')->nullable()->comment('最近抓取时间');
            $table->timestamps();

            $table->index('parent_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wecom_api_docs');
    }
};
