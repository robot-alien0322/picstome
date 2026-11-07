# Eugene fork extras

Small add-ons that run alongside this fork (outside the Laravel app):

- `collections-index.php` - read-only "collection" page that lists every gallery in a photoshoot, served at `/c/<token>` (nginx `location ^~ /c/`). The token is an HMAC of the photoshoot id with the app key.
- `picstome-collection-links` - CLI helper that prints the `/c/` link for each photoshoot.
- `picstome-warm-large.php` - timer job that pre-warms the large image CDN URLs so the full-screen viewer doesn't stall.

Fork features inside the app (gallery sets, gallery folders) are in the commits on the `eugene/sets` branch, marked "Eugene fork".
Licensed AGPL-3.0, same as upstream Picstome.
