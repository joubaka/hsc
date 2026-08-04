<?php

namespace App\Providers;

use App\Events\BookingCancelled;
use App\Events\BookingConfirmed;
use App\Services\CommunicationService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(BookingConfirmed::class, fn (BookingConfirmed $event) => app(CommunicationService::class)->bookingConfirmed($event->bookingId));
        Event::listen(BookingCancelled::class, fn (BookingCancelled $event) => app(CommunicationService::class)->bookingCancelled($event->bookingId));
    }
}
