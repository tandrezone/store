/**
 * Shared cart interactions. Talks to /api/cart.php via fetch.
 */
(function () {
    const CART_ENDPOINT = '/api/cart.php';

    function updateCartCount(count) {
        const badge = document.getElementById('cart-count');
        if (badge) badge.textContent = count;
    }

    async function cartRequest(action, params = {}) {
        const body = new URLSearchParams({ action, ...params });
        const response = await fetch(CART_ENDPOINT, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.error || 'Something went wrong.');
        }
        return data;
    }

    async function refreshCartCount() {
        try {
            const response = await fetch(CART_ENDPOINT + '?action=get');
            const data = await response.json();
            if (data.success) updateCartCount(data.count);
        } catch (e) {
            // Silently ignore — badge just won't update.
        }
    }

    // --- Product page: add to cart ---
    const addForm = document.getElementById('add-to-cart-form');
    if (addForm) {
        addForm.addEventListener('submit', async function (e) {
            e.preventDefault();
            const variantId = addForm.querySelector('#variant-select').value;
            const quantity = addForm.querySelector('#quantity-input').value;
            const message = document.getElementById('add-to-cart-message');

            try {
                const data = await cartRequest('add', { variant_id: variantId, quantity });
                updateCartCount(data.count);
                message.textContent = 'Added to cart.';
                message.className = 'form-message success';
            } catch (err) {
                message.textContent = err.message;
                message.className = 'form-message error';
            }
        });
    }

    // --- Cart page: update / remove line items ---
    document.querySelectorAll('.cart-qty-input').forEach((input) => {
        input.addEventListener('change', async function () {
            const variantId = input.dataset.variantId;
            const quantity = input.value;
            try {
                const data = await cartRequest('update', { variant_id: variantId, quantity });
                updateCartCount(data.count);
                document.getElementById('cart-total').textContent = Number(data.total).toFixed(2);
                const row = input.closest('tr');
                const item = data.items.find((i) => String(i.variant_id) === String(variantId));
                if (row && item) {
                    row.querySelector('.line-subtotal').textContent =
                        (item.price * item.quantity).toFixed(2) + '€';
                } else if (row && !item) {
                    row.remove(); // quantity dropped to 0 and was removed server-side
                }
            } catch (err) {
                alert(err.message);
            }
        });
    });

    document.querySelectorAll('.cart-remove-btn').forEach((btn) => {
        btn.addEventListener('click', async function () {
            const variantId = btn.dataset.variantId;
            try {
                const data = await cartRequest('remove', { variant_id: variantId });
                updateCartCount(data.count);
                document.getElementById('cart-total').textContent = Number(data.total).toFixed(2);
                btn.closest('tr').remove();
            } catch (err) {
                alert(err.message);
            }
        });
    });

    // --- Product page: image gallery ---
    document.querySelectorAll('[data-gallery]').forEach((gallery) => {
        const slides = Array.from(gallery.querySelectorAll('.gallery-slide'));
        const thumbs = Array.from(gallery.querySelectorAll('.gallery-thumb'));
        if (slides.length <= 1) return;

        let current = 0;

        function show(index) {
            current = (index + slides.length) % slides.length;
            slides.forEach((slide, i) => slide.classList.toggle('is-active', i === current));
            thumbs.forEach((thumb, i) => thumb.classList.toggle('is-active', i === current));
        }

        thumbs.forEach((thumb) => {
            thumb.addEventListener('click', () => show(Number(thumb.dataset.index)));
        });

        const prevBtn = gallery.querySelector('.gallery-prev');
        const nextBtn = gallery.querySelector('.gallery-next');
        if (prevBtn) prevBtn.addEventListener('click', () => show(current - 1));
        if (nextBtn) nextBtn.addEventListener('click', () => show(current + 1));
    });

    refreshCartCount();
})();
