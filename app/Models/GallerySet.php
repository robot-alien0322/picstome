<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * A named set of photos inside a gallery (Eugene fork feature).
 */
class GallerySet extends Model
{
    protected $guarded = [];

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(Photo::class, 'gallery_set_id');
    }

    /**
     * Ids of this set's ready photos, in the gallery's natural (by name) order.
     *
     * @return Collection<int, int>
     */
    public function readyPhotoIds(): Collection
    {
        $mine = $this->photos()->ready()->pluck('id')->flip();

        return $this->gallery->readyPhotoIds()->filter(fn ($id) => $mine->has($id))->values();
    }
}
