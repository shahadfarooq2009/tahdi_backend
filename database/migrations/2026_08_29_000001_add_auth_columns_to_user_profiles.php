<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('user_profiles', 'password')) {
                $table->string('password')->nullable();
            }

            if (! Schema::hasColumn('user_profiles', 'remember_token')) {
                $table->rememberToken();
            }

            if (! Schema::hasColumn('user_profiles', 'email_verified_at')) {
                $table->timestamp('email_verified_at')->nullable();
            }

            if (! Schema::hasColumn('user_profiles', 'google_id')) {
                $table->string('google_id')->nullable()->unique();
            }

            if (! Schema::hasColumn('user_profiles', 'auth_provider')) {
                $table->string('auth_provider', 32)->default('email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_profiles', function (Blueprint $table) {
            $columns = ['password', 'remember_token', 'email_verified_at', 'google_id', 'auth_provider'];

            foreach ($columns as $column) {
                if (Schema::hasColumn('user_profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
