<?php
/*
 * Picstome "collection" landing page.
 * One private page per Photoshoot listing its SHARED galleries as cards.
 * URL: /c/<token>   token = first 16 hex of HMAC-SHA256("collection:<photoshoot id>", APP_KEY)
 * Lives outside the Picstome repo (/var/www/picstome-collections) so app updates don't touch it.
 * Read-only: it never writes to Picstome's database or storage.
 */
chdir('/var/www/picstome');
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Photoshoot;
use App\Services\PhotoUrlService;

function collection_token(int $id): string {
    return substr(hash_hmac('sha256', 'collection:' . $id, (string) config('app.key')), 0, 16);
}
function e($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function notfound(): void {
    http_response_code(404);
    echo '<!doctype html><meta charset="utf-8"><title>Not found</title><body style="font-family:system-ui;background:#F3F1EC;color:#1A1712;display:grid;place-items:center;height:100vh;margin:0"><p>This collection link isn&rsquo;t valid. Please check the link you were sent.</p></body>';
    exit;
}

$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (! preg_match('#^/c/([a-f0-9]{16})/?$#', (string) $path, $m)) notfound();
$token = $m[1];

$shoot = null;
foreach (Photoshoot::query()->select('id')->get() as $s) {
    if (hash_equals(collection_token($s->id), $token)) { $shoot = Photoshoot::find($s->id); break; }
}
if (! $shoot) notfound();

$galleries = $shoot->galleries()->where('is_shared', true)
    ->where(fn ($q) => $q->whereNull('expiration_date')->orWhere('expiration_date', '>=', now()->toDateString()))
    ->orderBy('name')->get();
if ($galleries->isEmpty()) notfound();

$cards = []; $heroUrl = null;
foreach ($galleries as $g) {
    $cover = $g->coverPhoto ?: $g->photos()->where('status', 'processed')->orderBy('id')->first();
    $thumb = $cover ? PhotoUrlService::make($cover)->largeThumbnail() : '';
    if (! $heroUrl && $cover) $heroUrl = PhotoUrlService::make($cover)->url();
    $cards[] = [
        'name'   => $g->name,
        'url'    => route('shares.show', ['gallery' => $g->ulid, 'slug' => $g->slug]),
        'thumb'  => $thumb,
        'count'  => $g->photos()->count(),
        'locked' => ! empty($g->share_password),
    ];
}
$team = $shoot->team;
$studio = $team?->name ? (preg_match("/'s Studio$/", $team->name) ? preg_replace("/'s Studio$/", '', $team->name) . ' Photography' : $team->name) : 'Photography';
$sub = array_filter([$shoot->date ? \Illuminate\Support\Carbon::parse($shoot->date)->format('F j, Y') : null, $shoot->location]);
header('X-Robots-Tag: noindex, nofollow');
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($shoot->name) ?> &mdash; <?= e($studio) ?></title>
<style>
:root{--ground:#F3F1EC;--ink:#1A1712;--dim:#4C4940;--muted:#8C877A;--line:rgba(26,23,18,.15);--accent:#A2611C;
--sans:"Source Sans 3","Source Sans Pro","Helvetica Neue",Helvetica,Arial,system-ui,sans-serif;--mono:ui-monospace,"SF Mono",Menlo,"Roboto Mono",monospace}
*{box-sizing:border-box}body{margin:0;background:var(--ground);color:var(--ink);font-family:var(--sans);-webkit-font-smoothing:antialiased}
a{color:inherit;text-decoration:none}
.top{display:flex;justify-content:space-between;align-items:center;padding:18px clamp(18px,4vw,48px);font-family:var(--mono);font-size:.7rem;letter-spacing:.14em;text-transform:uppercase;color:var(--dim)}
.hero{position:relative;height:min(62vh,620px);min-height:320px;overflow:hidden;background:#222}
.hero img{width:100%;height:100%;object-fit:cover;object-position:50% 25%;display:block}
.hero:after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(0,0,0,0) 35%,rgba(0,0,0,.62))}
.hero .t{position:absolute;left:clamp(18px,4vw,48px);right:clamp(18px,4vw,48px);bottom:clamp(22px,5vh,48px);z-index:1;color:#F3F1EC}
.eyebrow{font-family:var(--mono);font-size:.7rem;letter-spacing:.14em;text-transform:uppercase;opacity:.85;margin:0 0 .6rem}
h1{font-weight:600;letter-spacing:-.015em;line-height:.98;font-size:clamp(2.2rem,6vw,4.6rem);margin:0}
.sub{margin:.8rem 0 0;font-size:1.05rem;opacity:.9}
main{max-width:1240px;margin:0 auto;padding:clamp(28px,5vw,56px) clamp(18px,4vw,48px) 80px}
.head{display:flex;justify-content:space-between;align-items:baseline;border-top:1px solid rgba(26,23,18,.32);padding-top:1rem;margin-bottom:1.6rem;font-family:var(--mono);font-size:.7rem;letter-spacing:.12em;text-transform:uppercase;color:var(--muted)}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:clamp(16px,2.4vw,28px)}
.card .img{aspect-ratio:3/2;overflow:hidden;background:#ddd8cc}
.card img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .6s cubic-bezier(.2,.7,.2,1)}
.card:hover img{transform:scale(1.035)}
.card h2{font-size:1.25rem;font-weight:600;margin:.85rem 0 .2rem;letter-spacing:-.005em}
.meta{font-family:var(--mono);font-size:.7rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted)}
.meta .go{color:var(--accent)}
footer{text-align:center;font-family:var(--mono);font-size:.68rem;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);padding:0 0 48px}
</style></head><body>
<div class="top"><span><?= e($studio) ?></span><span><?= count($cards) ?> galler<?= count($cards) === 1 ? 'y' : 'ies' ?></span></div>
<section class="hero"><?php if ($heroUrl): ?><img src="<?= e($heroUrl) ?>" alt="<?= e($shoot->name) ?>"><?php endif; ?>
<div class="t"><p class="eyebrow">Collection</p><h1><?= e($shoot->name) ?></h1><?php if ($sub): ?><p class="sub"><?= e(implode(' · ', $sub)) ?></p><?php endif; ?></div></section>
<main>
<div class="head"><span>In this collection</span><span>Choose a gallery</span></div>
<div class="grid">
<?php foreach ($cards as $c): ?>
  <a class="card" href="<?= e($c['url']) ?>">
    <div class="img"><?php if ($c['thumb']): ?><img loading="lazy" src="<?= e($c['thumb']) ?>" alt="<?= e($c['name']) ?>"><?php endif; ?></div>
    <h2><?= e($c['name']) ?></h2>
    <p class="meta"><?= (int) $c['count'] ?> photos<?= $c['locked'] ? ' · &#128274; password' : '' ?> &nbsp;<span class="go">View &rarr;</span></p>
  </a>
<?php endforeach; ?>
</div></main>
<footer><?= e($studio) ?><br><a href="<?= e(config('fork.source_url')) ?>" target="_blank" rel="noopener" style="color:inherit;opacity:.6;font-size:11px">Source code</a></footer>
</body></html>
