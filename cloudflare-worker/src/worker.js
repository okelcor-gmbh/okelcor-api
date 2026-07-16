import PostalMime from 'postal-mime';

/**
 * Cloudflare Email Worker — receives inbound e-mail for the
 * reply.okelcor.com subdomain (via Cloudflare Email Routing), parses it,
 * and POSTs the result to the Okelcor API's inbound-e-mail webhook.
 *
 * Setup: see EMAIL_INBOUND_SETUP.md in the main okelcor-api repo for the
 * full step-by-step (Cloudflare Email Routing configuration, secret setup,
 * deployment). This file is deployed via `wrangler deploy` from this
 * directory, not copy-pasted into the Cloudflare dashboard's Quick Edit —
 * it needs the `postal-mime` npm dependency, which Quick Edit can't bundle.
 *
 * Required Worker secret (set via `wrangler secret put WEBHOOK_SECRET`,
 * never committed to this repo): a random string shared with the API's
 * MAIL_INBOUND_WEBHOOK_SECRET — used to sign every request so the API can
 * verify it really came from this Worker.
 *
 * Required Worker variable (set in wrangler.toml, not secret — it's just a
 * URL): API_URL, the full webhook endpoint
 * (https://api.okelcor.com/api/v1/webhooks/email-inbound).
 */
export default {
  async email(message, env, ctx) {
    let parsed;
    try {
      parsed = await new PostalMime().parse(message.raw);
    } catch (err) {
      console.error('Failed to parse inbound e-mail:', err);
      return; // drop silently rather than bounce/retry a malformed message
    }

    const payload = {
      from: parsed.from
        ? { address: parsed.from.address, name: parsed.from.name }
        : { address: message.from, name: message.from },
      to: (parsed.to || []).map((t) => ({ address: t.address, name: t.name })),
      subject: parsed.subject || '',
      html: parsed.html || '',
      text: parsed.text || '',
      messageId: parsed.messageId || null,
      inReplyTo: parsed.inReplyTo || null,
    };

    const body = JSON.stringify(payload);
    const signature = await hmacSha256Hex(env.WEBHOOK_SECRET, body);

    try {
      const response = await fetch(env.API_URL, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Webhook-Signature': signature,
        },
        body,
      });

      if (!response.ok) {
        console.error('Okelcor API rejected inbound e-mail webhook:', response.status, await response.text());
      }
    } catch (err) {
      console.error('Failed to POST inbound e-mail to Okelcor API:', err);
    }

    // Deliberately NOT calling message.forward()/message.setReject() —
    // we only need a copy delivered to the API, nothing further to do
    // with the message itself inside Cloudflare.
  },
};

async function hmacSha256Hex(secret, message) {
  const enc = new TextEncoder();
  const key = await crypto.subtle.importKey(
    'raw',
    enc.encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign']
  );
  const signature = await crypto.subtle.sign('HMAC', key, enc.encode(message));
  return Array.from(new Uint8Array(signature))
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('');
}
