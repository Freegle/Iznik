-- Production idempotent SQL: the Partnerships team.
--
-- Membership of this team is what puts the Partnerships page in someone's ModTools menu and
-- lets them use the partnership API (Support and Admin get in regardless). Members are added
-- by hand per environment, so this only creates the team itself, which the permission check
-- looks up by name.
--
-- The team's email is where the three-month renewal reminders go.
-- See 2026_08_08_000002_create_partnerships_team.php.
INSERT INTO `teams` (`name`, `description`, `type`, `email`, `active`, `supporttools`)
SELECT 'Partnerships',
       'Looks after our partnerships with local authorities: sponsorship deals, the income they bring in, and the quarterly statistics councils receive.',
       'Team',
       'partnerships@ilovefreegle.org',
       1,
       0
WHERE NOT EXISTS (SELECT 1 FROM (SELECT `id` FROM `teams` WHERE `name` = 'Partnerships') x);
