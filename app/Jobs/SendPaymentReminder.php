<?php

namespace App\Jobs;

use App\Mail\UpcomingDeliveryReminder;
use App\Models\FamilyPackDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPaymentReminder implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $type; // 'pre_delivery', 'delivery_day', 'overdue'

    /**
     * Create a new job instance.
     */
    public function __construct(string $type = 'pre_delivery')
    {
        $this->type = $type;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $today = now()->startOfDay();
        
        $query = FamilyPackDelivery::where('status', 'pending')
            ->whereHas('familyPackOrder', function($q) {
                $q->where('status', \App\Enums\FamilyPackStatus::ACTIVE);
            });

        if ($this->type === 'pre_delivery') {
            // Remind 1 day before
            $targetDate = $today->copy()->addDay();
            $deliveries = $query->whereDate('scheduled_date', $targetDate)
                ->whereNull('payment_reminder_sent_at')
                ->get();
                
        } elseif ($this->type === 'delivery_day') {
            // Remind on delivery day
            $deliveries = $query->whereDate('scheduled_date', $today)
                ->whereNull('payment_due_reminder_sent_at')
                ->get();
                
        } elseif ($this->type === 'overdue') {
            // Remind 1 day after if still pending
            $targetDate = $today->copy()->subDay();
            $deliveries = $query->whereDate('scheduled_date', $targetDate)
                ->whereNull('payment_overdue_reminder_sent_at')
                ->get();
        } else {
            return;
        }

        foreach ($deliveries as $delivery) {
            try {
                Mail::to($delivery->familyPackOrder->customer->email)
                    ->queue(new UpcomingDeliveryReminder($delivery, $this->type));

                // Update timestamp
                if ($this->type === 'pre_delivery') {
                    $delivery->update(['payment_reminder_sent_at' => now()]);
                } elseif ($this->type === 'delivery_day') {
                    $delivery->update(['payment_due_reminder_sent_at' => now()]);
                } elseif ($this->type === 'overdue') {
                    $delivery->update(['payment_overdue_reminder_sent_at' => now()]);
                }

                Log::info("Sent {$this->type} reminder for delivery #{$delivery->id}");

            } catch (\Exception $e) {
                Log::error("Failed to send {$this->type} reminder for delivery #{$delivery->id}: " . $e->getMessage());
            }
        }
    }
}
