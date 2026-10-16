<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The management console's own theme, beside the till's.
 *
 * `users.theme_preference` already exists and is the **POS terminal's** theme.
 * It defaults to `dark`, which is right for a till and wrong for a back office:
 * reusing it would put every existing console user into dark on their first
 * load, without their having chosen anything, because the column's default is a
 * decision the terminal made for them.
 *
 * ## Why two columns rather than one
 *
 * They are different questions. A shopkeeper standing at a counter in a dim shop
 * and the same person reconciling books at a desk are two settings, and one
 * column cannot hold both without one of them being wrong. It also means the
 * console toggle cannot change what a till looks like — the failure mode of a
 * shared column, and one nobody would diagnose quickly.
 *
 * ## `system`, not `light`
 *
 * The default is the machine's own preference, which is what a reader who has
 * never chosen should get: a dark-mode laptop opens a dark console. Storing
 * `light` would be the app deciding for them, and would also stop the page
 * following the machine at sunset. Nullable is not used — `system` says the same
 * thing and needs no second meaning for null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('management_theme', 16)->default('system')->after('theme_preference');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('management_theme');
        });
    }
};
