-- Idempotent production SQL for 2026_09_25_000001_add_abuse_to_chat_messages_reportreason.php
--
-- Appends 'Abuse' to chat_messages.reportreason for the Judge's 'decent' question
-- (ai-judgement.md / config('freegle.judgement.chat_reportreason')).
--
-- Safe to run more than once. Run once on production BEFORE deploying the code that
-- writes 'Abuse' (ChatProcessService).

ALTER TABLE chat_messages
  MODIFY COLUMN reportreason ENUM(
    'Spam','Other','Last','Force','Fully','TooMany','User',
    'UnknownMessage','SameImage','DodgyImage',
    'CountryBlocked',
    'IPUsedForDifferentUsers',
    'IPUsedForDifferentGroups',
    'SubjectUsedForDifferentGroups',
    'SpamAssassin',
    'Greetings spam',
    'Referenced known spammer',
    'Known spam keyword',
    'URL on DBL',
    'BulkVolunteerMail',
    'UsedOurDomain',
    'WorryWord',
    'Script',
    'Link',
    'Money',
    'Email',
    'Language',
    'Abuse'
  ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  ALGORITHM=INPLACE, LOCK=NONE;
