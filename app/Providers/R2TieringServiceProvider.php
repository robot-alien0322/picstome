<?php

namespace App\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** Eugene fork: hourly R2 tiering (galleries:tier --auto, needs PICSTOME_R2_TIERING) and client ZIPs prepared ahead of time. */
class R2TieringServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('galleries:tier --auto')
                ->hourlyAt(30)
                ->withoutOverlapping(720)
                ->name('galleries:tier');

            $schedule->command('galleries:prepare-downloads --auto')
                ->everyFifteenMinutes()
                ->withoutOverlapping(30)
                ->name('galleries:prepare-downloads');
        });
    }
}
