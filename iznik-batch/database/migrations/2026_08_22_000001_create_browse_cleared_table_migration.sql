-- Production SQL: browse_cleared. NOT YET APPLIED.
--
-- Per-member watermark for "I have cleared my browse unread count".
--
-- WHY A WATERMARK RATHER THAN PER-POST ROWS. The badge counts open posts in reach with no
-- messages_likes View row. Scrolling a post into view writes that row and it is a genuine
-- impression: it feeds the view count posters see and the recommendation funnels. The
-- ordinary member is sitting on ~1,000 unseen posts (measured across 40 recently-active
-- members: 600-1,600, and 10,463 for a member of 87 groups), so spending View rows on a
-- bulk "mark all read" would fabricate a thousand views per click.
--
-- WHY NOT A 'Dismissed' VALUE IN messages_likes.type. That table is ~86M rows and an ENUM
-- change is a TOI DDL, i.e. the cluster-wide freeze this codebase has been bitten by
-- repeatedly (see the rippling_reach_overflow note above: an ALTER on a hot table sat 36
-- minutes at `checking permissions` and took the site down). CREATE TABLE has nothing to
-- build: no scan, no lock on anything hot, returns immediately.
--
-- WHY spatialid IS A messages_spatial.id AND NOT AN arrival OR A msgid. Both of those are
-- stamped when the post was WRITTEN. MessageSpatialService inserts `arrival = $msg->arrival`
-- (the group arrival), so a post that was Pending when the member cleared and is approved
-- ten minutes later carries a backdated value, falls under the watermark, and is silently
-- never counted again. On a fully-moderated group that is a lot of posts. The spatial row is
-- created when the post ENTERS the feed, so its auto-increment id is the honest "became
-- visible" clock.
--
-- Mirrors newsfeed_users(userid UNIQUE, newsfeedid), which is this same watermark for ChitChat.
CREATE TABLE IF NOT EXISTS browse_cleared (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    userid    BIGINT UNSIGNED NOT NULL,
    spatialid BIGINT UNSIGNED NOT NULL COMMENT 'messages_spatial.id cleared up to and including',
    timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY userid (userid)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='How far a member has cleared their browse unread count';

-- No backfill. An absent row means "cleared nothing", which is the correct state for every
-- existing member: their count carries on being decided by their View rows alone until the
-- first time they press the button.
--
-- VERIFY - a check to RUN, not a result to assume. After a member presses "Mark seen":
--
--   SELECT spatialid FROM browse_cleared WHERE userid = <id>;
--   SELECT MAX(id) FROM messages_spatial;
--
-- The first should be at or just below the second, and GET /api/message/count for that
-- member should return 0. If the count is still non-zero the filter is not reaching the
-- count query and the change has bought nothing - say so rather than closing the ticket.
