<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recent_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('summary');
            $table->text('description')->nullable();
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->json('attendees')->nullable();
            $table->string('creator_userid');
            $table->string('schedule_id')->nullable();
            $table->string('cal_id')->nullable();
            $table->string('location')->nullable();
            $table->json('api_request')->nullable()->comment('企微 API 请求参数');
            $table->json('api_response')->nullable()->comment('企微 API 返回结果');
            $table->timestamps();
            $table->softDeletes();

            $table->index('creator_userid');
            $table->index('start_time');
            $table->index('schedule_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recent_schedules');
    }
};
