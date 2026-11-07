<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A folder for organizing galleries in the admin (Eugene fork feature).
 */
class GalleryFolder extends Model
{
    protected $guarded = [];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function galleries(): HasMany
    {
        return $this->hasMany(Gallery::class, 'gallery_folder_id');
    }
}
