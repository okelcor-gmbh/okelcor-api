# Inbound E-mail Capture — Setup (Exchange Redirect → IMAP Mailbox)

**The problem this fixes:** staff compose e-mails to customers from inside
Okelcor, but when a customer hits Reply, that reply has been going only to
the individual staff member's own Outlook — never showing up in the system.
The order manager asked for replies to also show up in the admin panel.

**Why this approach, specifically:** `support@okelcor.com` is a Microsoft
365 mailbox. Microsoft has fully retired Basic Authentication (plain
username/password) for IMAP/POP/SMTP on Exchange Online, so a normal IMAP
connection straight to that mailbox is rejected outright, regardless of
credentials. The alternative — a Microsoft Graph/Azure AD app registration
— was deliberately avoided by choice, to skip Azure entirely. Instead: an
Exchange **inbox rule redirects** a copy of everything sent to
`support@okelcor.com` to a second, non-Microsoft mailbox, which this system
reads over plain IMAP (works fine there — the retirement is specific to
Exchange Online, not IMAP in general).

**Redirect, not Forward — this distinction matters.** Outlook/Exchange has
two different features that look similar:
- **Forward** rewrites the message — the new copy shows *you* as the
  sender, with the original message quoted inside. This breaks the
  matching logic below (wrong sender, wrong headers).
- **Redirect** resends the message keeping the original sender and headers
  intact, as if it had been sent directly to the new address. **This is
  the one you need.**

Follow these steps in order.

---

## 0. Before you start — one thing worth knowing

`support@okelcor.com` also receives other automated mail from this system
(`ORDER_EMAIL`, `QUOTE_EMAIL`, and `CRM_DIGEST_EMAIL` all point at it) —
that mail gets redirected along with everything else. The system already
knows to **ignore anything sent from Okelcor's own domain**, so those
existing notifications won't get mistaken for customer replies or spawn
bogus leads — nothing to configure for that, it's automatic.

## 1. Create the destination mailbox

You need a plain mailbox that still accepts IMAP with a username and
password — anywhere that isn't Microsoft 365 works. The simplest option:
create a new mailbox on the **same Namecheap/cPanel hosting this API
already runs on** (cPanel → Email Accounts → Create), e.g.:

```
inbound@okelcor.com
```

(If `okelcor.com`'s main mail is on Microsoft 365, this specific mailbox
would need to live on a subdomain cPanel actually controls mail for, or a
completely separate domain/hosting account you have IMAP access to —
whichever is simplest given how your DNS/hosting is actually split. If
you're not sure this is possible on the current setup, flag it back before
going further — the redirect rule in step 2 needs somewhere real to point at.)

Note the mailbox's IMAP host/port from cPanel → **Email Accounts** →
**Connect Devices** (usually `mail.okelcor.com`, port `993`, SSL) and set a
strong password.

## 2. Set up the redirect rule in Outlook/Exchange

In Outlook (or the Microsoft 365 admin center, or Exchange Online
PowerShell) on the `support@okelcor.com` mailbox:

1. Create a new inbox rule: **Apply to all messages** (or narrow it if you
   prefer, but the own-domain guard from step 0 already filters out this
   system's own mail, so "all messages" is fine).
2. Action: **Redirect the message to...** → `inbound@okelcor.com` (the
   mailbox from step 1). **Do not use "Forward"** — see the note above.
3. Save. Test by sending yourself a quick e-mail to `support@okelcor.com`
   and confirming a copy shows up, unmodified, in `inbound@okelcor.com`.
   **Also check whether it still appears in `support@okelcor.com`'s own
   inbox too** — Redirect is generally understood to leave the original in
   place unless a separate "move"/"delete" action is added to the rule, but
   confirm it for your tenant specifically rather than assume: if the
   redirected copy is the *only* copy, staff lose their existing Outlook
   visibility rather than gaining a second place to see it, which isn't
   what was asked for. If that turns out to be the case, add an explicit
   "and keep a copy in the Inbox" step to the rule (Exchange rules support
   this as a separate action) before relying on it.

## 3. Test plus-addressing

The system tells replies apart using an address like
`support+abc123@okelcor.com` (the part after `+` identifies which message
it's a reply to) — this only needs to work for mail **arriving at
support@okelcor.com** (which then gets redirected, preserving that address
in the headers); it has nothing to do with the destination mailbox.

1. Send a message **to** `support+test123@okelcor.com`.
2. Confirm it arrives (redirected) in `inbound@okelcor.com`, and that
   opening the raw message headers still shows `To: support+test123@okelcor.com`
   (most webmail clients have a "view message source" option).

**If the `+test123` part got stripped or the message bounced:** the system
still works without it, just slightly less reliably — it falls back to
matching by the e-mail's own reply-thread headers, then by the customer's
e-mail address.

## 4. Set the `.env` values

```env
MAIL_INBOUND_ENABLED=true
MAIL_INBOUND_ADDRESS=support@okelcor.com
MAIL_INBOUND_HOST=mail.okelcor.com
MAIL_INBOUND_PORT=993
MAIL_INBOUND_ENCRYPTION=ssl
MAIL_INBOUND_USERNAME=inbound@okelcor.com
MAIL_INBOUND_PASSWORD=
MAIL_INBOUND_MESSAGE_ID_DOMAIN=okelcor.com
```

Note `MAIL_INBOUND_ADDRESS` (`support@...`, the customer-facing address)
and `MAIL_INBOUND_USERNAME` (`inbound@...`, the mailbox actually being
read) are **deliberately different** — see the config comments in
`config/services.php` if you want the full reasoning.

`MAIL_INBOUND_ENABLED=true` is what actually switches the feature on —
until this is set, outgoing e-mails keep working exactly as they do today
(reply goes to the sending admin's own address, nothing changes).

After setting these, run the standard config-cache refresh
(`artisan config:clear && artisan config:cache`) — no code redeploy needed
if the code is already live.

## 5. Nothing else to schedule

The system already checks the inbound mailbox automatically every 5 minutes
once `MAIL_INBOUND_ENABLED=true` — it reuses the same scheduled-task
mechanism already running hourly jobs (carrier tracking sync, follow-up
reminders, etc.), so as long as the server's cron is already running (it
is, if those other scheduled features already work), there's nothing extra
to set up.

## 6. Test end to end

1. From the admin panel, send a test e-mail to a test customer account (or
   your own personal e-mail, added as a test customer).
2. Reply to that e-mail from the recipient side, as if you were the customer.
3. Within 5 minutes, check the customer's communication history in the
   admin panel — the reply should appear there, and the admin who sent the
   original message should get an in-app notification.
4. Double-check the *other* automated support@ mail (order/quote/digest
   notifications) is unaffected — those should keep arriving as normal and
   should **not** show up as new leads or communications (the own-domain
   guard from step 0 is what prevents that).

If step 3 doesn't work, the most likely causes: the rule redirected instead
of forwarded correctly (double-check it really is "Redirect"), the IMAP
credentials for `inbound@okelcor.com` are wrong, or the redirect rule isn't
actually catching the reply (check it fires on all incoming mail, not just
mail matching some narrower condition).

## A note on "staff still want to see it in Outlook too"

This feature is meant to add the system as a second place replies show up
— not replace Outlook. As long as step 2's check confirmed the redirect
leaves the original in `support@okelcor.com`'s inbox too (the normal
behaviour for Redirect, without an extra "move"/"delete" action), staff can
keep using Outlook exactly as before; the system is simply now watching an
independent copy of the same mail.
