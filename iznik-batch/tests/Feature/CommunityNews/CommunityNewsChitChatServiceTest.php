<?php

namespace Tests\Feature\CommunityNews;

use App\Models\CommunityNewsArea;
use App\Models\CommunityNewsItem;
use App\Models\User;
use App\Services\CommunityNews\CommunityNewsChitChatService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CommunityNewsChitChatServiceTest extends TestCase
{
    private function systemUser(): User
    {
        $user = $this->createTestUser(['email_preferred' => 'sysnews@test.com']);
        config(['freegle.communitynews.system_user_email' => 'sysnews@test.com']);

        return $user;
    }

    private function area(array $attrs = []): CommunityNewsArea
    {
        return CommunityNewsArea::create(array_merge([
            'anchorgroupid' => 1,
            'name' => 'Testville',
            'lat' => 51.5,
            'lng' => -0.12,
            'groupids' => [1],
            'groupcount' => 1,
        ], $attrs));
    }

    private function item(CommunityNewsArea $area, array $attrs = []): CommunityNewsItem
    {
        return CommunityNewsItem::create(array_merge([
            'areaid' => $area->id,
            'title' => 'Repair Café',
            'snippet' => 'Fix your bits and bobs.',
            'url' => 'https://example.org/repair',
            'researched_at' => now(),
        ], $attrs));
    }

    private function svc(): CommunityNewsChitChatService
    {
        return app(CommunityNewsChitChatService::class);
    }

    public function test_system_user_id_resolves_from_email(): void
    {
        $user = $this->systemUser();
        $this->assertSame($user->id, $this->svc()->systemUserId());
    }

    public function test_drip_posts_newsfeed_and_marks_item(): void
    {
        $sys = $this->systemUser();
        $area = $this->area();
        $item = $this->item($area);

        $result = $this->svc()->drip(false, $area->id);

        $this->assertSame(1, $result['posts']);
        $this->assertSame(1, $result['areas']);

        $item->refresh();
        $this->assertNotNull($item->newsfeedid);
        $this->assertNotNull($item->posted_at);

        $nf = DB::table('newsfeed')->where('id', $item->newsfeedid)
            ->selectRaw('type, userid, location, ST_AsText(position) AS pos, message, html')
            ->first();
        $this->assertSame('Message', $nf->type);
        $this->assertSame($sys->id, (int) $nf->userid);
        $this->assertStringContainsString('POINT', $nf->pos);
        $this->assertStringContainsString('Repair Café', $nf->message);
        $this->assertStringContainsString('https://example.org/repair', $nf->message);
        // The html variant hyperlinks the title; clients render it in
        // preference to message (and suppress preview cards).
        $this->assertStringContainsString('<a href="https://example.org/repair" target="_blank" rel="noopener">Repair Café.</a>', $nf->html);
        $this->assertStringContainsString('<p>Fix your bits and bobs.</p>', $nf->html);

        $this->assertNotNull($area->fresh()->lastposted);
    }

    public function test_compose_html_escapes_and_links(): void
    {
        $area = $this->area();
        $item = $this->item($area, [
            'title' => 'Fish & chips <b>fest</b>',
            'snippet' => 'Bring "cash" & <script>alert(1)</script> a friend.',
        ]);

        $html = $this->svc()->composeHtml($item);

        // Escaped, hyperlinked title with the full stop appended before linking.
        $this->assertStringContainsString('>Fish &amp; chips &lt;b&gt;fest&lt;/b&gt;.</a>', $html);
        // Snippet fully escaped — no raw markup can reach v-html on the client.
        $this->assertStringContainsString('Bring &quot;cash&quot; &amp; &lt;script&gt;alert(1)&lt;/script&gt; a friend.', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_compose_html_without_url_has_no_anchor(): void
    {
        $area = $this->area();
        $noUrl = $this->item($area, ['url' => null]);
        $this->assertStringNotContainsString('<a ', $this->svc()->composeHtml($noUrl));

        // Non-http(s) schemes are never linked — html is rendered unescaped
        // client-side, so only web URLs may become hrefs.
        $badScheme = $this->item($area, ['url' => 'javascript:alert(1)']);
        $this->assertStringNotContainsString('<a ', $this->svc()->composeHtml($badScheme));
    }

    public function test_dup_guard_skips_identical_repeat(): void
    {
        $sys = $this->systemUser();
        $area = $this->area();
        $item1 = $this->item($area);
        // Second item that composes to the identical message.
        $item2 = $this->item($area);

        $id1 = $this->svc()->postItem($area, $item1, $sys->id, false);
        $this->assertNotNull($id1);

        $id2 = $this->svc()->postItem($area, $item2, $sys->id, false);
        $this->assertNull($id2); // identical to the last post -> skipped
    }

    public function test_drip_respects_cadence(): void
    {
        $this->systemUser();
        config(['freegle.communitynews.chitchat_min_days' => 3]);
        $area = $this->area(['lastposted' => now()->subDay()]); // posted 1 day ago
        $this->item($area);

        $result = $this->svc()->drip(false, $area->id);
        $this->assertSame(0, $result['posts']); // too soon
    }

    public function test_force_bypasses_cadence(): void
    {
        $this->systemUser();
        config(['freegle.communitynews.chitchat_min_days' => 3]);
        $area = $this->area(['lastposted' => now()->subDay()]); // posted 1 day ago
        $this->item($area);

        $result = $this->svc()->drip(false, $area->id, true); // --force
        $this->assertSame(1, $result['posts']);
    }

    public function test_count_override_posts_multiple(): void
    {
        $this->systemUser();
        $area = $this->area();
        // Distinct items so the dup-guard doesn't skip them.
        $this->item($area, ['title' => 'Alpha event', 'url' => 'https://example.org/a']);
        $this->item($area, ['title' => 'Bravo event', 'url' => 'https://example.org/b']);
        $this->item($area, ['title' => 'Charlie event', 'url' => 'https://example.org/c']);

        $result = $this->svc()->drip(false, $area->id, false, 2); // --count=2
        $this->assertSame(2, $result['posts']);
    }

    public function test_engagement_counts_loves_and_replies(): void
    {
        $sys = $this->systemUser();
        $area = $this->area();
        $item = $this->item($area);

        $nfid = $this->svc()->postItem($area, $item, $sys->id, false);
        $item->update(['newsfeedid' => $nfid, 'posted_at' => now()]);

        DB::table('newsfeed_likes')->insert([
            'newsfeedid' => $nfid,
            'userid' => $sys->id,
            'timestamp' => now(),
        ]);
        $srid = (int) config('freegle.srid', 3857);
        DB::table('newsfeed')->insert([
            'type' => 'Message',
            'userid' => $sys->id,
            'replyto' => $nfid,
            'message' => 'Sounds lovely!',
            'added' => now(),
            'timestamp' => now(),
            'position' => DB::raw("ST_GeomFromText('POINT(0 0)', $srid)"),
        ]);

        $rows = $this->svc()->engagement($area->id);

        $this->assertCount(1, $rows);
        $this->assertSame($nfid, $rows[0]['newsfeedid']);
        $this->assertSame(1, $rows[0]['loves']);
        $this->assertSame(1, $rows[0]['replies']);
    }
}
