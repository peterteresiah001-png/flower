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
  gateway with two modes: **Demo Mode** (no credentials, simulates the
  prompt) and **Daraja Sandbox** (real free Safaricom sandbox API).
- `includes/class-wc-gateway-hosted-checkout.php` — IntaSend/Pesapal
  hosted checkout gateway, same two modes.
- `includes/class-whatsapp-dispatch.php` — adds a "Dispatch via WhatsApp"
  box to each order (WP Admin and the Dokan vendor dashboard) that opens
  a pre-filled `wa.me` link so a vendor/admin can manually forward the
  order to a rider.
- `includes/class-commission-setup.php` — sets Dokan's global commission
  to Admin 20% / Vendor 80% on activation (editable in Dokan settings).
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

### IntaSend / Pesapal (card/bank)
- **Demo Mode**: customer is redirected to a plain local page with
  "Simulate Successful Payment" / "Simulate Failed Payment" buttons —
  useful for testing the checkout flow before you have API keys.
- **Sandbox**: get free test keys from intasend.com or
  developer.pesapal.com and switch the gateway to "Provider Sandbox."

## 5. Vendor commission (80/20)

Vendors keep 80% of each sale; the marketplace keeps 20%. This is
Dokan's native commission engine — no extra code needed beyond the
default set on activation. To change the split later, go to
**Dokan > Settings > Selling Options > Commission**.

## 6. Manual WhatsApp dispatch

On every order (WP Admin "Edit Order" screen, and the vendor's Dokan
order details page), you'll see a **Dispatch via WhatsApp** box:

1. Enter/confirm the rider's WhatsApp number.
2. Click **"Open WhatsApp to dispatch this order"** — this opens
   WhatsApp Web/App with a message pre-filled with the customer name,
   phone, items, delivery address, and total.
3. Send it manually to the rider. No automation, no third-party API —
   exactly the manual dispatch flow requested.

## 7. MVP scope & known limitations

- Pesapal sandbox integration is stubbed with the redirect/webhook
  structure in place — you'll need to add the token + SubmitOrderRequest
  calls per developer.pesapal.com once you have credentials (IntaSend is
  fully wired since it only needs a single checkout call).
- No custom CSS/theme — uses default WordPress/WooCommerce/Dokan
  screens and styling.
- Delivery/dispatch is manual by design (WhatsApp), not automated
  logistics.
- Recommended next steps post-MVP: vendor payout reconciliation reports,
  automated rider assignment, SMS notifications, review/rating system
  (Dokan Lite → Dokan Pro has some of this built in).
