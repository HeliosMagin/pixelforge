<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            // Our own id: used as the Pixelforge Idempotency-Key and echoed back in metadata.local_id.
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pixelforge_id')->nullable()->unique();

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            $table->string('status');
            $table->string('status_detail')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            // All money is integer nano-dollars (1e-9 USD).
            $table->json('rates');
            $table->bigInteger('estimated_nanos');
            $table->bigInteger('max_nanos');
            $table->json('usage')->nullable();
            $table->bigInteger('provider_cost_nanos')->nullable();
            $table->bigInteger('charged_nanos')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();

            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable()->index();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
