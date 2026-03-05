<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recent_meetings', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->dateTime('start_time');
            $table->integer('duration_minutes')->default(60);
            $table->json('invitees');
            $table->string('creator_userid');
            $table->string('meetingid')->nullable();
            $table->json('api_request')->nullable()->comment('企微 API 请求参数');
            $table->json('api_response')->nullable()->comment('企微 API 返回结果');
            $table->timestamps();
            $table->softDeletes();

            $table->index('creator_userid');
            $table->index('start_time');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recent_meetings');
    }
};
