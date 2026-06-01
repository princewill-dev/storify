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
        Schema::table('vendors', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
        });

        // Create User accounts for existing vendors
        $this->migrateExistingVendors();
    }

    protected function migrateExistingVendors(): void
    {
        $vendors = DB::table('vendors')->whereNull('user_id')->get();

        foreach ($vendors as $vendor) {
            // Check if a User with this email already exists
            $existingUser = DB::table('users')->where('email', $vendor->email)->first();

            if ($existingUser) {
                DB::table('vendors')->where('id', $vendor->id)->update(['user_id' => $existingUser->id]);
            } else {
                $userId = DB::table('users')->insertGetId([
                    'uuid' => (string) Str::uuid(),
                    'name' => $vendor->name,
                    'email' => $vendor->email,
                    'phone' => $vendor->phone ?? null,
                    'role' => 'business_owner',
                    'status' => $vendor->status ?? 'active',
                    'is_verified' => $vendor->is_verified ?? false,
                    'email_verified_at' => $vendor->email_verified_at ?? null,
                    'last_login_at' => $vendor->last_login ?? null,
                    'location' => $vendor->location ?? null,
                    'ip_address' => $vendor->ip_address ?? null,
                    'password' => $vendor->password ?? bcrypt('temporary_' . Str::random(16)),
                    'created_at' => $vendor->created_at ?? now(),
                    'updated_at' => $vendor->updated_at ?? now(),
                ]);

                DB::table('vendors')->where('id', $vendor->id)->update(['user_id' => $userId]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
