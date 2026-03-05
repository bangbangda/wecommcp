<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_memories', function (Blueprint $table) {
            $table->id();
            $table->string('user_id')->comment('企微 userid');
            $table->string('module')->comment('模块标识：preferences/relationships/schedule/general');
            $table->string('content', 500)->comment('原子事实');
            $table->string('source')->default('explicit')->comment('来源：explicit（用户明确要求）/ inferred（统计推断）');
            $table->unsignedInteger('hit_count')->default(0)->comment('注入 prompt 次数');
            $table->timestamp('last_hit_at')->nullable()->comment('最近命中时间');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_memories');
    }
};
