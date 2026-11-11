-- Production SQL for 2026_10_19_000001_add_our_posting_breakdown_to_stats_type.php
--
-- stats.type had no 'OurPostingBreakdown', so the non-strict connection stored ''
-- for every daily OurPostingBreakdown row (1.8M rows since 2015). Adding the value
-- at the end of the ENUM is INSTANT; ALGORITHM=INSTANT makes it fail rather than
-- rebuild if that ever stops being true.
SET SESSION lock_wait_timeout = 5;
ALTER TABLE stats MODIFY `type` ENUM('ApprovedMessageCount','SpamMessageCount','MessageBreakdown','SpamMemberCount','PostMethodBreakdown','YahooDeliveryBreakdown','YahooPostingBreakdown','ApprovedMemberCount','SupportQueries','Happy','Fine','Unhappy','Searches','Activity','Weight','Outcomes','Replies','ActiveUsers','OurPostingBreakdown') COLLATE utf8mb4_unicode_ci NOT NULL, ALGORITHM=INSTANT;

-- Then relabel the '' rows in chunks. Repeat until it reports 0 rows affected
-- (about 180 runs). Each chunk is a range read on the `type` index.
UPDATE stats SET `type` = 'OurPostingBreakdown' WHERE `type` = '' LIMIT 10000;
