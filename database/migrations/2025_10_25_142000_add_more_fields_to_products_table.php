<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('brand')->nullable()->after('name');
            $table->unsignedInteger('quantity')->default(0)->after('brand');
            $table->decimal('size', 10, 2)->nullable()->after('quantity');
            $table->unsignedBigInteger('size_unit_id')->nullable()->after('size');
            $table->decimal('weight', 10, 2)->nullable()->after('size_unit_id');
            $table->unsignedBigInteger('weight_unit_id')->nullable()->after('weight');
            $table->string('color')->nullable()->after('description');
            $table->text('tags')->nullable()->after('color');
            $table->boolean('cod_available')->default(true)->after('status');

            $table->index(['size_unit_id']);
            $table->index(['weight_unit_id']);
        });

        Schema::create('size_units', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->timestamps();
        });

        Schema::create('weight_units', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weight_units');
        Schema::dropIfExists('size_units');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['brand','quantity','size','size_unit_id','weight','weight_unit_id','color','tags','cod_available']);
        });
    }
};
