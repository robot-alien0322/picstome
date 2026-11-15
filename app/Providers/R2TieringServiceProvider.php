<?php

namespace App\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** Eugene fork: daily R2 tiering run (galleries:tier --auto); does nothing unless PICSTOME_R2_TIERING. */
class R2TieringServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('galleries:tier --auto')
                ->dailyAt('03:30')
                ->withoutOverlapping(720)
                ->name('galleries:tier');
        });
    }
}
