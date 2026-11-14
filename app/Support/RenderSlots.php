<?php

namespace App\Support;

/**
 * Eugene fork: at most N image renders at once, shared by web requests and queue workers
 * (file locks in storage/app/img-cache). One slot made the two queue workers wait on each
 * other; unlimited let bursts of full-size renders starve the VM. Default 2 (fork.render_slots).
 */
class RenderSlots
{
    public static function run(callable $work): mixed
    {
        $dir = storage_path('app/img-cache');

        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $slots = max(1, (int) config('fork.render_slots', 2));

        while (true) {
            for ($i = 0; $i < $slots; $i++) {
                $lock = fopen($dir.'/.render.lock.'.$i, 'c');

                if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
                    try {
                        return $work();
                    } finally {
                        flock($lock, LOCK_UN);
                        fclose($lock);
                    }
                }

                if ($lock) {
                    fclose($lock);
                }
            }

            usleep(200_000);
        }
    }
}
