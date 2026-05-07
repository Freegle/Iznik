<?php

namespace App\Services;

use App\Models\MessageGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MessageDeindexService
{
    public function deindexOldMessages(): array
    {
        $date = now()->subDays(30)->format('Y-m-d');

        $msgids = DB::table('messages_groups')
            ->join('messages_index', 'messages_index.msgid', '=', 'messages_groups.msgid')
            ->where('messages_groups.collection', MessageGroup::COLLECTION_APPROVED)
            ->where('messages_groups.arrival', '<', $date)
            ->distinct()
            ->pluck('messages_groups.msgid');

        Log::info("MessageDeindex: deindexing {$msgids->count()} messages");

        $done = 0;

        foreach ($msgids->chunk(500) as $chunk) {
            DB::table('messages_index')->whereIn('msgid', $chunk)->delete();
            $done += count($chunk);
            Log::info("MessageDeindex: ...{$done} / {$msgids->count()}");
        }

        DB::table('words_cache')->delete();

        return ['deindexed' => $done];
    }
}
