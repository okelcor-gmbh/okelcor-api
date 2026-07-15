# WhatsApp Business API Setup — What You Need To Do

The backend is built and ready. It cannot send or receive a single real
message until the steps below are done on the Meta (Facebook) side — this
is business/account setup, not something that can be done from code. Follow
these in order.

---

## 1. Meta Business Account (if you don't already have one)

Go to [business.facebook.com](https://business.facebook.com) and create a
Business Account for Okelcor if one doesn't already exist. You'll need to
verify the business — legal name, address, and a phone number Meta can
contact you on. This can take a few days if Meta asks for documents
(business registration, etc.), so start this step first.

## 2. Create a Meta Developer App

1. Go to [developers.facebook.com](https://developers.facebook.com) → **My Apps** → **Create App**.
2. Choose the **Business** app type, link it to the Business Account from step 1.
3. In the app dashboard, find **Add Product** and add **WhatsApp**.

## 3. Get a WhatsApp Business phone number

This is the number customers will message and that automated messages send from.

- **For testing:** Meta gives you a free test number automatically when you
  add the WhatsApp product — it only works with up to 5 phone numbers you
  manually add as "recipients" in the dashboard. Good enough to test
  everything below before going live.
- **For real use:** you need a dedicated phone number that is **not**
  already active on the regular WhatsApp or WhatsApp Business app on someone's
  phone — Meta requires the number to be fresh (or you formally migrate an
  existing WhatsApp Business number into the Cloud API, which Meta's
  dashboard has a guided flow for). This should probably be a new SIM/line,
  not the personal number of whoever currently runs Okelcor's WhatsApp Business app.
- Add and verify the number in the WhatsApp product's **API Setup** page (Meta sends an SMS/call code).

## 4. Get the four values the backend needs

From the WhatsApp product's **API Setup** page, and the app's **Settings → Basic** page:

| Value | Where to find it | Goes in `.env` as |
|---|---|---|
| Phone Number ID | API Setup page, under the phone number | `WHATSAPP_PHONE_NUMBER_ID` |
| WhatsApp Business Account ID | API Setup page (labeled "WhatsApp Business Account ID") | `WHATSAPP_BUSINESS_ACCOUNT_ID` |
| App Secret | Settings → Basic → App Secret ("Show") | `WHATSAPP_APP_SECRET` |
| Access Token | see step 5 — do **not** use the temporary 24-hour token the API Setup page shows by default | `WHATSAPP_ACCESS_TOKEN` |

## 5. Generate a permanent access token (don't use the temporary one)

The token shown on the API Setup page by default **expires in 24 hours** —
fine for a first test, useless for production. To get one that doesn't expire:

1. Go to **Business Settings** (business.facebook.com/settings) → **Users → System Users**.
2. Create a new System User (e.g. "Okelcor API"), role: **Admin**.
3. Under **Add Assets**, assign it the WhatsApp app from step 2 with **Full Control**.
4. Click **Generate New Token** on that System User, select the app, and
   check these permissions: `whatsapp_business_messaging`,
   `whatsapp_business_management`.
5. Copy the generated token immediately — Meta only shows it once. This is
   your `WHATSAPP_ACCESS_TOKEN`.

## 6. Set the `.env` values on the server

```env
WHATSAPP_PHONE_NUMBER_ID=
WHATSAPP_BUSINESS_ACCOUNT_ID=
WHATSAPP_ACCESS_TOKEN=
WHATSAPP_APP_SECRET=
WHATSAPP_VERIFY_TOKEN=      # you make this one up — any random string, see step 7
WHATSAPP_API_VERSION=v20.0  # optional, this is already the default
```

For `WHATSAPP_VERIFY_TOKEN`: pick any random string yourself (e.g. generate
one with `openssl rand -hex 20`) — it's not from Meta, it's a shared secret
*you* set on both sides so Meta can prove it's really Meta calling your
webhook the first time. Put the same value in `.env` and in step 7 below.

## 7. Point the webhook at this API

In the WhatsApp product's **Configuration** page:

- **Callback URL:** `https://api.okelcor.com/api/v1/webhooks/whatsapp`
- **Verify Token:** the exact same string you put in `WHATSAPP_VERIFY_TOKEN` above
- Click **Verify and Save** — Meta will call the URL once to confirm; this
  only works if the `.env` value is already deployed and correct.
- Under **Webhook fields**, subscribe to **messages** (this single
  subscription covers both incoming customer messages and delivery/read
  status updates — no need to subscribe to anything else for what's built).

## 8. Submit message templates for approval

Automated notifications (order shipped, payment reminders, etc.) legally
**cannot** be sent as plain text — WhatsApp requires a pre-approved
**template** for any message your business starts (as opposed to replying
to the customer within 24 hours of their own message, which can be free text).

Go to the WhatsApp product's **Message Templates** page and create these
(the backend already expects these exact names — using different names
means updating `WhatsAppNotifier::TEMPLATES` in the code to match):

| Template name | Category | Suggested body |
|---|---|---|
| `okelcor_order_shipped` | Utility | "Your Okelcor order {{1}} has shipped. Tracking number: {{2}}." |
| `okelcor_order_delivered` | Utility | "Your Okelcor order {{1}} has been delivered. Thank you for choosing Okelcor." |
| `okelcor_payment_reminder` | Utility | "This is a reminder that payment is outstanding for your Okelcor order {{1}}. Please contact us to arrange payment." |
| `okelcor_proposal_ready` | Utility | "Your Okelcor proposal {{1}} is ready for review. Please log in to your account or reply here." |
| `okelcor_quote_ready` | Utility | "Your Okelcor tyre quote {{1}} is ready. Please log in to your account to review it." |

Use the **Utility** category, not Marketing — these are transactional
(order/account updates a customer is already expecting), which gets
approved faster and costs less per message in most countries than Marketing
templates. Approval usually takes minutes to a few hours; Meta will reject
a template that reads as promotional, so keep the wording factual.

## 9. Consent — who can be messaged with a template

Meta requires **explicit opt-in** before you send someone a template
(business-initiated) message — this isn't optional, and sending to people
who never agreed risks the number being restricted. The backend already
defaults every customer's WhatsApp notifications to **off** until they
opt in (see the frontend note for where that toggle lives in the portal).

Practical ways to collect opt-in, in order of ease:
- A checkbox on the quote-request/registration form ("Send me order updates via WhatsApp").
- Ask once, by WhatsApp itself, the first time a customer messages your
  business number — a reply from them counts as consent for messages
  related to that conversation.

You do **not** need consent to *reply* to a customer within 24 hours of
their own message to you — that's always allowed, template or not.

## 10. Messaging limits (grows automatically, nothing to configure)

A new WhatsApp Business phone number starts capped at messaging **250
unique customers per rolling 24 hours** (Tier 1). This increases
automatically (1,000 → 10,000 → unlimited) based on volume and message
quality rating (customers blocking/reporting you lowers it) — there's
nothing to set up for this, just something to be aware of if Okelcor's
WhatsApp volume grows quickly.

## 11. Pricing

Meta charges per conversation, not per message, in a handful of categories
(Utility, Marketing, Authentication, Service). Customer-initiated
conversations (the customer messages first, you reply within 24h) are free
or very low cost in most regions; business-initiated template conversations
cost more, particularly Marketing category. Rates vary significantly by the
recipient's country — check
[Meta's current WhatsApp pricing page](https://developers.facebook.com/docs/whatsapp/pricing)
for the specific countries Okelcor's customers are in (mostly African
markets per the existing lead data) before estimating monthly cost, since
this changes and shouldn't be guessed here.

## 12. Test before going live

Using the free test number from step 3 (5 manually-added recipient
numbers), before switching to the real business number:
1. Send yourself a WhatsApp message from one of the 5 test recipient
   numbers to the test number — confirm it shows up as a new communication
   log entry / lead in the admin panel.
2. From the admin panel, reply to that test conversation — confirm it
   arrives on the test recipient's phone.
3. Once a template is approved, trigger the matching event (e.g. mark a
   test order as shipped) and confirm the template message arrives.

Once all three work, swap the phone number over to the real, verified
business number (step 3) and update `WHATSAPP_PHONE_NUMBER_ID` accordingly
— everything else stays the same.

---

## Summary — what's already built vs. what only you can do

| | |
|---|---|
| ✅ Backend: sending, receiving, lead capture, admin composer, opt-in preferences | Done, deployed once you follow the steps above |
| ⬜ Meta Business verification, phone number, tokens, webhook config, template approval | Only you (or whoever holds Okelcor's Meta Business account) can do this — no amount of code substitutes for it |
