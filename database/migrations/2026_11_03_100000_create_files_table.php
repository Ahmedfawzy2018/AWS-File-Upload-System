<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_name', 255);
            $table->string('object_key', 512)->charset('ascii')->collation('ascii_bin')->unique();
            $table->string('mime_type', 127);
            $table->unsignedBigInteger('size_bytes');
            $table->char('checksum_sha256', 64)->nullable();
            $table->string('version_id', 255)->nullable();
            $table->string('etag', 64)->nullable();
            $table->string('status', 16);
            $table->string('upload_type', 16);
            $table->string('multipart_upload_id', 255)->nullable();
            $table->timestamp('multipart_completed_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('files');
    }
};
