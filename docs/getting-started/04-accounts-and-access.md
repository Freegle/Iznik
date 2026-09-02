---
last_reviewed: 2026-09-02
owner: Freegle dev team
---

# Accounts and access

This page is a **checklist of what exists**, so that nothing is discovered missing during
an incident. It contains **no credentials, no keys, no IP addresses and no private
hostnames**, and it never will. That is the rule for the whole documentation set, and this
page is the one most likely to tempt someone to break it.

> **Where the actual credentials live**
>
> **1Password.** Every login, key and token below is in the shared vaults. Getting you into
> 1Password is therefore the first task of your first day, and until it is done you are
> blocked on almost everything else.
>
> Ask an existing technical volunteer (the `geeks@` list) to invite you. If nobody responds,
> escalate through Freegle staff - somebody always holds vault administration.

## Day one: the four things you need

In this order, because each one unlocks the next.

| Order | Account | Why first |
|---|---|---|
| 1 | **1Password** | Everything else is inside it |
| 2 | **GitHub** | Access to the `Freegle` organisation, and push rights |
| 3 | **The volunteers' forum (Discourse)** | Where technical discussion happens |
| 4 | **A Freegle account on the live site** | You cannot understand the product without using it |

You do **not** need production server access on day one, and you should not ask for it
until you need it. See "Production access" below.

## What accounts exist

Grouped by what you would be locked out of. Each row is "does this exist and who can grant
it", not how to log in.

### Code and delivery

| Account | What it gives you | Needed by |
|---|---|---|
| **GitHub** (`Freegle` org) | The monorepo, pull requests, issues | Developer, sysadmin |
| **CircleCI** | Test runs, the orb, build environment variables | Developer |
| **Netlify** | The two frontend sites, deploy logs, environment variables, domain settings | Both. Sysadmin especially |
| **Coveralls** | Coverage reporting | Developer (rarely) |
| **Sentry** | Production errors, and the feed the monitor reads | Both |

### Production infrastructure

| Account | What it gives you |
|---|---|
| **SSH to the production hosts** | The database nodes, the Docker host, the load balancer, the outbound mail relay |
| **The hosting provider consoles** | Creating and rebuilding machines, networking, DNS at provider level |
| **The DNS registrar / DNS host** | The `ilovefreegle.org` zone and the shortlink domains |
| **The cloud project used by the backup system** | The Yesterday VM and its snapshots |
| **The cloud storage used for log backup** | Loki archives |
| **The container registry** | The images production pulls |

Machine roles and what routes where are in
[../ops/production.md](../ops/production.md). Which supervisor owns which service - monit,
systemd, Docker Compose or HAProxy - is in
[../ops/reference/sysadmin-duties.md](../ops/reference/sysadmin-duties.md). Host-level
configuration that is not in an image is recorded in
[`ops/hosts/`](../../ops/hosts/README.md).

### Money

Handle these with more care than the rest. They move real money for a charity.

| Account | What it gives you |
|---|---|
| **Stripe** | Card donations, the webhook configuration, refunds |
| **PayPal** | The other donation route, plus the API credentials the nightly reconciliation uses |
| **HMRC Gift Aid** | Submitting the claim. Held by the treasurer or finance volunteer, **not** by developers |
| **The bank** | Not a developer or sysadmin concern. Named here only so you know it is not missing |

**Never test against live payment credentials.** Both providers have test modes. See
[../developers/reference/donations-and-gift-aid.md](../developers/reference/donations-and-gift-aid.md).

### Google and other service providers

Most of these are keys rather than logins, and most are in one Google Cloud project.

| Account | Used for |
|---|---|
| **Google Cloud project** | OAuth sign-in, Maps and Places, Cloud Vision, Perspective, Gemini, Firebase push |
| **Google Play Console** | The two Android app listings, releases, target-API compliance |
| **Apple Developer / App Store Connect** | The two iOS app listings, plus the signing certificates and provisioning profiles |
| **Mapbox** | Map imagery and the isochrones production still buys |
| **MaxMind** | IP-to-location data used in anti-abuse |
| **Playwire, Google AdSense** | Advert delivery |
| **Facebook, Yahoo, Apple developer apps** | The social sign-in routes |
| **CookieYes, Google Tag Manager, Trustpilot** | Consent banner, analytics, reviews |

The full list of what each one does and how badly it matters is
[../developers/reference/external-services.md](../developers/reference/external-services.md).

### Mail and community

| Account | What it gives you |
|---|---|
| **Google Workspace** | Staff and team `@ilovefreegle.org` mail, and the team addresses in [05-who-does-what.md](05-who-does-what.md) |
| **Discourse admin** | The volunteers' forum, and the API key the batch tier posts with |
| **The support mailbox** | Member support mail, read over IMAP by the support tooling |

### App signing

Called out separately because losing it is unrecoverable.

- **The Android upload keystore and its passwords.** If these are lost you cannot publish
  an update to the existing app listing. They are in 1Password, and the build reads them
  from environment variables that throw at configuration time rather than producing an
  unsigned build.
- **The Apple signing certificates and provisioning profiles**, with the same consequence.

Details in [../developers/reference/mobile-app.md](../developers/reference/mobile-app.md).

## How credentials reach the running system

You will not find any of the above in the code. The paths are:

| Where it runs | How it gets its secrets |
|---|---|
| Local development | `.env`, gitignored. Copy `.env.example` and fill in what you need. Most of the stack works with none of it |
| Production batch tier | `.env.background` on the Docker host, from `.env.background.example`. Read through `iznik-batch/config/freegle.php`, never `env()` at the point of use |
| Production API and services | Their own environment on the host |
| The frontends | Build-time environment variables in Netlify. Anything in `runtimeConfig.public` is served to every visitor, so **only publishable keys belong there** |
| CI | CircleCI project environment variables |

The example files (`.env.example`, `.env.background.example`) are the closest thing to a
complete inventory of what a running system needs, and they are in git. Read them; they
are more current than this page can be.

## Production access

Deliberately not automatic.

- **Ask for it when a task needs it**, not before. Most development, including most bug
  fixing, needs no production access at all.
- **Read-only database access to production exists** and is the right tool for
  investigating live data. It is genuinely read-only, so you cannot break anything with it.
- **Writes to production data happen through code and migrations**, reviewed like anything
  else - not by hand at a prompt.
- Be aware that **production containers must not be recreated or restarted without
  explicit approval**: the batch container is doing real work for real people when you
  restart it.

## When someone leaves

The uncomfortable but necessary list, and exactly what gets forgotten when people move on.

1. Remove them from 1Password, and **rotate anything they could have copied** - a
   credential someone once held is not secured by removing their access to the vault.
2. Remove them from the GitHub organisation, CircleCI, Netlify and Sentry.
3. Remove their SSH keys from the production hosts, and check for keys used by automation
   that happen to be theirs.
4. Check for anything under a **personal** account rather than a Freegle one: a domain, an
   API key, a cloud project, a store listing, a mailing list. This is the item that
   actually causes outages years later.
5. Reassign the team addresses in [05-who-does-what.md](05-who-does-what.md) that they
   received.
6. Update the `owner:` front matter on any documentation page they owned.

## If you are locked out of everything

The recovery order that has worked before:

1. **Freegle staff** can reach the vault administrators.
2. **The `geeks@` list** reaches the technical volunteers.
3. **The volunteers' forum** reaches everybody, and someone there will know who holds what.
4. The **hosting provider and the registrar** have their own account-recovery routes tied
   to the organisation's contact details, independent of anything above.

Nothing about Freegle depends on one person being reachable, but proving that takes hours
you will not want to spend during an incident. Which is the whole reason this page exists.
