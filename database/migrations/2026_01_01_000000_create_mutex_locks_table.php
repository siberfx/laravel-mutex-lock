<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Only needed for SQLite connections when "mutex-lock.sqlite.auto_create_table" is disabled.
 * MySQL/MariaDB and PostgreSQL use native advisory locks and need no table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection(config('mutex-lock.connection'))->create(config('mutex-lock.sqlite.table', 'mutex_locks'), function (Blueprint $table) {
            $table->string('name')->primary();
            $table->string('token', 64);
            $table->double('expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection(config('mutex-lock.connection'))->dropIfExists(config('mutex-lock.sqlite.table', 'mutex_locks'));
    }
};
