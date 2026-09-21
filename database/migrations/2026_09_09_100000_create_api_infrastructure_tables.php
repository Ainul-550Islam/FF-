<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $guard = function (string $table, \Closure $create) {
            if (!Schema::hasTable($table)) {
                Schema::create($table, $create);
            }
        };
        // ------------------------------------------------------------------
        // api_clients — a user-owned "API application". Personal access
        // tokens are issued against a client and linked via
        // personal_access_tokens.api_client_id so admins can revoke an
        // entire application at once. Plaintext secrets are never stored;
        // tokens are stored only as their SHA-256 hash (Sanctum).
        // ------------------------------------------------------------------
        $guard('api_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->string('status', 20)->default('active'); // active | revoked
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id', 'api_clients_user_index');
            $table->index('status', 'api_clients_status_index');
        });

        // Link Sanctum tokens to their owning API client (nullable so
        // standalone tokens remain valid).
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->foreignId('api_client_id')->nullable()->after('tokenable_id')
                ->constrained('api_clients')->nullOnDelete();

            $table->index('api_client_id', 'pat_api_client_index');
        });

        // ------------------------------------------------------------------
        // api_idempotency_keys — request fingerprints for critical mutation
        // endpoints. A repeated request within the TTL replays the stored
        // response instead of executing again.
        // ------------------------------------------------------------------
        $guard('api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('key', 128);                    // SHA-256 of the client key
            $table->string('method', 10);
            $table->string('path', 255);
            $table->string('request_fingerprint', 128);    // SHA-256 of canonical body
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->json('response_body')->nullable();     // stored response (no secrets)
            $table->timestamp('created_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->unique(['user_id', 'key'], 'api_idempotency_user_key_unique');
            $table->index('expires_at', 'api_idempotency_expiry_index');
        });

        // ------------------------------------------------------------------
        // webhook_endpoints — approved third-party subscriptions (outbound).
        // The signing secret is stored encrypted; it is never returned by
        // the API after creation.
        // ------------------------------------------------------------------
        $guard('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('url', 500);
            $table->string('description', 255)->nullable();
            $table->string('status', 20)->default('active'); // active | disabled
            $table->text('secret_encrypted');
            $table->json('events');                           // subscribed event types
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();

            $table->index('status', 'webhook_endpoints_status_index');
        });

        // ------------------------------------------------------------------
        // webhook_deliveries — per-event delivery attempts (outbound).
        // ------------------------------------------------------------------
        $guard('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->string('event', 60);
            $table->string('delivery_id', 64)->unique();      // UUID, idempotent
            $table->json('payload');                          // redacted payload
            $table->string('signature', 128);
            $table->string('status', 20)->default('pending'); // pending | success | failed | disabled
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->unsignedSmallInteger('last_status_code')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index('endpoint_id', 'webhook_deliveries_endpoint_index');
            $table->index('status', 'webhook_deliveries_status_index');
        });

        // ------------------------------------------------------------------
        // webhook_events — signed inbound provider events. Raw payloads are
        // stored encrypted (never plaintext); safe metadata is stored
        // separately. Duplicate external event ids are idempotent.
        // ------------------------------------------------------------------
        $guard('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 30);
            $table->string('external_event_id', 128)->nullable();
            $table->string('event_type', 60);
            $table->string('signature_status', 20);           // verified | invalid | missing
            $table->string('status', 20)->default('received'); // received | verified | processing | processed | ignored | failed | replayed
            $table->unsignedInteger('attempts')->default(0);
            $table->text('payload_encrypted')->nullable();    // encrypted raw payload
            $table->json('metadata')->nullable();             // safe subset only
            $table->timestamp('received_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'external_event_id'], 'webhook_events_provider_event_unique');
            $table->index('status', 'webhook_events_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('api_idempotency_keys');
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropIndex('pat_api_client_index');
            $table->dropConstrainedForeignId('api_client_id');
        });
        Schema::dropIfExists('api_clients');
    }
};
