<?php

namespace App\Console\Commands;

use App\Models\Booking;
use App\Models\CommunicationCampaign;
use App\Models\CommunicationSetting;
use App\Services\CommunicationService;
use Illuminate\Console\Command;

class DispatchCommunications extends Command
{
    protected $signature = 'communications:dispatch';
    protected $description = 'Queue scheduled campaigns and upcoming booking reminders';
    public function handle(CommunicationService $service): int
    {
        $settings = CommunicationSetting::current();
        if (! $settings->enabled) {
            $this->info('Email communications are disabled.');
            return self::SUCCESS;
        }
        CommunicationCampaign::where('status', 'scheduled')->where('scheduled_at', '<=', now())->get()->each(fn ($campaign) => $service->queueCampaign($campaign));
        if ($settings->booking_reminders_enabled) {
            $target = now()->addHours($settings->booking_reminder_hours);
            Booking::with(['member', 'facility.venue'])->where('status', 'confirmed')->whereBetween('starts_at', [$target->copy()->subMinutes(15), $target->copy()->addMinutes(15)])->get()->each(fn ($booking) => $service->bookingReminder($booking));
        }
        $this->info('Scheduled communications dispatched.');
        return self::SUCCESS;
    }
}
