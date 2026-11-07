<?php

namespace App\Support;

use App\Models\Gallery;
use App\Models\GalleryActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;

/**
 * Gallery activity (Eugene fork): visitor cookie, download email cookie, logging.
 */
class GalleryActivityTracker
{
    public const VISITOR_COOKIE = 'pst_vid';

    public const EMAIL_COOKIE = 'pst_dl_email';

    public const COOKIE_MINUTES = 60 * 24 * 365;

    public const VISIT_DEDUPE_MINUTES = 30;

    public static function visitorId(Request $request): string
    {
        if ($id = $request->attributes->get(self::VISITOR_COOKIE)) {
            return $id;
        }

        $id = (string) $request->cookie(self::VISITOR_COOKIE);

        if (! preg_match('/^[a-z0-9-]{16,64}$/', $id)) {
            $id = (string) Str::uuid();
        }

        Cookie::queue(self::VISITOR_COOKIE, $id, self::COOKIE_MINUTES);
        $request->attributes->set(self::VISITOR_COOKIE, $id);

        return $id;
    }

    public static function email(Request $request): ?string
    {
        $email = $request->attributes->get(self::EMAIL_COOKIE) ?? $request->cookie(self::EMAIL_COOKIE);

        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : null;
    }

    public static function rememberEmail(Request $request, string $email): void
    {
        $email = strtolower(trim($email));
        Cookie::queue(self::EMAIL_COOKIE, $email, self::COOKIE_MINUTES);
        $request->attributes->set(self::EMAIL_COOKIE, $email);
    }

    public static function isOwner(Gallery $gallery): bool
    {
        return (bool) auth()->user()?->can('update', $gallery);
    }

    public static function isBot(Request $request): bool
    {
        $ua = (string) $request->userAgent();

        return $ua === '' || (bool) preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp|telegram|discord|slack|skype|embedly|quora|pinterest|vkshare|headless|curl|wget|python|httpclient|okhttp|lighthouse/i', $ua);
    }

    public static function record(Gallery $gallery, Request $request, string $type, array $extra = []): GalleryActivity
    {
        $country = strtoupper((string) $request->header('CF-IPCountry'));

        return GalleryActivity::create(array_merge([
            'gallery_id' => $gallery->id,
            'type' => $type,
            'visitor_id' => static::visitorId($request),
            'email' => static::email($request),
            'country' => preg_match('/^[A-Z]{2}$/', $country) && ! in_array($country, ['XX', 'T1'], true) ? $country : null,
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ], $extra));
    }

    /** Make sure the gallery's email list includes this visitor's remembered email. */
    public static function ensureEmailListed(Gallery $gallery, Request $request): void
    {
        $email = static::email($request);

        if ($email && ! $gallery->activities()->where('type', 'email')->where('email', $email)->exists()) {
            static::record($gallery, $request, 'email', ['email' => $email]);
        }
    }
}
