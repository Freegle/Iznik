<?php

namespace App\Services\FirstReply;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The account Freegle itself speaks from.
 *
 * Freegle needs to be able to talk to a member in a chat - to ask the questions
 * that make a silent post more likely to succeed, and to say what is happening
 * while they wait. Chat already does everything that needs (delivery, email
 * notification, push, read state, history), and it does it well. The cheapest
 * honest way to get all of that is for Freegle to BE a user, in an ordinary
 * User2User conversation.
 *
 * The cost of reusing the chat plumbing is that everything else which walks
 * User2User chats now has an account in its data set that is not a person:
 * chase-ups that nag you to reply, chat spam review, mod queues, rating prompts.
 * Each of those has to learn to skip it, which is what isSystemUser exists for.
 * That is a real cost and it is paid deliberately: a bespoke Freegle-to-member
 * channel would have avoided it, and would also have had to reimplement
 * notification, push, unread state and the app's entire chat UI.
 *
 * Resolved by email and created on first use so there is nothing to seed by hand
 * and no environment where the id is different for a reason nobody remembers.
 */
class FreegleUserService
{
    private const CACHE_KEY = 'firstreply.system_user_id';

    private const CACHE_TTL = 3600;

    /**
     * The Freegle account's user id, creating it if this is the first time it has
     * been needed. Null if it could not be resolved or created - callers treat
     * that as "Freegle cannot speak right now" and skip, rather than failing.
     */
    public function userId(): ?int
    {
        $cached = Cache::get(self::CACHE_KEY);
        if ($cached !== null) {
            return (int) $cached;
        }

        $email = (string) config('freegle.firstreply.chat.system_user_email');
        if ($email === '') {
            return null;
        }

        try {
            $id = $this->findOrCreate($email);
        } catch (\Throwable $e) {
            Log::warning('firstreply: could not resolve system user', ['error' => $e->getMessage()]);

            return null;
        }

        if ($id !== null) {
            Cache::put(self::CACHE_KEY, $id, self::CACHE_TTL);
        }

        return $id;
    }

    /**
     * Is this the Freegle account? Everything that treats a User2User chat as a
     * conversation between two people should ask this first - chase-ups, spam
     * review, ratings, "you have not replied" nudges. None of those mean anything
     * pointed at Freegle, and some of them (a chase-up telling a member off for
     * not replying to an automated question) would be actively rude.
     */
    public function isSystemUser(?int $userId): bool
    {
        if ($userId === null || $userId <= 0) {
            return false;
        }

        return $this->userId() === $userId;
    }

    /** Test-only: drop the cached id so a fresh database resolves again. */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function findOrCreate(string $email): ?int
    {
        $existing = DB::table('users_emails')->where('email', $email)->value('userid');
        if ($existing) {
            return (int) $existing;
        }

        $name = (string) config('freegle.firstreply.chat.system_user_name', 'Freegle');

        return DB::transaction(function () use ($email, $name) {
            // Re-check inside the transaction: two batch workers can reach this at
            // the same moment on a fresh install, and two Freegle accounts would be
            // very hard to notice and very annoying to unpick.
            $existing = DB::table('users_emails')->where('email', $email)->value('userid');
            if ($existing) {
                return (int) $existing;
            }

            $userId = DB::table('users')->insertGetId([
                'fullname' => $name,
                'added' => now(),
                // Never chase, never mail, never suggest as a person to rate.
                'newslettersallowed' => 0,
                'relevantallowed' => 0,
                // A real name, not one we made up, so name-invention never rewrites it.
                'inventedname' => 0,
                // Freegle's own messages do not go through member chat moderation.
                'chatmodstatus' => 'Unmoderated',
            ]);

            DB::table('users_emails')->insert([
                'userid' => $userId,
                'email' => $email,
                'preferred' => 1,
                'backwards' => strrev($email),
                'added' => now(),
            ]);

            Log::info('firstreply: created Freegle system user', ['userid' => $userId, 'email' => $email]);

            return (int) $userId;
        });
    }
}
