<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建外部联系人主表
     * 存储企微外部联系人（客户）的基础信息
     */
    public function up(): void
    {
        Schema::create('external_contacts', function (Blueprint $table) {
            $table->id();
            $table->string('external_userid')->unique()->comment('外部联系人 userid（wo/wm 开头）');
            $table->string('name')->comment('姓名');
            $table->string('name_pinyin')->nullable()->comment('姓名拼音（用于搜索）');
            $table->string('name_initials')->nullable()->comment('姓名首字母（用于搜索）');
            $table->string('avatar')->nullable()->comment('头像 URL');
            $table->tinyInteger('type')->default(0)->comment('类型：1=微信用户, 2=企业微信用户');
            $table->tinyInteger('gender')->default(0)->comment('性别：0=未知, 1=男, 2=女');
            $table->string('corp_name')->nullable()->comment('所在企业简称');
            $table->string('corp_full_name')->nullable()->comment('所在企业全称');
            $table->string('position')->nullable()->comment('职位');
            $table->string('unionid')->nullable()->comment('微信 unionid');
            $table->timestamps();

            $table->index('name');
            $table->index('name_pinyin');
            $table->index('name_initials');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_contacts');
    }
};
