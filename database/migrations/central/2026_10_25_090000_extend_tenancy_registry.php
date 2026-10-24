<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('tenancy.central_connection');
    }

    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('plan')->default('standard')->after('name');
        });

        Schema::table('tenant_domains', function (Blueprint $table) {
            $table->timestamp('tls_issued_at')->nullable()->after('verified_at');
        });

        // Who administers a tenant. The login itself lives in the tenant's own database; this is the registry's record
        // of the contact, so the super-admin can reach a tenant without opening its database.
        Schema::create('tenant_admins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->timestamps();

            $table->unique(['tenant_id', 'email']);
        });

        Schema::create('tenant_terms_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('version');
            $table->timestamp('accepted_at');
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_terms_acceptances');
        Schema::dropIfExists('tenant_admins');

        Schema::table('tenant_domains', function (Blueprint $table) {
            $table->dropColumn('tls_issued_at');
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('plan');
        });
    }
};
