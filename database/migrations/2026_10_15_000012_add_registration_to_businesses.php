<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the business is registered, and with what.
 *
 * Four columns rather than a flag, because "is this a registered company" is
 * three questions once answered: are you registered, as what, and under which
 * number — and the certificate is the evidence for all three.
 *
 * **`is_registered` is nullable, and that is the point.** `false` means the
 * owner was asked and said no. `null` means nobody has asked yet, which is the
 * state every existing business is in and the state the setup flow reads to
 * decide whether this step is done. Collapsing the two would make every
 * business that predates this column look like it had answered.
 *
 * The other three are null when `is_registered` is false, and required when it
 * is true — enforced in the request, since a check constraint across four
 * columns is a thing to maintain in two places.
 *
 * **Why not `ownership_type_id` / `business_type_id`.** Those already exist,
 * they come from signup, and they answer a different question: how the business
 * describes itself versus how it is registered. A sole trader can be registered
 * as a Business Name; a limited company has one ownership type and one
 * registration type and they are not the same vocabulary.
 *
 * `registration_type` is a string rather than an FK to a lookup table. The
 * registry's categories are a closed set fixed by an external body, not a list
 * a business editor should be able to add to, and an enum in `App\Enums` says
 * that in one place instead of in a seeder and a table nobody joins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('is_registered')->nullable()->after('currency');
            $table->string('registration_type', 32)->nullable()->after('is_registered');
            $table->string('registration_number', 64)->nullable()->after('registration_type');
            $table->string('certificate_path')->nullable()->after('registration_number');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['is_registered', 'registration_type', 'registration_number', 'certificate_path']);
        });
    }
};
