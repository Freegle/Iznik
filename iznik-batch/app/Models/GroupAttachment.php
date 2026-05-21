<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

/**
 * Attachment stored in the groups_images table.
 *
 * Mirrors the TYPE_GROUP behaviour of Attachment in iznik-server, including
 * getPath() and getPublic().
 */
class GroupAttachment extends Model
{
    protected $table = 'groups_images';
    protected $guarded = ['id'];
    public $timestamps = false;

    protected $casts = [
        'archived' => 'boolean',
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class, 'groupid');
    }

    /**
     * Return the URL for this attachment.
     *
     * When $id is supplied the method works statically (no attachment row needs
     * to be loaded), matching the Group::getPublic() usage pattern in
     * iznik-server where getPath(FALSE, $profileImageId) is called on an
     * unloaded Attachment instance.
     *
     * Mirrors Attachment::getPath() from iznik-server for TYPE_GROUP.
     */
    public function getPath(bool $thumb = false, ?int $id = null): ?string
    {
        // Not implemented yet, unsure if needed.
        if ($this->externaluid || $this->externalurl) {
            Log::error("GroupAttachment::getPath(): external UID or URL not implemented.");
            return null;
        }

        $imageId = $id ?? $this->id;

        if (!$imageId) {
            return null;
        }

        $prefix = $thumb ? 'tgimg' : 'gimg';
        $domain = $this->archived
            ? rtrim(config('freegle.images.archived_domain', 'https://freegle.blob.core.windows.net'), '/')
            : rtrim(config('freegle.images.domain', 'https://images.ilovefreegle.org'), '/');

        return "{$domain}/{$prefix}_{$imageId}.jpg";
    }
}
