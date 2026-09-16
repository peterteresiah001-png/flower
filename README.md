# Flower, Gifts & Event Rentals Marketplace (Kenya) — MVP

A multi-vendor marketplace built on **WordPress + WooCommerce + Dokan (Lite, free)**,
with M-Pesa STK Push, IntaSend/Pesapal hosted checkout, an 80/20 vendor
commission split, and manual WhatsApp dispatch. No custom CSS — everything
uses default WordPress/WooCommerce/Dokan screens.

## 1. What's included

- `flower-marketplace-ke.php` — bootstraps everything, creates default
  product categories (Flowers, Gifts, Event Rentals), sets the 80/20
  commission default.
- `includes/class-wc-gateway-mpesa-stk.php` — M-Pesa STK Push payment
  gateway with three modes: **Demo Mode** (no credentials, simulates the
  prompt), **Daraja Sandbox** (real free Safaricom sandbox API), and
  **Daraja Production** (real payments — only sticks once the "Confirm
  Production Use" checkbox in gateway settings is ticked in the same
  save, so a fat-fingered dropdown change can't start taking real money
  on untested credentials). Sandbox/Production payment safety:
    - The Daraja callback URL carries a secret token, auto-generated on
      first use and stored in the `fmke_mpesa_callback_secret` option, so
      a forged POST to that URL (Daraja doesn't sign its callbacks) is
      rejected rather than marking an order paid.
    - The callback handler is idempotent — a duplicate or late callback
      for an already-resolved order is acknowledged and ignored rather
      than re-run.
    - A WP-Cron job (`fmke_mpesa_reconcile`, every 5 minutes, scheduled
      on activation) polls Daraja's status-query API for any order stuck
      "on-hold" for 3+ minutes with no callback, so a dropped/late
      callback doesn't leave an order (and the vendor's stock/earnings)
      stuck indefinitely. The same check is also available per-order as
      a "Check M-Pesa payment status now" action on the order edit
      screen (WooCommerce > Orders > [order] > Order actions), for when
      you don't want to wait for the next cron run.
- `includes/class-wc-gateway-hosted-checkout.php` — IntaSend/Pesapal card, bank and mobile-money
  hosted checkout gateway, same two modes.
- `includes/class-whatsapp-dispatch.php` — adds a "Dispatch via WhatsApp"
  box to each order (WP Admin and the Dokan vendor dashboard) that opens
  a pre-filled `wa.me` link so a vendor/admin can manually forward the
  order to a rider.
- `includes/class-commission-setup.php` — sets Dokan's global commission
  to Admin 20% / Vendor 80% on activation (editable in Dokan settings).
- `includes/class-vendor-earnings.php` — an **Earnings** page on the
  Dokan vendor dashboard (`/dashboard/earnings/`). Shows gross sales,
  marketplace commission, net earnings and order count for a selectable
  period (7 / 30 / 90 days or all time), plus a per-order table showing
  how each order was split. Figures are read from Dokan's own
  `dokan_orders` table — the commission is taken per order rather than
  recalculated at 20%, so historical orders and any vendor on a custom
  split still read correctly. Cancelled, refunded, failed and pending
  orders are excluded. Order dates are read from whichever order-storage
  backend is actually active — WooCommerce's High-Performance Order
  Storage (HPOS) table if HPOS is on, the classic `posts` table if not —
  detected directly rather than assumed, so earnings stay accurate even
  if a store later enables HPOS or turns off the "keep the posts table
  in sync" compatibility setting. If the page 404s after activation, go
  to **Settings > Permalinks** and click Save once to rebuild the
  dashboard rewrite rules.
- `includes/class-vendor-withdrawals.php` — a **Withdrawals** page on the
  Dokan vendor dashboard (`/dashboard/withdrawals/`) so vendors can cash
  out via M-Pesa or bank transfer. Shows the vendor's available balance
  (net earnings on *completed* orders, minus anything already requested),
  a request form, and a history table with status (pending / approved /
  paid / rejected). Requests are reviewed at **WP Admin > WooCommerce >
  Withdrawal Requests**, where the admin approves, rejects, or marks a
  request paid once the M-Pesa/bank transfer has actually been sent -
  email notifications go out to the admin on a new request and to the
  vendor on any status change. Minimum withdrawal is KES 500 (change the
  `MIN_WITHDRAWAL` constant in the class to adjust). Uses its own
  `{prefix}fmke_withdrawals` table rather than Dokan's built-in withdraw
  screen, since it needs to store M-Pesa numbers / bank details. The
  balance check and the insert are wrapped in a per-vendor MySQL named
  lock, so a double-click, page refresh, or two open tabs can't both
  slip past the balance check and jointly overdraw the vendor's real
  balance. M-Pesa numbers are normalized/validated to a proper
  2547XXXXXXXX / 2541XXXXXXXX format (07.../01... local format is also
  accepted and converted) and bank details get basic sanity checks,
  so a typo doesn't reach the admin's payout screen unflagged.
- `includes/class-vendor-reviews.php` — **store-level vendor reviews**,
  separate from WooCommerce's per-product reviews. Adds a ratings/reviews
  block to each vendor's Dokan store page (average rating, review list,
  and a form) plus a public reply box, restricted to logged-in customers
  who have a *completed* order with that specific vendor (one review per
  customer per vendor - submitting again edits their existing review).
  Vendors read and reply to their reviews from a **Reviews** page on the
  dashboard (`/dashboard/reviews/`); the admin can hide or delete any
  review at **WP Admin > WooCommerce > Vendor Reviews**. The homepage's
  Featured Vendors cards use this same rating once a vendor has store
  reviews, falling back to Dokan's product-review-based rating until
  then. Uses its own `{prefix}fmke_vendor_reviews` table.
- `includes/class-shop-sidebar.php` — left-hand category accordion on the
  shop/category pages.
- `includes/class-category-shortcuts.php` — a horizontally-scrollable
  row of small "shortcut" cards (icon/image + name) at the top of the
  shop and category pages, above the sort/result bar. Shows an "All"
  card plus the current category's siblings (or the top-level
  categories on the main shop page), with the active one highlighted -
  a fast way to jump between categories without using the sidebar,
  especially on mobile.
- `includes/class-homepage-banners.php` — three homepage promo banners
  (Flowers / Gifts / Event Rentals), auto-inserted at the top of the
  front page on Storefront, or placeable anywhere via the
  `[fmke_promo_banners]` shortcode. Uses each category's WooCommerce
  "Thumbnail" image if set (Products > Categories > edit), otherwise a
  plain colour fallback.
- `includes/class-order-notifications.php` — email sent at checkout: one
  summary to the admin with a per-vendor breakdown, and a separate email
  to each vendor with just their sub-order.
- `includes/class-order-status-notifications.php` — email sent on every
  order status change, to whichever of customer/vendor/admin the status
  actually concerns. The customer emails on this class can overlap with
  WooCommerce's own built-in customer emails; toggle each one off at
  **WooCommerce > Order Email Notifications** if you don't want both.
- `includes/class-sms-notifications.php` — SMS via Africa's Talking,
  sent the moment an order is confirmed paid (see Section 7).
- `includes/class-top-categories.php` — a "Top Categories" grid of
  circular tiles (image, name, product count), ordered by how many
  products are in each category. Auto-inserted on the front page just
  below the promo banners, or placeable anywhere via the
  `[fmke_top_categories count="8"]` shortcode. Unlike the fixed
  Flowers/Gifts/Event Rentals banners, this grid is data-driven, so it
  automatically picks up any subcategories a vendor adds later (e.g.
  "Roses", "Birthday Gifts"). The automatic homepage placement leaves
  out the three banner categories to avoid repeating them right below
  the banners; the shortcode shows all categories.

## 2. Required free plugins (install from WordPress.org before activating this one)

1. **WooCommerce**
2. **Dokan Lite (Multi-Vendor Marketplace)**
3. This plugin (`flower-marketplace-ke`) — upload as a zip via
   Plugins > Add New > Upload Plugin.

Optional but recommended free plugins:
- **WooCommerce Multilingual** or **Loco Translate** if you want Swahili.
- **WP Mail SMTP** so order emails actually deliver.

## 3. Installation order

1. Install WordPress, then WooCommerce, then Dokan Lite.
2. Run the WooCommerce and Dokan setup wizards (currency: KES, store
   country: Kenya).
3. Upload and activate `flower-marketplace-ke.php`.
4. Go to **WooCommerce > Settings > Payments** and enable:
   - "Pay with M-Pesa" (M-Pesa STK Push)
   - "Pay by Card / Bank" (IntaSend/Pesapal)
   Leave both in **Demo Mode** to test the full purchase flow first.
5. Go to **Dokan > Settings > Selling Options > Commission** and confirm
   it shows Admin 20% / Vendor 80% (the plugin sets this by default, but
   Dokan versions vary slightly, so double-check).
6. Go to **WooCommerce > WhatsApp Dispatch** and set your default rider
   WhatsApp number (used until a vendor sets one per order).

## 4. Testing payments (MVP simulation)

### M-Pesa STK Push
- **Demo Mode**: place an order, choose "Pay with M-Pesa", enter any
  phone number. The order goes `on-hold` with a note that a prompt was
  "sent," then auto-confirms as `processing` ~8 seconds later with a
  simulated receipt number — enough to demo/test the full checkout and
  order-management flow without any Safaricom account.
- **Daraja Sandbox** (real, still free): register at
  developer.safaricom.co.ke, create an app, and use the standard test
  shortcode `174379` with your sandbox passkey/consumer key/secret. This
  triggers a real STK push on a Safaricom test phone number and a real
  callback to your site.

### IntaSend / Pesapal (Visa, Mastercard, bank, mobile money)
- **Demo Mode**: customer is redirected to a plain local page with
  "Simulate Successful Payment" / "Simulate Failed Payment" buttons —
  useful for testing the checkout flow before you have API keys.
- **Sandbox**: get free test keys from intasend.com or
  developer.pesapal.com and switch the gateway to "Provider Sandbox."
- **Production**: same screen, set Mode to "Provider Production" and
  paste your live keys. Both providers are fully implemented — going
  live is a dropdown change, not a code change.

#### Pesapal specifics (API 3.0)
- Put your **consumer_key** in "Publishable Key / Pesapal Consumer Key"
  and your **consumer_secret** in "Secret Key / Pesapal Consumer Secret".
- The IPN URL is registered with Pesapal **automatically** on the first
  payment and cached, so there's nothing to paste into their dashboard.
  It is:
  `https://yourdomain.com/wc-api/wc_gateway_hosted_checkout/?fmke_pesapal_ipn=1`
  Tick **"Re-register my Pesapal IPN URL on the next payment"** in the
  gateway settings after changing your domain or credentials.
- The order is only marked paid after a server-side
  `GetTransactionStatus` call, and only if the amount Pesapal reports
  matches the order total (a mismatch goes `on-hold` for a human).
  Query params on the browser return are never trusted on their own.
- Card test numbers for the sandbox come from your Pesapal developer
  account — Pesapal rotates these, so use the ones on
  developer.pesapal.com rather than any hard-coded list.
- If an IPN never arrives (some hosts block outbound callbacks), open
  the order in WP Admin and run **Order actions > "Check card/bank
  payment status now"** to re-poll Pesapal for that one order.
- **Refunds**: for Pesapal orders, WooCommerce's normal Refund button
  submits a Pesapal `RefundRequest` using the stored confirmation code.
  Pesapal still requires you to approve it in their dashboard. IntaSend
  refunds are done in the IntaSend dashboard.

## 5. Vendor commission (80/20)

Vendors keep 80% of each sale; the marketplace keeps 20%. This is
Dokan's native commission engine — no extra code needed beyond the
default set on activation. To change the split later, go to
**Dokan > Settings > Selling Options > Commission**.

Each vendor can see their own side of this at **Vendor Dashboard >
Earnings**: gross sales, what the marketplace kept, and what's payable
to them, per period and per order.

## 6. Manual WhatsApp dispatch

On every order (WP Admin "Edit Order" screen, and the vendor's Dokan
order details page), you'll see a **Dispatch via WhatsApp** box:

1. Enter/confirm the rider's WhatsApp number.
2. Click **"Open WhatsApp to dispatch this order"** — this opens
   WhatsApp Web/App with a message pre-filled with the customer name,
   phone, items, delivery address, and total.
3. Send it manually to the rider. No automation, no third-party API —
   exactly the manual dispatch flow requested.

## 7. Notifications (email + SMS)

**Email** works out of the box via `wp_mail()` — no setup needed to start
testing. Two things to know before relying on it in production:

- WordPress's default mail transport (`mail()`) sends from
  `wordpress@yourdomain` with no SPF/DKIM, which many inboxes (Gmail,
  Outlook) spam-folder or drop outright. Install an SMTP plugin (e.g.
  WP Mail SMTP) pointed at a real sending service (Postmark, SES,
  Brevo, Gmail SMTP) before go-live — this plugin can't fix
  deliverability from PHP's `mail()` alone.
- The plugin's own customer status emails
  (`class-order-status-notifications.php`) overlap with WooCommerce's
  built-in customer emails for **on-hold, processing, completed and
  refunded**. Both are on by default. Go to **WooCommerce > Order Email
  Notifications** to turn off either side per status if you don't want
  a customer getting two emails for the same update.

**SMS** is via [Africa's Talking](https://africastalking.com) — the best
fit for Kenya on cost (~KES 0.80/SMS live) and reach (all three networks,
free sandbox). Configure at **WooCommerce > SMS Notifications**:

1. Sign up at africastalking.com and grab your API key. Use username
   `sandbox` to test for free before your account is fully set up.
2. Set Mode to **Demo** first — no API calls, no cost, the message that
   would have been sent is logged as an order note so you can confirm
   the trigger fires at the right moment.
3. Move to **Sandbox** to confirm the real API call works end-to-end
   (messages don't reach a real handset in sandbox).
4. Before **Live**, register an alphanumeric Sender ID (max 11
   characters) in the Africa's Talking dashboard — Safaricom, Airtel and
   Telkom all silently drop or reroute SMS from an unregistered ID.
   Registration takes 2–5 business days, so start it early.

Two events are wired up, both firing the moment an order is confirmed
paid (not when it's first placed — M-Pesa STK and card/bank payments
both confirm asynchronously after the order exists):

- **Customer**: "your order is confirmed" text with the total and a
  tracking link.
- **Vendor**: "new paid order" text with item count, total and customer
  contact — the moment a vendor should start preparing it. Reads the
  vendor's phone from their Dokan store profile.

Both toggle independently on the settings page. Vendor SMS is skipped if
the vendor hasn't set a phone number on their Dokan store profile;
customer SMS is skipped if the billing phone doesn't look like a valid
number once normalised to `+254...`.

## 8. MVP scope & known limitations

- Card/debit payments are live-ready on **both** providers: IntaSend
  (single checkout call) and Pesapal API 3.0 (token → IPN registration →
  SubmitOrderRequest → callback + IPN → GetTransactionStatus). Neither is
  stubbed any more; only Demo Mode is simulated.
- Pesapal's IPN needs your site to be publicly reachable over HTTPS, so
  card payments can't be fully tested from localhost — use a staging
  domain or a tunnel.
- No custom CSS/theme — uses default WordPress/WooCommerce/Dokan
  screens and styling.
- Delivery/dispatch is manual by design (WhatsApp), not automated
  logistics.
- Recommended next steps post-MVP: vendor payout reconciliation reports,
  automated rider assignment, review/rating system (Dokan Lite → Dokan
  Pro has some of this built in).
