Epilogue Reveal with MailerLite
Hide content until subscription, per author.
This plugin locks content on chosen post types and reveals it after the user subscribes via a MailerLite form selected by the post’s product_author term. Compatible with both new and old MailerLite embed codes.

Features
Settings page: Settings → Epilogue Reveal

Select which post types are protected.

Map MailerLite form HTML per product_author term.

Debug tool to clear all ep_reveal_ cookies* (browser-side).

Shortcode: [epilogue_reveal]…hidden content…[/epilogue_reveal]

Auto-detects the post’s first product_author term and renders the mapped form.

Intercepts form submission (old/new MailerLite) and sets an unlock cookie (ep_reveal_<postID>) on success.

Lightweight: no frontend CSS frameworks.

Installation
Upload to /wp-content/plugins/ and activate.

Go to Settings → Epilogue Reveal:

Tick the post types to protect.

Paste MailerLite embed HTML per product author.

Wrap protected content with:

php
Αντιγραφή
Επεξεργασία
[epilogue_reveal]
Your gated epilogue / preview content here.
[/epilogue_reveal]
How it works
On selected post types, the shortcode checks for ep_reveal_<postID> cookie.

If absent, it renders the mapped MailerLite form (by product_author).

On successful subscribe, JS sets the cookie (max-age=86400) and reloads to reveal content.

Requirements
WordPress

product_author taxonomy available and assigned to posts you protect

MailerLite embed codes (old or new)

Notes
If no form is configured for the detected author, a notice is shown.

Works with most themes/builders; no admin AJAX required.

If you use a strict CSP, allow the MailerLite domains used by your embed.
