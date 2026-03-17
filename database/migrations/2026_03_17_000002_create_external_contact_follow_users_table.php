<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建外部联系人跟进关系表
     * 记录内部员工与外部联系人的添加/跟进关系
     */
    public function up(): void
    {
        Schema::create('external_contact_follow_users', function (Blueprint $table) {
            $table->id();
            $table->string('external_userid')->comment('外部联系人 userid');
            $table->string('userid')->comment('内部员工 userid');
            $table->string('remark')->nullable()->comment('员工对客户的备注名');
            $table->string('description')->nullable()->comment('员工对客户的描述/备注信息');
            $table->string('remark_corp_name')->nullable()->comment('备注的企业名称');
            $table->tinyInteger('add_way')->nullable()->comment('添加方式');
            $table->string('state')->nullable()->comment('渠道来源标识（联系我/获客链接的 state 参数）');
            $table->timestamp('follow_at')->nullable()->comment('添加时间');
            $table->timestamp('deleted_by_user_at')->nullable()->comment('员工删除客户的时间');
            $table->timestamp('deleted_by_customer_at')->nullable()->comment('客户删除员工的时间');
            $table->timestamps();

            $table->unique(['external_userid', 'userid']);
            $table->index('userid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_contact_follow_users');
    }
};
