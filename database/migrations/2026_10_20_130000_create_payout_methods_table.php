<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 23f (R249, R252): the payout-method registry and the tutor's preference.
 *
 * The rows are inserted here, not seeded, for the same reason as the payment gateways: rehearsal is
 * never re-seeded. `bank_transfer` starts active for every country (empty `countries`); the other
 * four start inactive until an admin enables them with countries. On `tutor_profiles` the bank
 * details stay in the existing `bank_*` columns; only the chosen method (a foreign key to the
 * registry's unique code, so a stray code can never be stored), the account email for the
 * email-based methods (encrypted by the model cast) and the time the details last changed are new.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_methods', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('is_active')->default(false);
            $table->jsonb('countries')->default('[]');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();

        // Listed explicitly, not from the enum: a later enum case must not change what this migration inserts.
        $methods = [
            ['bank_transfer', 'Bank transfer', true],
            ['payoneer', 'Payoneer', false],
            ['paypal', 'PayPal', false],
            ['stripe', 'Stripe', false],
            ['wise', 'Wise', false],
        ];

        DB::table('payout_methods')->insert(array_map(fn (array $method, int $index): array => [
            'code' => $method[0],
            'name' => $method[1],
            'is_active' => $method[2],
            'countries' => '[]',
            'sort' => $index + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], $methods, array_keys($methods)));

        Schema::table('tutor_profiles', function (Blueprint $table) {
            $table->string('payout_method')->nullable();
            $table->text('payout_email')->nullable();
            $table->timestamp('payout_details_changed_at')->nullable();

            $table->foreign('payout_method')->references('code')->on('payout_methods')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tutor_profiles', function (Blueprint $table) {
            $table->dropForeign(['payout_method']);
            $table->dropColumn(['payout_method', 'payout_email', 'payout_details_changed_at']);
        });

        Schema::dropIfExists('payout_methods');
    }
};
