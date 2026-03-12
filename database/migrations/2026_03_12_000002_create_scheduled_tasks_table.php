<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->index()->comment('创建者 userid');
            $table->string('title')->comment('任务标题');
            $table->string('action_type')->comment('动作类型：send_group_message / send_user_message');
            $table->json('action_params')->comment('动作参数：{chatid, content, msg_type}');
            $table->string('schedule_type')->comment('调度类型：once / daily / weekdays / weekly / monthly');
            $table->string('execute_time', 5)->comment('执行时间 HH:mm');
            $table->json('schedule_config')->nullable()->comment('once={execute_date}, weekly={day_of_week:1-7}, monthly={day_of_month:1-31}');
            $table->dateTime('next_run_at')->nullable()->index()->comment('下次执行时间');
            $table->dateTime('last_run_at')->nullable()->comment('上次执行时间');
            $table->boolean('is_active')->default(true)->index()->comment('是否启用');
            $table->dateTime('expires_at')->nullable()->comment('过期时间');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_tasks');
    }
};
