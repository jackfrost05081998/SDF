(() => {
    const app = document.getElementById('posApp');

    if (!app) {
        return;
    }

    const products = JSON.parse(app.dataset.products);
    const csrfToken = app.dataset.csrf;
    const canCheckout = app.dataset.canCheckout !== 'false';

    const grid = document.getElementById('productGrid');
    const searchFilter = document.getElementById('searchFilter');

    const temperature = document.getElementById('temperature');
    const drinkSize = document.getElementById('drinkSize');

    const quantityLabel = document.getElementById('quantity');
    const selectedProductText = document.getElementById('selectedProductText');
    const priceExplanation = document.getElementById('priceExplanation');
    const addToOrder = document.getElementById('addToOrder');

    const cartBody = document.getElementById('cartBody');
    const emptyCart = document.getElementById('emptyCart');
    const cartTotal = document.getElementById('cartTotal');
    const cartItemCount = document.getElementById('cartItemCount');
    const clearCart = document.getElementById('clearCart');
    const checkoutButton = document.getElementById('checkoutButton');
    const cartDrawer = document.getElementById('cartDrawer');
    const cartBackdrop = document.getElementById('cartBackdrop');
    const openCartDrawer = document.getElementById('openCartDrawer');
    const closeCartDrawer = document.getElementById('closeCartDrawer');

    const checkoutDialog = document.getElementById('checkoutDialog');
    const checkoutForm = document.getElementById('checkoutForm');
    const checkoutTotal = document.getElementById('checkoutTotal');
    const closeCheckout = document.getElementById('closeCheckout');

    const paymentMethodInput = document.getElementById('paymentMethod');
    const paymentButtons = [...document.querySelectorAll('[data-payment-method]')];

    const cashPaymentFields = document.getElementById('cashPaymentFields');
    const cashReceived = document.getElementById('cashReceived');
    const changePreview = document.getElementById('changePreview');

    const digitalPaymentMessage = document.getElementById('digitalPaymentMessage');
    const digitalPaymentTitle = document.getElementById('digitalPaymentTitle');

    const completePaymentButton = document.getElementById('completePaymentButton');
    const checkoutError = document.getElementById('checkoutError');

    let selectedProduct = null;
    let selectedQuantity = 1;
    let paymentMethod = 'cash';
    let cart = [];

    function pesos(amount) {
        return `₱${Number(amount).toFixed(2)}`;
    }

    function escapeHtml(value) {
        const element = document.createElement('span');
        element.textContent = String(value);

        return element.innerHTML;
    }

    function cartTotalAmount() {
        return cart.reduce((total, item) => total + Number(item.line_total), 0);
    }

    function cartQuantityCount() {
        return cart.reduce((total, item) => total + Number(item.quantity), 0);
    }

    function productPrice(product, selectedTemperature, selectedSize) {
        let price = Number(product.base_price);

        if (selectedTemperature === 'iced') {
            price += Number(product.iced_extra);
        }

        if (selectedTemperature === 'iced' && selectedSize === '16 Oz') {
    price += Number(product.size_16_extra);
}

        return Number(price.toFixed(2));
    }

    function setTemperatureOptions() {
        if (!selectedProduct) {
            return;
        }

        [...temperature.options].forEach((option) => {
            option.disabled = (
                selectedProduct.hot_iced !== 'both'
                && option.value !== selectedProduct.hot_iced
            );
        });

        if (selectedProduct.hot_iced !== 'both') {
            temperature.value = selectedProduct.hot_iced;
        }
    }
function syncSizeWithTemperature() {
    const isHot = temperature.value === 'hot';
    if (isHot) {
        drinkSize.value = '12 Oz';
        drinkSize.disabled = true;
    } else {
        drinkSize.disabled = false;
    }
}
    function updateSelectedProduct() {
        quantityLabel.textContent = selectedQuantity;

        if (!selectedProduct) {
            if (selectedProductText) {
                selectedProductText.textContent = 'Select a drink or product from the menu.';
            }
            priceExplanation.textContent = '';
            addToOrder.disabled = true;
            return;
        }

        setTemperatureOptions();
        syncSizeWithTemperature();

        const unitPrice = productPrice(
            selectedProduct,
            temperature.value,
            drinkSize.value
        );
        const temperatureText = temperature.value === 'iced' ? 'Iced' : 'Hot';

        if (selectedProductText) {
            selectedProductText.textContent =
                `${selectedProduct.name} · ${temperatureText} · ${drinkSize.value}`;
        }

        let explanation = `₱${Number(selectedProduct.base_price).toFixed(2)} base`;

        if (temperature.value === 'iced' && Number(selectedProduct.iced_extra) > 0) {
            explanation += ` + ₱${Number(selectedProduct.iced_extra).toFixed(2)} iced`;
        }

        if (drinkSize.value === '16 Oz' && Number(selectedProduct.size_16_extra) > 0) {
            explanation += ` + ₱${Number(selectedProduct.size_16_extra).toFixed(2)} 16 Oz`;
        }

        explanation += ` = ${pesos(unitPrice)} each`;

        priceExplanation.textContent = explanation;
        addToOrder.disabled = false;
    }

    function renderProducts() {
        const searchTerm = searchFilter.value.trim().toLowerCase();
const filteredProducts = products.filter((product) => {
    return product.name.toLowerCase().includes(searchTerm);
});

        if (filteredProducts.length === 0) {
            grid.innerHTML = '<p class="empty-cart">No matching menu item found.</p>';
            return;
        }

        grid.innerHTML = filteredProducts.map((product) => `
            <button
                class="product-card ${selectedProduct?.id === product.id ? 'selected' : ''}"
                type="button"
                data-product-id="${product.id}"
            >
                <strong>${escapeHtml(product.name)}</strong>
                <span class="product-price">From ${pesos(product.base_price)}</span>
            </button>
        `).join('');

        grid.querySelectorAll('[data-product-id]').forEach((button) => {
            button.addEventListener('click', () => {
                selectedProduct = products.find(
                    (product) => product.id === Number(button.dataset.productId)
                );

                selectedQuantity = 1;
                renderProducts();
                updateSelectedProduct();
            });
        });
    }

    function renderCart() {
        if (cart.length === 0) {
            cartBody.innerHTML = '';
        } else {
            cartBody.innerHTML = cart.map((item, index) => `
                <tr>
                    <td class="cart-item-name">
                        <strong>${escapeHtml(item.name)}</strong>
                        <small>${item.temperature === 'iced' ? 'Iced' : 'Hot'} · ${escapeHtml(item.size)}</small>
                    </td>

                    <td class="cart-qty-column">
                        <div class="cart-quantity-control">
                            <button
                                type="button"
                                data-cart-action="decrease"
                                data-cart-index="${index}"
                                aria-label="Decrease ${escapeHtml(item.name)} quantity"
                            >−</button>

                            <span>${item.quantity}</span>

                            <button
                                type="button"
                                data-cart-action="increase"
                                data-cart-index="${index}"
                                aria-label="Increase ${escapeHtml(item.name)} quantity"
                            >+</button>
                        </div>
                    </td>

                    <td>${pesos(item.unit_price)}</td>
                    <td><strong>${pesos(item.line_total)}</strong></td>

                    <td class="cart-action-column">
                        <button
                            class="cart-remove-button"
                            type="button"
                            data-cart-action="remove"
                            data-cart-index="${index}"
                            aria-label="Remove ${escapeHtml(item.name)} from order"
                        >×</button>
                    </td>
                </tr>
            `).join('');
        }

        const isEmpty = cart.length === 0;
        const totalQuantity = cartQuantityCount();

        emptyCart.hidden = !isEmpty;
        cartTotal.textContent = pesos(cartTotalAmount());
        if (cartItemCount) {
            cartItemCount.textContent = `${totalQuantity} item${totalQuantity === 1 ? '' : 's'}`;
        }

        clearCart.disabled = isEmpty;
        checkoutButton.disabled = isEmpty || !canCheckout;
    }

    function updateCartQuantity(index, adjustment) {
        const item = cart[index];

        if (!item) {
            return;
        }

        const nextQuantity = item.quantity + adjustment;

        if (nextQuantity <= 0) {
            cart.splice(index, 1);
        } else if (nextQuantity <= 99) {
            item.quantity = nextQuantity;
            item.line_total = Number((item.unit_price * nextQuantity).toFixed(2));
        }

        renderCart();
    }

    function addSelectedProductToCart() {
        if (!selectedProduct) {
            return;
        }

        const unitPrice = productPrice(
            selectedProduct,
            temperature.value,
            drinkSize.value
        );

        const matchingItem = cart.find((item) => (
            item.product_id === selectedProduct.id
            && item.temperature === temperature.value
            && item.size === drinkSize.value
        ));

        if (matchingItem) {
            matchingItem.quantity = Math.min(
                99,
                matchingItem.quantity + selectedQuantity
            );

            matchingItem.line_total = Number(
                (matchingItem.quantity * matchingItem.unit_price).toFixed(2)
            );
        } else {
            cart.push({
                product_id: selectedProduct.id,
                name: selectedProduct.name,
                temperature: temperature.value,
                size: drinkSize.value,
                quantity: selectedQuantity,
                unit_price: unitPrice,
                line_total: Number((unitPrice * selectedQuantity).toFixed(2))
            });
        }

        renderCart();
        openCart();
    }

    function setPaymentMethod(method) {
        paymentMethod = method;
        paymentMethodInput.value = method;

        paymentButtons.forEach((button) => {
            const isSelected = button.dataset.paymentMethod === method;
            button.classList.toggle('is-selected', isSelected);
            button.setAttribute('aria-pressed', String(isSelected));
        });

        const isCash = method === 'cash';

        cashPaymentFields.hidden = !isCash;
        digitalPaymentMessage.hidden = isCash;
        cashReceived.required = isCash;

        if (isCash) {
            completePaymentButton.textContent = 'Complete Cash Payment';
            changePreview.textContent = '';
            return;
        }

        const label = method === 'gcash' ? 'GCash' : 'Maya';

        digitalPaymentTitle.textContent = `${label} Payment`;
        completePaymentButton.textContent = `Complete ${label} Payment`;
    }

    function updateCashPreview() {
        const enteredCash = Number(cashReceived.value);
        const total = cartTotalAmount();

        if (!Number.isFinite(enteredCash) || cashReceived.value === '') {
            changePreview.textContent = '';
            return;
        }

        const difference = enteredCash - total;

        if (difference >= 0) {
            changePreview.textContent = `Change: ${pesos(difference)}`;
        } else {
            changePreview.textContent = `Still needed: ${pesos(Math.abs(difference))}`;
        }
    }

    searchFilter.addEventListener('input', renderProducts);

temperature.addEventListener('change', () => {
    syncSizeWithTemperature();
    updateSelectedProduct();
});
    drinkSize.addEventListener('change', updateSelectedProduct);

    document.getElementById('increaseQty').addEventListener('click', () => {
        if (selectedQuantity < 99) {
            selectedQuantity++;
        }

        updateSelectedProduct();
    });

    document.getElementById('decreaseQty').addEventListener('click', () => {
        if (selectedQuantity > 1) {
            selectedQuantity--;
        }

        updateSelectedProduct();
    });

    addToOrder.addEventListener('click', addSelectedProductToCart);

    cartBody.addEventListener('click', (event) => {
        const button = event.target.closest('[data-cart-action]');

        if (!button) {
            return;
        }

        const index = Number(button.dataset.cartIndex);
        const action = button.dataset.cartAction;

        if (action === 'increase') {
            updateCartQuantity(index, 1);
        }

        if (action === 'decrease') {
            updateCartQuantity(index, -1);
        }

        if (action === 'remove') {
            cart.splice(index, 1);
            renderCart();
        }
    });

    clearCart.addEventListener('click', () => {
        if (cart.length === 0) {
            return;
        }

        if (window.confirm('Clear all items from this order?')) {
            cart = [];
            renderCart();
        }
    });

    checkoutButton.addEventListener('click', () => {
        if (!canCheckout) {
            return;
        }

        checkoutError.classList.add('hidden');
        checkoutTotal.textContent = pesos(cartTotalAmount());

        cashReceived.value = '';
        changePreview.textContent = '';

        setPaymentMethod('cash');
        checkoutDialog.showModal();
        cashReceived.focus();
    });

    closeCheckout.addEventListener('click', () => {
        checkoutDialog.close();
    });

    function openCart() {
        if (!cartDrawer) {
            return;
        }
        cartDrawer.classList.add('is-open');
        cartDrawer.setAttribute('aria-hidden', 'false');
        if (cartBackdrop) {
            cartBackdrop.classList.add('is-visible');
            cartBackdrop.setAttribute('aria-hidden', 'false');
        }
    }

    function closeCart() {
        if (!cartDrawer) {
            return;
        }
        cartDrawer.classList.remove('is-open');
        cartDrawer.setAttribute('aria-hidden', 'true');
        if (cartBackdrop) {
            cartBackdrop.classList.remove('is-visible');
            cartBackdrop.setAttribute('aria-hidden', 'true');
        }
    }

    if (openCartDrawer) {
        openCartDrawer.addEventListener('click', openCart);
    }
    if (closeCartDrawer) {
        closeCartDrawer.addEventListener('click', closeCart);
    }
    if (cartBackdrop) {
        cartBackdrop.addEventListener('click', closeCart);
    }
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeCart();
        }
    });

    paymentButtons.forEach((button) => {
        button.addEventListener('click', () => {
            setPaymentMethod(button.dataset.paymentMethod);
        });
    });

    cashReceived.addEventListener('input', updateCashPreview);

    checkoutForm.addEventListener('submit', async (event) => {
        event.preventDefault();

        checkoutError.classList.add('hidden');

        try {
            const response = await fetch('checkout.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    payment_method: paymentMethod,
                    cash_received: paymentMethod === 'cash'
                        ? cashReceived.value
                        : null,
                    cart: cart.map((item) => ({
                        product_id: item.product_id,
                        temperature: item.temperature,
                        size: item.size,
                        quantity: item.quantity
                    }))
                })
            });

            const result = await response.json();

            if (!result.ok) {
                throw new Error(result.message || 'Checkout failed.');
            }

            /*
             * Receipt has a Back to POS button after printing.
             * Redirecting is more reliable than popup windows in browsers.
             */
            window.location.assign(result.receipt_url);
        } catch (error) {
            checkoutError.textContent = error.message;
            checkoutError.classList.remove('hidden');
        }
    });

    renderProducts();
    renderCart();
})();