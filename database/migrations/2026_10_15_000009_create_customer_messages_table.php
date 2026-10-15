<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbound messages a business sent to a customer.
 *
 * One row per recipient, not per campaign: a send is a thing that happened to
 * a person, and a customer's own history has to be able to show it. A campaign
 * table would answer "what did we send" and leave "what has this customer been
 * sent" to a join through a mapping table we do not need yet.
 *
 * Deliberately no open, click or bounce tracking. Those are their own product,
 * with their own consent and privacy consequences, and nothing here claims to
 * know whether a message was read — `sent_at` is when it was handed to the
 * queue.
 *
 * `category` is what decides whether consent applies: a marketing message may
 * only go to a customer who granted it, a transactional one (an order update,
 * a reply to a complaint) does not ask permission, because refusing to answer
 * a customer is not a privacy protection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            // The sender. Nullable and nullOnDelete so the record of what was
            // sent outlives the staff account that sent it.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('category', 20);
            $table->string('subject');
            $table->text('body');
            $table->string('status', 20)->default('queued');
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            // The Messages screen lists the business's recent sends; a
            // customer's own tab lists theirs.
            $table->index(['business_id', 'created_at']);
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_messages');
    }
};
