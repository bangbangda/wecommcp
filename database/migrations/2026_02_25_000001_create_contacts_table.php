<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('userid')->unique();
            $table->string('name');
            $table->string('name_pinyin');
            $table->string('name_initials');
            $table->string('department')->nullable();
            $table->string('position')->nullable();
            $table->string('mobile')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();

            $table->index('name');
            $table->index('name_pinyin');
            $table->index('name_initials');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
