<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add staff fields to users
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'invitation_token')) $table->string('invitation_token', 100)->nullable()->unique()->after('remember_token');
            if (!Schema::hasColumn('users', 'invited_at')) $table->timestamp('invited_at')->nullable()->after('invitation_token');
            if (!Schema::hasColumn('users', 'accepted_at')) $table->timestamp('accepted_at')->nullable()->after('invited_at');
            if (!Schema::hasColumn('users', 'force_password_change')) $table->boolean('force_password_change')->default(false)->after('accepted_at');
        });

        // 2. Migrate staff to users (if staff table still exists)
        $oldToNew = []; // old_staff_id => new_user_id
        if (Schema::hasTable('staff')) {
            if (!Schema::hasTable('staff_migration_map')) {
                Schema::create('staff_migration_map', function (Blueprint $table) {
                    $table->unsignedBigInteger('old_staff_id');
                    $table->unsignedBigInteger('new_user_id');
                });
            }

            $staffMembers = DB::table('staff')->get();
            foreach ($staffMembers as $s) {
                $existing = DB::table('users')->where('email', $s->email)->first();
                if ($existing) {
                    DB::table('users')->where('id', $existing->id)->update([
                        'invitation_token' => $s->invitation_token,
                        'invited_at' => $s->invited_at,
                        'accepted_at' => $s->accepted_at,
                        'last_login_at' => $s->last_login_at,
                        'force_password_change' => $s->force_password_change ?? false,
                        'password' => $s->password ?? $existing->password,
                        'status' => $s->status ?? $existing->status,
                        'phone' => $s->phone ?? $existing->phone,
                    ]);
                    $userId = $existing->id;
                } else {
                    $userId = DB::table('users')->insertGetId([
                        'uuid' => (string) Str::uuid(),
                        'name' => $s->name,
                        'email' => $s->email,
                        'phone' => $s->phone,
                        'password' => $s->password ?? bcrypt(Str::random(16)),
                        'role' => 'staff',
                        'status' => $s->status ?? 'active',
                        'is_verified' => true,
                        'email_verified_at' => now(),
                        'invitation_token' => $s->invitation_token,
                        'invited_at' => $s->invited_at,
                        'accepted_at' => $s->accepted_at,
                        'last_login_at' => $s->last_login_at,
                        'force_password_change' => $s->force_password_change ?? false,
                        'created_at' => $s->created_at ?? now(),
                        'updated_at' => $s->updated_at ?? now(),
                    ]);
                }

                $oldToNew[$s->id] = $userId;
                DB::table('staff_migration_map')->insert([
                    'old_staff_id' => $s->id,
                    'new_user_id' => $userId,
                ]);
            }
        }

        // 3. Update staff_assignments FK (if column still named staff_id)
        if (Schema::hasTable('staff_assignments') && Schema::hasColumn('staff_assignments', 'staff_id')) {
            Schema::disableForeignKeyConstraints();
            try { DB::statement('ALTER TABLE staff_assignments DROP FOREIGN KEY staff_assignments_staff_id_foreign'); } catch (\Throwable $e) {}
            DB::statement('ALTER TABLE staff_assignments CHANGE staff_id user_id BIGINT UNSIGNED NOT NULL');
            Schema::enableForeignKeyConstraints();
            try { Schema::table('staff_assignments', fn ($t) => $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete()); } catch (\Throwable $e) {}
        }

        // 4. Remap staff_assignments.user_id to new user IDs
        if (Schema::hasTable('staff_assignments') && Schema::hasTable('staff_migration_map')) {
            $assignments = DB::table('staff_assignments')->get();
            foreach ($assignments as $a) {
                $map = DB::table('staff_migration_map')->where('old_staff_id', $a->user_id)->first();
                if ($map) {
                    DB::table('staff_assignments')->where('id', $a->id)->update(['user_id' => $map->new_user_id]);
                }
            }
        }

        // 5. Update tables that reference staff_id (point FK to users)
        $staffRefTables = ['orders', 'pos_sessions'];
        foreach ($staffRefTables as $tb) {
            if (Schema::hasColumn($tb, 'staff_id')) {
                try { DB::statement("ALTER TABLE {$tb} DROP FOREIGN KEY {$tb}_staff_id_foreign"); } catch (\Throwable $e) {}
                try { Schema::table($tb, fn ($t) => $t->foreign('staff_id')->references('id')->on('users')->nullOnDelete()); } catch (\Throwable $e) {}
            }
        }
        // Remap staff_id values
        foreach ($staffRefTables as $tb) {
            if (Schema::hasColumn($tb, 'staff_id') && Schema::hasTable('staff_migration_map')) {
                $records = DB::table($tb)->whereNotNull('staff_id')->get();
                foreach ($records as $r) {
                    $map = DB::table('staff_migration_map')->where('old_staff_id', $r->staff_id)->first();
                    if ($map) {
                        DB::table($tb)->where('id', $r->id)->update(['staff_id' => $map->new_user_id]);
                    }
                }
            }
        }

        // 6. Cleanup
        Schema::dropIfExists('staff_role');
        Schema::dropIfExists('staff');
        Schema::dropIfExists('staff_migration_map');

        // 7. Drop old custom roles table (only if it has old schema)
        if (Schema::hasTable('roles') && Schema::hasColumn('roles', 'abilities')) {
            Schema::disableForeignKeyConstraints();
            Schema::dropIfExists('roles');
            Schema::enableForeignKeyConstraints();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_migration_map');
    }
};
