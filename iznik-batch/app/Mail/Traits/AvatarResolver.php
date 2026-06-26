<?php

namespace App\Mail\Traits;

use App\Models\User;

trait AvatarResolver
{
    /**
     * Return a hosted avatar URL for the given user.
     *
     * Uses the user's uploaded profile image when available, otherwise falls
     * back to the boring-avatars server which generates the same geometric
     * avatar the frontend shows (never the grey default silhouette).
     *
     * @param int|null $size Pixel size hint for the avatar server (default 48)
     */
    protected function resolveAvatarUrl(?User $user, int $size = 48): string
    {
        if ($user) {
            $uploaded = $user->getProfileImageUrl(thumbnail: true);
            if ($uploaded) {
                return $uploaded;
            }
        }

        $name    = $user?->fullname ?: 'user';
        $baseUrl = rtrim(config('freegle.avatar_server_url', ''), '/');
        if ($baseUrl === '') {
            // Fall back to the user site so emails always have absolute avatar URLs.
            // Without this fallback the URL becomes "/name.png" which doesn't resolve
            // in email clients.
            $baseUrl = rtrim(config('freegle.sites.user', ''), '/');
        }

        return $baseUrl . '/' . rawurlencode($name) . '.png' . ($size !== 48 ? "?size={$size}" : '');
    }
}
