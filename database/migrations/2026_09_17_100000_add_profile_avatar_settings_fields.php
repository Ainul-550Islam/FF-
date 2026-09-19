<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'username')) {
                $table->string('username')->nullable()->unique()->after('name');
            }
            if (!Schema::hasColumn('users', 'display_name')) {
                $table->string('display_name')->nullable()->after('username');
            }
            if (!Schema::hasColumn('users', 'avatar_path')) {
                $table->string('avatar_path')->nullable()->after('phone');
            }
            if (!Schema::hasColumn('users', 'bio')) {
                $table->text('bio')->nullable()->after('avatar_path');
            }
            if (!Schema::hasColumn('users', 'date_of_birth')) {
                $table->date('date_of_birth')->nullable()->after('bio');
            }
            if (!Schema::hasColumn('users', 'gender')) {
                $table->string('gender', 20)->nullable()->after('date_of_birth');
            }
            if (!Schema::hasColumn('users', 'country')) {
                $table->string('country', 2)->nullable()->after('gender');
            }
            if (!Schema::hasColumn('users', 'timezone')) {
                $table->string('timezone')->default('Asia/Dhaka')->after('country');
            }
            if (!Schema::hasColumn('users', 'locale')) {
                $table->string('locale', 10)->default('en')->after('timezone');
            }
            if (!Schema::hasColumn('users', 'last_seen_at')) {
                $table->timestamp('last_seen_at')->nullable()->after('locale');
            }
            if (!Schema::hasColumn('users', 'username_changed_at')) {
                $table->timestamp('username_changed_at')->nullable()->after('last_seen_at');
            }
            if (!Schema::hasColumn('users', 'phone_verified_at')) {
                $table->timestamp('phone_verified_at')->nullable()->after('phone');
            }
            if (!Schema::hasColumn('users', 'is_banned')) {
                $table->boolean('is_banned')->default(false)->after('is_active');
            }
            if (!Schema::hasColumn('users', 'banned_at')) {
                $table->timestamp('banned_at')->nullable()->after('is_banned');
            }
            if (!Schema::hasColumn('users', 'ban_reason')) {
                $table->string('ban_reason')->nullable()->after('banned_at');
            }
        });

        // User identities for OAuth / connected accounts
        if (!Schema::hasTable('user_identities')) {
            Schema::create('user_identities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('provider')->index(); // google, phone, etc
                $table->string('provider_user_id')->index();
                $table->string('email')->nullable();
                $table->string('name')->nullable();
                $table->string('avatar')->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
                $table->unique(['provider', 'provider_user_id']);
            });
        }

        // Login history
        if (!Schema::hasTable('login_events')) {
            Schema::create('login_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('event')->default('login')->index(); // login, logout, failed, lockout
                $table->string('ip_address', 45)->nullable();
                $table->string('ip_hash', 64)->nullable()->index();
                $table->text('user_agent')->nullable();
                $table->string('device_hash', 64)->nullable();
                $table->string('device_label')->nullable();
                $table->string('location')->nullable();
                $table->boolean('successful')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'created_at']);
            });
        }

        // User sessions enhanced
        if (!Schema::hasTable('user_sessions')) {
            Schema::create('user_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('session_id')->unique();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->string('device_label')->nullable();
                $table->string('location')->nullable();
                $table->timestamp('last_active_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->boolean('is_current')->default(false);
                $table->boolean('is_revoked')->default(false);
                $table->timestamps();
                $table->index(['user_id', 'last_active_at']);
            });
        }

        // Payment methods for settings
        if (!Schema::hasTable('payment_methods')) {
            Schema::create('payment_methods', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('provider')->index(); // bkash, nagad, rocket, manual
                $table->string('label')->nullable(); // e.g. Personal bKash
                $table->string('masked_identifier')->nullable(); // masked phone/account
                $table->string('identifier_hash')->nullable()->index(); // hashed full identifier
                $table->boolean('is_default')->default(false);
                $table->boolean('is_verified')->default(false);
                $table->timestamp('verified_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'provider']);
            });
        }

        // OTP challenges for phone verification
        if (!Schema::hasTable('otp_challenges')) {
            Schema::create('otp_challenges', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('phone')->nullable()->index();
                $table->string('purpose')->default('login')->index(); // login, verify, register, payment
                $table->string('provider')->default('sms')->index();
                $table->string('code_hash');
                $table->string('reference')->nullable()->index();
                $table->integer('attempts')->default(0);
                $table->integer('max_attempts')->default(5);
                $table->timestamp('expires_at')->index();
                $table->timestamp('consumed_at')->nullable();
                $table->timestamps();
                $table->index(['phone', 'purpose', 'expires_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = ['username','display_name','avatar_path','bio','date_of_birth','gender','country','timezone','locale','last_seen_at','username_changed_at','phone_verified_at','is_banned','banned_at','ban_reason'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
        Schema::dropIfExists('user_identities');
        Schema::dropIfExists('login_events');
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('otp_challenges');
    }
};
