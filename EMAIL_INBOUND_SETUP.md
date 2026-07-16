# Inbound E-mail Capture — Step-by-Step Setup Guide (Cloudflare)

**What this does, in plain terms:** right now, when a customer replies to an
e-mail sent from the admin panel, that reply only shows up in Outlook —
nobody sees it inside Okelcor's system. After this setup, replies will also
show up on the customer's record in the admin panel, and whoever sent the
original message gets a notification.

**How it works, in plain terms:** outgoing e-mails from the system will ask
customers to reply to a brand-new address on a new subdomain,
`reply.okelcor.com`. Cloudflare (where this domain's DNS already lives)
watches that subdomain, and the instant a reply arrives, it hands it to a
small piece of code ("a Worker") that reads it and tells the Okelcor API
about it — instantly, no waiting or polling. `support@okelcor.com` and your
normal Outlook inbox are **completely untouched** by any of this.

---

## Why this approach (and not the ones tried before)

Two earlier attempts didn't pan out:
- A direct connection to Microsoft 365 was avoided on purpose (it needs an
  Azure app registration, which you asked to skip).
- Redirecting `support@okelcor.com`'s mail to a second mailbox and reading
  that via plain IMAP kept failing to authenticate, and troubleshooting
  didn't resolve it.

This approach sidesteps Microsoft 365 entirely — it uses a brand new
subdomain that never touches `support@okelcor.com` or Outlook at all, built
on Cloudflare, which already manages this domain's DNS.

**One honest caveat before you start:** this is the most technical of the
three options — it involves installing a small piece of free software
(Node.js) and running a few terminal commands to publish the Worker code.
It's not difficult, just less "click a button in a web page" than the
earlier attempts. Follow the steps below in order and you'll be fine.

---

## Before you start — checklist

- [ ] Access to the Cloudflare account/dashboard where `okelcor.com`'s DNS is managed
- [ ] A free [Cloudflare account](https://dash.cloudflare.com) login (same one, if DNS is already there)
- [ ] A terminal on your own computer (not the Namecheap server this time —
      this part runs from your computer, once, to publish the Worker)
- [ ] Terminal/SSH access to the Namecheap server (as before, for the `.env` step)
- [ ] About 45–60 minutes (a bit longer than the earlier attempts, mostly
      because of the one-time software install)

---

## Part 1 — Point a new subdomain at Cloudflare Email Routing (15 minutes)

**Goal of this part:** get Cloudflare watching `reply.okelcor.com` for
incoming mail, without touching anything about `okelcor.com` itself (which
must keep working with Microsoft 365 exactly as it does today).

1. Log into [dash.cloudflare.com](https://dash.cloudflare.com) and select the `okelcor.com` site.
2. In the left-hand menu, find **Email** → **Email Routing**.
3. Cloudflare will offer to set this up — **this is the one part where I
   want you to follow Cloudflare's own on-screen instructions rather than a
   fixed list from me**, because the exact DNS record values Cloudflare
   asks you to add can change over time, and I'd rather you copy what's
   actually shown on your screen than a value I typed from memory that
   might be stale.
4. The important decision point: when Cloudflare's setup asks what address
   or domain to route, **make sure you're setting this up for the
   subdomain `reply.okelcor.com`, not for `okelcor.com` itself.** If the
   wizard doesn't obviously offer a subdomain option, go to **DNS** →
   **Records** instead, and manually add whatever MX and TXT records
   Cloudflare's Email Routing page told you to add, but with the "Name"/
   "Host" field set to `reply` instead of `@` (root) — this scopes those
   records to the subdomain only, leaving `okelcor.com`'s existing
   Microsoft 365 MX record completely alone.
5. Once added, Cloudflare will show the records as verified (may take a few
   minutes). At this point, mail sent to `anything@reply.okelcor.com` is
   being received by Cloudflare — we just haven't told it what to do with
   it yet (that's Part 4).

✅ **Checkpoint:** in Cloudflare's Email Routing section, the subdomain
shows as active/verified, and `okelcor.com`'s own DNS records (the ones
pointing to Microsoft 365) are unchanged — double check this specifically,
since it's the one thing that must not break.

---

## Part 2 — Install Node.js (one-time, ~10 minutes)

This is free, standard software used to run the small piece of code from
Part 3. Skip this if it's already installed (check by opening a terminal
and typing `node -v` — if you see a version number like `v20.x.x`, skip to Part 3).

1. Go to [nodejs.org](https://nodejs.org) and download the **LTS** version for your operating system.
2. Run the installer, accepting the defaults.
3. Open a fresh terminal window and confirm it worked:
   ```bash
   node -v
   npm -v
   ```
   Both should print a version number.

---

## Part 3 — Set up and publish the Worker (15 minutes)

This project already includes the Worker's code, in the `cloudflare-worker/`
folder of the `okelcor-api` repository — you're just installing its tools
and publishing it, not writing anything.

1. Open a terminal **on your own computer** and go into that folder:
   ```bash
   cd path/to/okelcor-api/cloudflare-worker
   ```
2. Install its dependencies:
   ```bash
   npm install
   ```
3. Log in to Cloudflare from the terminal (opens your browser to confirm — one-time):
   ```bash
   npx wrangler login
   ```
4. Create the shared secret that lets the Worker and the API trust each
   other. Generate a random value and set it — **write this value down
   somewhere safe, you'll need it again in Part 5**:
   ```bash
   npx wrangler secret put WEBHOOK_SECRET
   ```
   When prompted, paste in a long random string (e.g. generate one with
   `openssl rand -hex 32` in another terminal tab, or any password
   generator — 40+ random characters is plenty).
5. Publish the Worker:
   ```bash
   npx wrangler deploy
   ```
   This should finish with a success message and a URL like
   `https://okelcor-inbound-email.<your-subdomain>.workers.dev` — you don't
   need to visit that URL or do anything with it, it's just confirmation
   the Worker is live.

✅ **Checkpoint:** `wrangler deploy` finished without errors.

---

## Part 4 — Tell Cloudflare Email Routing to use this Worker (5 minutes)

1. Back in the Cloudflare dashboard → **Email** → **Email Routing** → **Routing rules**.
2. Add a rule (or edit the catch-all rule) for `reply.okelcor.com`:
   - **Matcher:** Catch-all address (so `reply@reply.okelcor.com`,
     `reply+anything@reply.okelcor.com`, etc. all match).
   - **Action:** **Send to a Worker**.
   - **Destination:** select `okelcor-inbound-email` (the Worker from Part 3).
3. Save.

✅ **Checkpoint — test it right now:** send a test e-mail from any account
to `reply+test123@reply.okelcor.com`. It should just disappear (no bounce,
no visible inbox — that's expected, the Worker consumed it and sent it to
the API). We'll confirm it actually reached the API in Part 6.

---

## Part 5 — Tell the Okelcor API about the new address and secret (5 minutes)

Terminal/SSH into the Namecheap server (as before) and add these to `.env`:

```env
MAIL_INBOUND_ENABLED=true
MAIL_INBOUND_ADDRESS=reply@reply.okelcor.com
MAIL_INBOUND_WEBHOOK_SECRET="paste-the-exact-secret-from-part-3-step-4-here"
MAIL_INBOUND_MESSAGE_ID_DOMAIN=okelcor.com
```

(Quoting the secret value is a good habit in case it contains any unusual
characters — it won't hurt even if it doesn't need it.)

Then:

```bash
cd /home/u978121777/domains/okelcor.com/public_html/okelcor-api
/opt/alt/php83/usr/bin/php artisan config:clear
/opt/alt/php83/usr/bin/php artisan config:cache
```

---

## Part 6 — The real test (5 minutes)

1. In the admin panel, open (or create) a test customer with an e-mail
   address you can actually check.
2. Send them a test e-mail through the admin panel's compose feature.
3. Open that e-mail in your own inbox (as if you were the customer) and hit
   **Reply** — write anything, send it.
4. Within a few seconds (this is instant, not a 5-minute poll like the
   earlier attempts — Cloudflare hands it to the Worker the moment it
   arrives), go back to that customer's record in the admin panel — the
   reply should appear in their communication history, and the staff
   member who sent the original e-mail should get a notification.

**If it doesn't show up:**
1. Check the Worker actually ran: Cloudflare dashboard → **Workers & Pages**
   → `okelcor-inbound-email` → **Logs** (or run `npx wrangler tail` from
   the `cloudflare-worker` folder while you send the test reply, to watch
   it happen live). If nothing shows up here, the problem is in Part 1 or
   Part 4 (Cloudflare isn't routing the mail to the Worker at all).
2. If the Worker log shows it ran but with an error — read the error
   message, it'll usually say exactly what failed (e.g. a fetch/network
   error reaching the API).
3. If the Worker ran fine with no errors, but nothing shows up in the admin
   panel — double check `MAIL_INBOUND_WEBHOOK_SECRET` in `.env` **exactly**
   matches what you set in Part 3 step 4 (this is the most common mismatch
   — a signature check silently rejects the request if these don't match
   exactly), and confirm you ran `config:clear`/`config:cache` after
   editing `.env`.

---

## You're done

From now on:
- Customer replies show up in the admin panel within seconds, no extra
  clicks from anyone.
- Staff keep using Outlook exactly as before for everything else — this
  subdomain is completely separate from `support@okelcor.com`.
- The system automatically ignores anything sent from Okelcor's own domain,
  so its own automated e-mails never create noise or fake leads here.

**If you ever need to update the Worker's code** (rare — only if this
feature's logic changes on the backend side in a way that needs a matching
Worker change), the fix is just repeating Part 3 steps 1–5 from an updated
copy of the `cloudflare-worker/` folder.
