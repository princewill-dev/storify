<?php

namespace App\Jobs;

use App\Models\FamilyPackDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CancelUnpaidDelivery implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Cancel deliveries that are 2 days past schedule and still pending
        $thresholdDate = now()->subDays(2)->startOfDay();

        $overdueDeliveries = FamilyPackDelivery::where('status', 'pending')
            ->whereDate('scheduled_date', '<=', $thresholdDate)
            ->get();

        foreach ($overdueDeliveries as $delivery) {
            try {
                $delivery->update([
                    'status' => 'cancelled',
                    'notes' => ($delivery->notes ? $delivery->notes . "\n" : "") . "Auto-cancelled due to inactivity/non-payment.",
                ]);

                Log::info("Auto-cancelled overdue delivery #{$delivery->id}");
                
                // Optional: Notify customer of cancellation

            } catch (\Exception $e) {
                Log::error("Failed to cancel delivery #{$delivery->id}: " . $e->getMessage());
            }
        }
    }
}
