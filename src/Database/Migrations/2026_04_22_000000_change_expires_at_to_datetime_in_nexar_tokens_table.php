<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('nexar_tokens')) {
            return;
        }

        Schema::table('nexar_tokens', function (Blueprint $table) {
            $table->dateTime('expires_at')->change();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('nexar_tokens')) {
            return;
        }

        Schema::table('nexar_tokens', function (Blueprint $table) {
            $table->date('expires_at')->change();
        });
    }
};
