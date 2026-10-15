<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The applicant's name, split; a dialling code; and a postcode.
 *
 * **Why the name is split when `legal_name` already holds it.** The single
 * field was collected as "Full legal name — as on your ID", which is fine for a
 * person to type and useless to anything that has to compare it. A provider
 * onboarding an account wants the three parts separately, and splitting a free
 * text field downstream is guessing at where the boundaries are. The three new
 * columns are what is asked for now; `legal_name` is **kept**, written as the
 * three joined, because the admin review queue, its resource and every existing
 * test read it, and a compliance record whose display name changes shape
 * underneath the reviewers is a worse outcome than one redundant column.
 *
 * All four name columns are nullable here and required at the request. Existing
 * rows have a `legal_name` and no parts, and a migration that invented parts for
 * them would be inventing a fact about a person's name.
 *
 * `phone_country_code` is a column rather than a convention over `phone_number`
 * for the same reason: `+2348031234567` and `08031234567` are the same number
 * written two ways, and a field that has to be parsed to be used is a field
 * that will be parsed wrongly at least once.
 *
 * `date_of_birth` and `selfie_image_path` are deliberately untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kyc_applications', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('legal_name');
            $table->string('middle_name')->nullable()->after('first_name');
            $table->string('last_name')->nullable()->after('middle_name');
            $table->string('phone_country_code', 8)->nullable()->after('phone_number');
            $table->string('postal_code', 20)->nullable()->after('state');
        });
    }

    public function down(): void
    {
        Schema::table('kyc_applications', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'middle_name', 'last_name', 'phone_country_code', 'postal_code']);
        });
    }
};
