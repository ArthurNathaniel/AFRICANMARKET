# Jenmond Mobile African Market

An online store, built with PHP and MySQL, that sells authentic African products to customers across the USA. One shop, run by your admin team.

## Setup (XAMPP)

1. Copy this folder to `C:\xampp\htdocs\jenmond`.
2. Start **Apache** and **MySQL** in the XAMPP Control Panel.
3. Open http://localhost/jenmond/install.php and create your super-admin account. Tick "Load demo data" if you want sample products, customers and orders.
4. The store is at http://localhost/jenmond/ and the admin panel is at http://localhost/jenmond/admin/.

Database settings are in `config/config.php`. The defaults match XAMPP: user `root`, no password, database `african_market`.

Demo customers can sign in with their email (see Admin → Customers) and the password `Customer123`.

## Payments

- **Stripe (the only payment gateway):** in **Admin → Settings → Payment gateways**, paste your keys from the Stripe dashboard (**Developers → API keys**). There are separate fields for **test** keys (`pk_test_…` / `sk_test_…`) and **live** keys (`pk_live_…` / `sk_live_…`); the **Active mode** dropdown picks which set checkout uses. Use **Test connection** to check a key. Checkout redirects to Stripe's secure payment page and the payment is confirmed when the customer returns to `order-success.php`.
- **Test mode:** no real money moves. Checkout shows test cards to use on the Stripe page, e.g. `4242 4242 4242 4242` (succeeds), `4000 0025 0000 3155` (3D Secure) and `4000 0000 0000 9995` (declined), with any future expiry, any CVC and any ZIP. Test payments appear in the Stripe dashboard with **Test mode** switched on.
- **Webhook (recommended):** in Stripe, add an endpoint for `https://YOUR-DOMAIN/stripe-webhook.php` with the event `checkout.session.completed` (once in test mode, once in live mode) and paste each signing secret (`whsec_…`) into the matching Settings field. It marks orders paid even if the customer closes the tab before returning. Locally you can use `stripe listen --forward-to localhost/AFRICANMARKET/stripe-webhook.php`.
- **Without Stripe keys:** in development mode only, checkout simulates a successful payment so you can test the full flow. In production, checkout is disabled until Stripe is configured.

## Before going live

- In `config/config.php`, set `APP_ENV` to `'production'` and change `ADMIN_INVITE_CODE`.
- Use a dedicated MySQL user with a strong password, not `root`.
- Serve the site over HTTPS.
- Configure outgoing email (SMTP) so receipts, shipping updates and password resets are delivered.
- Review the placeholder marketing copy on the home page and the shipping notes in Admin → Shipping.

## How orders work

1. A customer checks out. They can be a guest or signed in.
2. The order is created as **pending / unpaid**.
3. Once payment is confirmed, the order becomes **processing / paid**. Stock is reduced and a receipt is emailed. If a support email is set in Settings, your team also gets an email.
4. An admin packs the order, then sets it to **Shipped** with a carrier and tracking number. The customer gets an email with a tracking link.
5. When the package arrives, an admin sets the order to **Delivered**.

Customers can follow their order in **My account → Orders**. Guests use **Track an order**, entering their order number and email.

## Admin roles

- **Super admin:** full access, including payment keys and approving new admins.
- **Admin:** runs the shop day to day.
- **Manager:** products, stock, reviews and shipping. Managers cannot delete products or issue refunds.

New admins sign up at `admin/signup.php` with the invite code, then wait for approval in **Settings → Admin team**.

## Structure

```
index.php, shop.php, product.php, cart.php, checkout.php,
order-success.php, order-tracking.php, login.php, register.php,
forgot-password.php, reset-password.php, logout.php, stripe-webhook.php   Storefront
account/      Customer area (dashboard, orders, wishlist, addresses, profile)
admin/        Admin panel
partials/     Storefront layout pieces
includes/     functions.php (helpers), auth.php (admin auth), accounts.php (customer auth), shop.php (cart, orders, Stripe)
assets/       CSS, JS, logo (logo.svg, logo-light.svg, logo-mark.svg)
database/     schema.sql, demo_seed.php
uploads/      Product images (PHP execution disabled)
```
