<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wipe_fresh', fn (Blueprint $table) => $table->id());
    }

    public function down(): void
    {
        Schema::dropIfExists('wipe_fresh');
    }
};
