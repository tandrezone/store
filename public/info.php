<?php
declare(strict_types=1);

$pageTitle = 'Info';
require __DIR__ . '/partials/header.php';
?>

<section class="content-page">
    <h1>Info</h1>
    <p class="page-subtitle">How ordering, payment, and shipping work.</p>

    <h2>How to order</h2>
    <p>
        Browse the shop, pick a variant on the product page, and add it to your cart.
        Your cart is saved to your session, so it's still there if you come back later
        on the same device and browser. When you're ready, go to checkout and fill in
        your shipping details.
    </p>

    <h2>Payment</h2>
    <p>
        Payment is handled by our payment provider, OxaPay, and settled in
        cryptocurrency. After checkout you'll be redirected to a secure payment page —
        your order is created first with a status of <em>pending</em>, and switches to
        <em>paid</em> automatically once the payment confirms on-chain. That
        confirmation can take a few minutes depending on network conditions.
    </p>

    <h2>Order tracking</h2>
    <p>
        We don't require an account. Every order gets an order number
        (e.g. <code>ORD-20260803-A1B2C3</code>) shown on the confirmation page and
        sent to the email you provided at checkout — keep both handy, since looking
        up an order requires the order number together with that email.
    </p>

    <h2>Shipping</h2>
    <p>
        Orders ship once payment is confirmed. Shipping times vary by item and
        destination; if a specific product page doesn't list a delivery estimate,
        assume a standard dispatch window and reach out via
        <a href="/support.php">Support</a> if it's been longer than expected.
    </p>

    <h2>Stock &amp; availability</h2>
    <p>
        Stock is checked at checkout, not just when an item is added to your cart —
        so on rare occasions an item that showed as available may turn out to be sold
        out by the time you pay. If that happens you'll see a clear message and won't
        be charged.
    </p>

    <p class="updated-note">Last updated August 2026.</p>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
