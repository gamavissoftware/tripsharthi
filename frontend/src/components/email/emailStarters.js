/**
 * Starter layouts for the email editor. Table-based with inline styles on
 * purpose: Outlook and Gmail ignore <style> blocks and flexbox, and this is
 * the markup every mail client renders the same.
 */

const shell = (inner) => `<!DOCTYPE html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f5f7;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
${inner}
</table>
</td></tr></table>
</body></html>`

export const STARTERS = [
  {
    key: 'newsletter',
    name: 'Newsletter',
    subject: 'What\'s new this month, {{contact.first_name|there}}',
    preheader: 'Three quick updates from our team',
    html: shell(`<tr><td style="padding:28px 32px 8px;font-size:22px;font-weight:bold;">Your monthly update</td></tr>
<tr><td style="padding:8px 32px 16px;font-size:15px;line-height:1.6;">Hi {{contact.first_name|there}},<br><br>Here is what we have been working on this month.</td></tr>
<tr><td style="padding:0 32px 16px;font-size:15px;line-height:1.6;"><strong>1. First headline</strong><br>A sentence or two about it.</td></tr>
<tr><td style="padding:0 32px 16px;font-size:15px;line-height:1.6;"><strong>2. Second headline</strong><br>A sentence or two about it.</td></tr>
<tr><td style="padding:8px 32px 28px;"><a href="https://example.com" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:6px;font-weight:bold;font-size:15px;">Read more</a></td></tr>`),
  },
  {
    key: 'offer',
    name: 'Offer / announcement',
    subject: '{{contact.first_name|Hi}}, a special offer for you',
    preheader: 'Valid this week only',
    html: shell(`<tr><td style="padding:36px 32px 8px;text-align:center;font-size:26px;font-weight:bold;">20% off, this week only</td></tr>
<tr><td style="padding:8px 32px 20px;text-align:center;font-size:15px;line-height:1.6;color:#4b5563;">Hi {{contact.first_name|there}}, as one of our customers you get early access.</td></tr>
<tr><td style="padding:0 32px 36px;text-align:center;"><a href="https://example.com" style="display:inline-block;background:#16a34a;color:#ffffff;text-decoration:none;padding:14px 28px;border-radius:6px;font-weight:bold;font-size:16px;">Claim the offer</a></td></tr>`),
  },
  {
    key: 'letter',
    name: 'Plain letter',
    subject: 'A quick note, {{contact.first_name|there}}',
    preheader: '',
    html: shell(`<tr><td style="padding:32px;font-size:15px;line-height:1.7;">Hi {{contact.first_name|there}},<br><br>Write your message here. Plain, personal emails often get more replies than designed ones.<br><br>Thanks,<br>Your name</td></tr>`),
  },
]

export const MERGE_TAGS = [
  { tag: '{{contact.first_name|there}}', label: 'First name' },
  { tag: '{{contact.name}}',             label: 'Full name' },
  { tag: '{{contact.email}}',            label: 'Email' },
  { tag: '{{contact.wa_number}}',        label: 'WhatsApp number' },
  { tag: '{{contact.city}}',             label: 'City' },
  { tag: '{{contact.job_title}}',        label: 'Job title' },
  { tag: '{{unsubscribe_url}}',          label: 'Unsubscribe link URL' },
]
