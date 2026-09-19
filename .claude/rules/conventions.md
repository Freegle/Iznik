# Conventions that are not obvious from the code

These are decisions, not preferences. Each was settled once and is easy to get wrong by
reasoning from first principles instead.

## Every table gets an `id`

Give a new table a surrogate `id` even when a natural key is unique and is the only way the
table is ever read, and even when that costs an index. Consistency across the schema is worth
more than the saving on one table.

## No Python for data work

For ad-hoc analysis, querying and modelling, use PHP or Node, not Python. The stack is PHP, Go
and JavaScript throughout; Python is foreign to it, and the host blocks installing packages
anyway. Something written in the house languages can be run and maintained by everyone else.

## Never put keys or secrets in a pull request

Not in code, not in test fixtures, not in the description, not in a commit message. This
includes partner API keys and mail credentials. A pull request is shared and cached, so a secret
committed once is leaked even after it is removed.

## Somebody's "home community" is geographic, not their membership

For any spatial question - proximity, coverage, boundary populations - work out the home
community from **which community's catchment area contains the member's location**, taking the
smallest when several overlap. Do not read it from the membership table. People belong to
communities they do not live in, and membership answers a different question.

## "Available initially" is the size of the pool

It means how many of a thing there were before any were given away. It is not a record of what
was showing at the moment the post went up, and it is not a running total.

## Community rippling opt-out is not a moderator setting

The per-community controls for whether posts ripple in or out are deliberately **not** exposed
as moderator settings in the interface. They are changed deliberately, centrally, through a
command. Do not add toggles for them.

## Moderators do not export member data

There is no member export in ModTools, and adding one back is not a small convenience. The
reason is data protection: a moderator needs to see members to moderate them, and that is not
the same as being able to take the list away. A button that writes the list to a file was
removed for exactly this reason (Discourse 10085/7), after a spell where it came back.

Disabling a button does not achieve this. The list is fetched from `/memberships`, so anything
that only hides the interface leaves the data one call away. If the capability is not wanted,
the code goes.

## See also

- `docs/developers/reference/coding-standards.md` - the rest of the coding rules.
- `docs/getting-started/decisions-and-rationale.md` - the bigger product decisions.
