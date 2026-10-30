(() => {
    const dialog = document.getElementById('refundDialog');
    const openButtons = document.querySelectorAll('[data-open-refund]');
    const closeButtons = document.querySelectorAll('[data-close-refund]');
    const form = document.getElementById('refundForm');
    const orderId = document.getElementById('refundOrderId');
    const receiptNo = document.getElementById('refundReceiptNo');
    const dialogTitle = document.getElementById('refundDialogTitle');
    const dialogTotal = document.getElementById('refundDialogTotal');
    const lineList = document.getElementById('refundLineList');

    if (!dialog || openButtons.length === 0 || !lineList) {
        return;
    }

    const pesos = (amount) =>
        '₱' + Number(amount).toLocaleString('en-PH', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        });

    // Recalculate the live refund total from the current quantity inputs.
    const recalcTotal = () => {
        let total = 0;

        lineList.querySelectorAll('[data-refund-line]').forEach((row) => {
            const unitPrice = Number(row.dataset.unitPrice) || 0;
            const input = row.querySelector('input[type="number"]');
            let qty = parseInt(input.value, 10);

            if (Number.isNaN(qty) || qty < 0) {
                qty = 0;
            }

            total += unitPrice * qty;
        });

        if (dialogTotal) {
            dialogTotal.textContent = pesos(total);
        }
    };

    // Render one editable row per refundable line.
    const buildLines = (items) => {
        lineList.innerHTML = '';

        items.forEach((item) => {
            const row = document.createElement('div');
            row.className = 'refund-line';
            row.dataset.refundLine = '';
            row.dataset.unitPrice = String(item.unit_price);

            const info = document.createElement('div');
            info.className = 'refund-line-info';

            const name = document.createElement('span');
            name.className = 'refund-line-name';
            name.textContent = item.name;
            info.appendChild(name);

            const meta = document.createElement('small');
            meta.className = 'refund-line-meta';
            const variant = item.variant ? item.variant + ' · ' : '';
            meta.textContent = `${variant}${pesos(item.unit_price)} each · ${item.remaining} refundable`;
            info.appendChild(meta);

            const control = document.createElement('div');
            control.className = 'refund-line-control';

            const input = document.createElement('input');
            input.type = 'number';
            input.min = '0';
            input.max = String(item.remaining);
            input.step = '1';
            input.value = '0';
            input.inputMode = 'numeric';
            input.name = `refund_qty[${item.id}]`;
            input.setAttribute('aria-label', `Quantity to refund for ${item.name}`);

            input.addEventListener('input', () => {
                let qty = parseInt(input.value, 10);

                if (Number.isNaN(qty) || qty < 0) {
                    qty = 0;
                }

                if (qty > item.remaining) {
                    qty = item.remaining;
                }

                input.value = String(qty);
                recalcTotal();
            });

            control.appendChild(input);
            row.appendChild(info);
            row.appendChild(control);
            lineList.appendChild(row);
        });

        recalcTotal();
    };

    const parseItems = (raw) => {
        if (!raw) {
            return [];
        }

        try {
            const parsed = JSON.parse(raw);
            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            return [];
        }
    };

    openButtons.forEach((button) => {
        button.addEventListener('click', () => {
            if (orderId) {
                orderId.value = button.dataset.orderId || '';
            }
            if (receiptNo) {
                receiptNo.value = button.dataset.receiptNo || '';
            }
            if (dialogTitle) {
                dialogTitle.textContent = `Refund ${button.dataset.receiptNo || 'Receipt'}`;
            }

            buildLines(parseItems(button.dataset.refundItems));

            dialog.showModal();
            const firstInput = lineList.querySelector('input[type="number"]');
            if (firstInput) {
                firstInput.focus();
                firstInput.select();
            }
        });
    });

    closeButtons.forEach((button) => {
        button.addEventListener('click', () => dialog.close());
    });

    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            dialog.close();
        }
    });

    if (form) {
        form.addEventListener('submit', (event) => {
            const submitButton = event.submitter;

            let selectedUnits = 0;
            lineList.querySelectorAll('input[type="number"]').forEach((input) => {
                const qty = parseInt(input.value, 10);
                if (!Number.isNaN(qty) && qty > 0) {
                    selectedUnits += qty;
                }
            });

            if (selectedUnits < 1) {
                event.preventDefault();
                window.alert('Enter a quantity of at least 1 for one or more items to refund.');
                return;
            }

            if (!submitButton || !window.confirm(
                'Authorize this refund? This action is recorded and cannot be undone.'
            )) {
                event.preventDefault();
                return;
            }

            submitButton.disabled = true;
            submitButton.textContent = 'Authorizing refund…';
        });
    }
})();
