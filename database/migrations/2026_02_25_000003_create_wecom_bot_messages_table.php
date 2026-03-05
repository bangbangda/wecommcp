<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('wecom_bot_messages', function (Blueprint $table) {
            $table->id();
            $table->string('msgid')->unique();
            $table->string('aibotid');
            $table->string('chatid')->nullable();
            $table->string('chattype');
            $table->string('userid');
            $table->string('msgtype');
            $table->text('content');
            $table->string('stream_id')->unique();
            $table->text('response_url');
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wecom_bot_messages');
    }
};
