# Okelcor — Security & Reliability Plan

**Prepared for:** Okelcor leadership
**Scope:** Backend platform, admin operations, backups
**Date:** 14 July 2026
**Status:** Draft for stakeholder review

A plan to keep Okelcor's data confidential, accurate, and online — covering the
people who run it, the systems it runs on, and what happens to the business if
a server is ever lost. This is written for review with stakeholders before any
of it is built.

**At a glance:**
- 🔴 No verified offsite backup
- 🔴 eBay credential not yet rotated
- 🟡 Single server, no redundancy
- 🟡 Admin role list incomplete
- 🟢 Mandatory 2FA + audit trail
- 🟢 Role-based access control

---

## Why these three things

Every security decision below serves one of three goals. Naming them up front
makes it easy to check any future request against the same three questions:
does this keep customer and business data private, does it keep our records
accurate, and does it keep the platform running.

| | Plain meaning |
|---|---|
| **Confidentiality** | Only the right people can see customer details, pricing, contracts, and payment data — nobody else, inside or outside the company. |
| **Integrity** | Orders, invoices, and customer records stay accurate — no silent corruption, no one quietly editing history without a trace. |
| **Availability** | The store, the admin panel, and the customer portal stay reachable — and if a server is ever lost, the business can be rebuilt quickly. |

This plan leans hardest on **availability**, because that's the weakest of the
three today — the platform runs on one server, with no confirmed working copy
anywhere else.

---

## Current state, by area

This isn't a rebuild — a fair amount already works. Below is what's solid
today and what's actually missing, area by area.

### 1 · People (admin staff & access)

**Working well**
- Two-factor login is mandatory for every admin account — no exceptions, no bypass.
- Every meaningful admin action is written to a permanent audit trail (who, what, when).
- Roles already separate what an order manager can do from what a super admin can do.

**Gaps**
- The database won't accept some roles the system already expects (`sales_manager`, `support`, and two others) — creating one of these accounts fails outright.
- No written rule for switching off access the same day someone leaves.
- No quarterly check of who still holds admin rights they no longer need.
- No baseline training on phishing, password reuse, or a lost/stolen device.

### 2 · Systems & information (application & data handling)

**Working well**
- Sensitive endpoints are rate-limited; Stripe payment confirmations are cryptographically verified before being trusted.
- Admin API and customer-facing API are kept separate; only known frontend domains are allowed to call the API.
- The full test suite runs automatically against a real database on every change, catching regressions before release.

**Gaps**
- A credential for the eBay integration was exposed in a past session and still hasn't been replaced.
- Bulk jobs (e.g. a 1,700-email send) run *immediately, inline*, tying up the same server handling live customer traffic.
- Production error logs aren't reliably landing anywhere visible — an incident today could leave no trace to investigate.

### 3 · Backup & availability (can the business survive losing this server)

**Working well**
- A backup command already exists and can package the full database, plus product images on request, into a single archive.

**Gaps**
- That archive is saved **on the same server** it's protecting against — if the server is lost, the backup is lost with it.
- Nobody has restored one of these archives to confirm it actually works.
- Uploaded files — product photos, signed proposals, shipping documents — live only on that one server's disk.
- Nothing outside the server watches whether the site is up; the first sign of an outage today would be a customer noticing.
- The site itself runs on a single shared hosting account, with no failover if that account or server has a problem.

---

## Prioritised roadmap

Ordered by how much damage the gap could cause versus how cheap it is to
close. Nothing below requires the platform to go offline to implement.

### Phase 0 — Start this week: stop the single point of failure

| Action | Why it matters | Effort |
|---|---|---|
| Send backups off-server, to the cloud | Right now a backup sits on the same disk as the thing it protects. Point the existing backup job at a low-cost cloud storage bucket so a copy leaves the building automatically. This closes the single biggest risk in this whole plan. | Low |
| Rotate the exposed eBay credential | It's been flagged as exposed for weeks and hasn't been replaced. Fifteen minutes in the eBay developer portal closes the door. | Low |
| Fix the admin role list | A small database change so creating a `sales_manager` or `support` admin account doesn't silently fail in production. | Low |

### Phase 1 — Next 30 days: make the fixes trustworthy

| Action | Why it matters | Effort |
|---|---|---|
| Actually restore a backup, once | A backup nobody has restored is a rumor, not a backup. Prove one archive rebuilds a working database before trusting it. | Low |
| Set a retention plan | Keep recent days, recent weeks, and a year of months — inexpensive on cloud storage, and protects against a problem that goes unnoticed for a while. | Low |
| Move slow jobs to the background | A large email send should run behind the scenes, not tie up the same server serving the storefront while it goes out. | Medium |
| Fix production error logging | Errors are currently vanishing. This is the difference between diagnosing the next incident in minutes versus guessing. | Medium |
| Add an outside "is it up" watcher | A cheap external monitor checks the site every few minutes and alerts someone the moment it goes quiet — instead of waiting for a customer to complain. | Low |
| Review admin access, rotate shared passwords | Confirm every admin account still needs its access; rotate database, mail, Stripe, and hosting-panel credentials as routine hygiene. | Low |

### Phase 2 — 60–90 days: remove the single point of failure for good

| Action | Why it matters | Effort |
|---|---|---|
| Move uploaded files to cloud storage | Product photos, signed proposals, and shipping documents currently live only on the server's disk. Phase 0/1 already gets them into the offsite backup — this is the more permanent fix, not an emergency. | High |
| Stand up a staging environment | A private twin of the site so future changes are tested somewhere that isn't the live storefront. | Medium |
| Write and drill a disaster-recovery runbook | A short, specific document: if the server disappears tonight, who does what, using which backup, in what order. Then run it once, on a throwaway server, to time it and find the gaps on paper. | Medium |
| Revisit hosting itself | A single shared hosting account has no redundancy by design. Worth evaluating a host with automatic server-level snapshots — but only once Phase 0/1 backups are solid, so this isn't done under pressure. | High |

### Phase 3 — Ongoing: keep it that way

| Action | Why it matters | Effort |
|---|---|---|
| Basic security habits for staff | Recognising a phishing email, not reusing passwords, reporting a lost device immediately. 2FA already blunts a lot of this, but people are still usually how a break-in starts. | Low |
| A written "who do we call" list | Who has server access, who talks to Stripe / eBay / the hosting provider, who tells customers if there's downtime — decided calmly now, not during an outage. | Low |
| Quarterly access review | New hires get exactly the role they need; departures get switched off the same day. | Low |
| Close two open decisions from the last customer-lifecycle review | What a customer's buyer tier should actually unlock, and whether a flagged high-risk buyer should ever block a checkout automatically. Not bugs — open business decisions worth a short call. | Low |

---

## Recommended starting point

**Get a real, automatic, offsite backup running this week.** It's the
cheapest item in this entire plan — a few dollars a month in cloud storage —
and it's the one gap that, left open, turns every other problem on this list
into a business-ending one.

---

## Questions stakeholders will likely ask

**Why cloud storage instead of just downloading backups to a laptop?**
Because that's already been tried and it isn't happening — manual steps get
skipped once things get busy. A laptop is also its own single point of
failure: it can be lost, stolen, or damaged. Cloud storage costs a few
dollars a month and runs itself without anyone remembering to do it.

**How much will this actually cost?**
Phase 0 and Phase 1 are low cost — mainly a few dollars a month for cloud
storage plus a modest amount of engineering time. Phase 2 (moving hosting) is
the one item with a real budget conversation attached, and it doesn't need to
happen this quarter.

**What do "how much could we lose" and "how fast can we recover" actually mean?**
Two plain numbers worth agreeing on: how much recent work could be lost if
something goes wrong today, and how long it would take to be back online.
Right now, the honest answer to the first is "potentially everything since
the last working backup" — because there isn't a confirmed one. Phase 1 sets
a target of never losing more than 24 hours of work. Phase 2 sets a target
for how fast the site comes back.

**Is anything actively broken right now — is this an emergency?**
No known active breach or ongoing incident. The risk is a single point of
failure: one server, no verified offsite backup. The odds of it failing on
any given day are low, but the cost if it does is total — that combination
is exactly what this plan is built to close quickly and cheaply.

**Will any of this change how staff work day to day?**
Phase 0 and Phase 1 run invisibly in the background — nobody has to change
their routine. The only staff-visible piece is a short access review, and
eventually a brief security-habits session in Phase 3.

**Do we need to leave our current hosting provider right now?**
No. Offsite backups and outside monitoring (Phase 0/1) already remove most of
the danger without touching hosting at all. Moving hosting is Phase 2 —
worth doing eventually, not urgent once backups are solid.
