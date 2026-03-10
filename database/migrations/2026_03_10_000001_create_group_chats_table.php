<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('group_chats', function (Blueprint $table) {
            $table->id();
            $table->string('chatid')->unique()->comment('企微群聊 ID');
            $table->string('name')->nullable()->comment('群名称');
            $table->string('owner_userid')->comment('群主 userid');
            $table->string('creator_userid')->comment('创建者 userid');
            $table->json('userlist')->comment('全部群成员 userid 列表');
            $table->timestamps();
            $table->softDeletes();

            $table->index('owner_userid');
            $table->index('creator_userid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('group_chats');
    }
};
