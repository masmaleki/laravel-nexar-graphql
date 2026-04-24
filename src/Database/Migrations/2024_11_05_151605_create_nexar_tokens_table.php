<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nexar_tokens')) {
            return;
        }

        Schema::create('nexar_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('client_id')->index();
            $table->string('client_secret');
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->text('supply_token');
            $table->string('scope')->default('supply.domain');
            $table->dateTime('expires_at')->nullable();
            $table->integer('expires_in')->default(3600);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nexar_tokens');
    }
};
