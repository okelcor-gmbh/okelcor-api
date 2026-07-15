# Inbound E-mail Capture — Setup (Microsoft 365)

**The problem this fixes:** staff compose e-mails to customers from inside
Okelcor, but when a customer hits Reply, that reply has been going only to
the individual staff member's own Outlook — never showing up in the system.
The order manager asked for replies to also show up in the admin panel.

**Important — this is Microsoft 365 / Exchange Online, not IMAP.** An
earlier draft of this doc assumed a cPanel-hosted mailbox reachable over
plain IMAP with a username/password. Since `support@okelcor.com` is
actually a Microsoft 365 mailbox, that approach would never have worked —
**Microsoft has fully retired Basic Authentication (plain username/password)
for IMAP/POP/SMTP on Exchange Online.** A connection using just a password
is rejected outright, regardless of how correct the credentials are. The
backend now uses **Microsoft Graph** instead — Microsoft's own, currently
supported way to read mail programmatically, authenticated via an Azure AD
app registration rather than a personal login. Follow these steps in order.

---

## 0. Before you start — one thing worth knowing

`support@okelcor.com` already receives other automated mail from this
system (`ORDER_EMAIL`, `QUOTE_EMAIL`, and `CRM_DIGEST_EMAIL` all point at
it). The system already knows to **ignore anything sent from Okelcor's own
domain** when scanning this mailbox, so those existing notifications won't
get mistaken for customer replies or spawn bogus leads — nothing to
configure for that, it's automatic.

## 1. Register an app in Microsoft Entra ID

You'll need a **Global Administrator** (or Application Administrator) on
Okelcor's Microsoft 365 tenant for this part.

1. Go to [entra.microsoft.com](https://entra.microsoft.com) (or
   [portal.azure.com](https://portal.azure.com) → **Microsoft Entra ID**).
2. **App registrations** → **New registration**.
   - Name: `Okelcor Inbound Mail Reader` (or anything recognizable).
   - Supported account types: **Accounts in this organizational directory only**.
   - Leave Redirect URI blank — this app never involves a user logging in.
3. After creating it, note down from the **Overview** page:
   - **Application (client) ID**
   - **Directory (tenant) ID**

## 2. Create a client secret

1. In the app's page → **Certificates & secrets** → **New client secret**.
2. Give it a description and an expiry (24 months is reasonable — you'll
   need to rotate it before it expires; put a reminder somewhere).
3. Copy the **Value** immediately — Microsoft only shows it once.

## 3. Grant it permission to read/mark mail — and get admin consent

1. In the app's page → **API permissions** → **Add a permission** → **Microsoft Graph** → **Application permissions**.
2. Search for and select **`Mail.ReadWrite`** (read is not enough — the
   system also marks messages as read once processed).
3. Click **Add permissions**, then click **Grant admin consent for Okelcor**
   (requires a Global Admin) — without this step, every API call will be
   rejected even with a valid token.

**Optional but recommended — restrict the app to just this one mailbox.**
By default, an app with `Mail.ReadWrite` (Application permission) can read
**every mailbox in the tenant**, not just `support@`. To restrict it to
only `support@okelcor.com`, a Global Admin can run this in
[Exchange Online PowerShell](https://learn.microsoft.com/en-us/powershell/exchange/connect-to-exchange-online-powershell):

```powershell
New-ApplicationAccessPolicy -AppId "<client-id-from-step-1>" `
  -PolicyScopeGroupId "support@okelcor.com" `
  -AccessRight RestrictAccess `
  -Description "Restrict Okelcor inbound mail reader to support@ only"
```

Not required for this to work, but good practice (least privilege) — worth
doing if someone with Exchange PowerShell access is available.

## 4. Confirm plus-addressing is enabled on the tenant

The system tells replies apart using an address like
`support+abc123@okelcor.com` (the part after `+` identifies which message
it's a reply to). Exchange Online supports this ("plus addressing"), and
it's on by default for most tenants, but it can be explicitly disabled.
A Global Admin can check/enable it via Exchange Online PowerShell:

```powershell
Get-OrganizationConfig | Select DisablePlusAddressInRecipients
# If this returns True, enable plus addressing:
Set-OrganizationConfig -DisablePlusAddressInRecipients $false
```

**If it can't be enabled for some reason:** the system still works without
it, just slightly less reliably — it falls back to matching by the e-mail's
own reply-thread headers, then by the customer's e-mail address.

## 5. Set the `.env` values

```env
MAIL_INBOUND_ENABLED=true
MAIL_INBOUND_ADDRESS=support@okelcor.com
MAIL_INBOUND_MS_TENANT_ID=
MAIL_INBOUND_MS_CLIENT_ID=
MAIL_INBOUND_MS_CLIENT_SECRET=
MAIL_INBOUND_MESSAGE_ID_DOMAIN=okelcor.com
```

`MAIL_INBOUND_ENABLED=true` is what actually switches the feature on —
until this is set, outgoing e-mails keep working exactly as they do today
(reply goes to the sending admin's own address, nothing changes).

`MAIL_INBOUND_OWN_DOMAIN` is optional — only set it if Okelcor's outgoing
"From" address (`MAIL_FROM_ADDRESS`) is ever on a *different* domain than
`okelcor.com` in some environment; otherwise leave it unset and the system
figures the own-domain out from `MAIL_FROM_ADDRESS` automatically.

After setting these, run the standard config-cache refresh
(`artisan config:clear && artisan config:cache`) — no code redeploy needed
if the code is already live.

## 6. Nothing else to schedule

The system already checks this mailbox automatically every 5 minutes once
`MAIL_INBOUND_ENABLED=true` — it reuses the same scheduled-task mechanism
already running hourly jobs (carrier tracking sync, follow-up reminders,
etc.), so as long as the server's cron is already running (it is, if those
other scheduled features already work), there's nothing extra to set up.

## 7. Test end to end

1. From the admin panel, send a test e-mail to a test customer account (or your own personal e-mail, added as a test customer).
2. Reply to that e-mail from the recipient side, as if you were the customer.
3. Within 5 minutes, check the customer's communication history in the
   admin panel — the reply should appear there, and the admin who sent the
   original message should get an in-app notification.
4. Double-check the *other* automated support@ mail (order/quote/digest
   notifications) is unaffected — those should keep arriving in the mailbox
   as normal and should **not** show up as new leads or communications
   (the own-domain guard from step 0 is what prevents that).

If step 3 doesn't work, the most likely causes, roughly in order of
likelihood: admin consent wasn't granted (step 3), the client secret was
mistyped or has expired, or the app registration's tenant doesn't match
`MAIL_INBOUND_MS_TENANT_ID`.

## A note on "staff still want to see it in Outlook too"

This feature doesn't take away the ability to see replies in Outlook — it
adds the system as a second place they show up. If staff still want a
personal copy of every reply in their own inbox as well, Outlook/Exchange
lets you set up an **inbox rule that forwards a copy** from
`support@okelcor.com` to a specific person's mailbox — that's a mailbox-side
setting, not something the app needs to know about. In practice, most teams
stop needing this once they get used to the in-app notification telling
them exactly when a specific customer has replied.
