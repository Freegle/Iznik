Dear {{ $userName }},

There is a pending suggested admin for {{ $groupName }} that has been waiting for {{ $pendingTimeText }} without being approved, rejected, or held.

Subject: {{ $adminSubject }}

Please don't assume that somebody else will deal with it. You might be waiting for another moderator to handle this, but if so, please check with them whether they're going to do it - it's been hanging around for a while now.

Please log into ModTools and approve, reject, or hold this admin:
{{ $modToolsUrl }}

---
This is an automated reminder from {{ $siteName ?? 'Freegle' }}.
You are receiving this because you are a moderator of {{ $groupName }}.
