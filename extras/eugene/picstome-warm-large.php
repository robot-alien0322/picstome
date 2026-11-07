<?php
// Pre-warms the large viewer image on the image CDN (wsrv.nl) so clients don't
// wait 1-3 s on the first view of each photo. Run by systemd timer picstome-warm.
// Not part of Picstome itself, so app updates don't touch it.
// State: /var/lib/picstome-warm/warmed.json (photo id:updated_at -> time warmed)
chdir("/var/www/picstome");
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$stateFile = "/var/lib/picstome-warm/warmed.json";
$state = is_file($stateFile) ? (json_decode(file_get_contents($stateFile), true) ?: []) : [];

$urls = [];
foreach (App\Models\Photo::query()->where("status", "processed")->orderByDesc("id")->limit(2000)->get() as $p) {
    $key = $p->id . ":" . ($p->updated_at?->timestamp ?? 0);
    if (isset($state[$key]) || ! $p->isImage()) continue;
    $u = App\Services\PhotoUrlService::make($p)->url();
    if ($u) $urls[$key] = $u;
}

$ok = 0; $fail = 0;
foreach (array_chunk($urls, 4, true) as $batch) {
    $mh = curl_multi_init(); $hs = [];
    foreach ($batch as $key => $u) {
        $h = curl_init($u);
        curl_setopt_array($h, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90,
            CURLOPT_HTTPHEADER => ["Accept: image/webp,image/*"]]);
        curl_multi_add_handle($mh, $h); $hs[] = [$key, $h];
    }
    do { curl_multi_exec($mh, $running); curl_multi_select($mh); } while ($running);
    foreach ($hs as [$key, $h]) {
        if (curl_getinfo($h, CURLINFO_HTTP_CODE) === 200) { $state[$key] = time(); $ok++; } else { $fail++; }
        curl_multi_remove_handle($mh, $h); curl_close($h);
    }
    curl_multi_close($mh);
}
file_put_contents($stateFile, json_encode($state));
if ($ok || $fail) echo "warmed $ok, failed $fail\n";
