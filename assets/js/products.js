(() => {
    const deletedForm = document.getElementById('deletedProductsForm');
    const selectAll = document.getElementById('selectAllDeleted');
    const selectedCount = document.getElementById('selectedCount');
    const selectedCountBottom = document.getElementById('selectedCountBottom');

    const restoreButtons = [
        document.getElementById('restoreSelected'),
        document.getElementById('restoreSelectedBottom')
    ];

    const permanentDeleteButtons = [
        document.getElementById('deletePermanently'),
        document.getElementById('deletePermanentlyBottom')
    ];

    function checkboxes() {
        return [...document.querySelectorAll('.deleted-product-checkbox')];
    }

    function updateBulkActions() {
        const allCheckboxes = checkboxes();
        const checked = allCheckboxes.filter((checkbox) => checkbox.checked);
        const count = checked.length;

        if (selectedCount) {
            selectedCount.textContent = `${count} selected`;
        }

        if (selectedCountBottom) {
            selectedCountBottom.textContent = `${count} selected`;
        }

        restoreButtons.forEach((button) => {
            if (button) button.disabled = count === 0;
        });

        permanentDeleteButtons.forEach((button) => {
            if (button) button.disabled = count === 0;
        });

        if (selectAll) {
            selectAll.checked = count > 0 && count === allCheckboxes.length;
            selectAll.indeterminate = count > 0 && count < allCheckboxes.length;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', () => {
            checkboxes().forEach((checkbox) => {
                checkbox.checked = selectAll.checked;
            });

            updateBulkActions();
        });
    }

    checkboxes().forEach((checkbox) => {
        checkbox.addEventListener('change', updateBulkActions);
    });

    if (deletedForm) {
        deletedForm.addEventListener('submit', (event) => {
            const submittedButton = event.submitter;

            if (!submittedButton) return;

            const selected = checkboxes().filter((checkbox) => checkbox.checked).length;

            if (selected === 0) {
                event.preventDefault();
                return;
            }

            if (submittedButton.value === 'bulk_permanent_delete') {
                const message =
                    `Permanently delete ${selected} selected product(s)?\n\n` +
                    'This cannot be undone. Previous sales receipts will remain readable.';

                if (!window.confirm(message)) {
                    event.preventDefault();
                }
            }
        });
    }

    const editDialog = document.getElementById('editProductDialog');
    const closeEditDialog = document.getElementById('closeEditDialog');
    const cancelEditDialog = document.getElementById('cancelEditDialog');

    const formFields = {
        id: document.getElementById('editProductId'),
        name: document.getElementById('editName'),
        basePrice: document.getElementById('editBasePrice'),
        temperature: document.getElementById('editTemperature')
    };

    document.querySelectorAll('[data-edit-product]').forEach((button) => {
        button.addEventListener('click', () => {
            try {
                const product = JSON.parse(button.dataset.editProduct);

                formFields.id.value = product.id;
                formFields.name.value = product.name;
                formFields.basePrice.value = Number(product.base_price).toFixed(2);
                formFields.temperature.value = product.hot_iced;

                editDialog.showModal();
                formFields.name.focus();
            } catch (error) {
                window.alert('The product details could not be loaded. Refresh the page and try again.');
            }
        });
    });

    function closeDialog() {
        if (editDialog && editDialog.open) {
            editDialog.close();
        }
    }

    if (closeEditDialog) {
        closeEditDialog.addEventListener('click', closeDialog);
    }

    if (cancelEditDialog) {
        cancelEditDialog.addEventListener('click', closeDialog);
    }

    if (editDialog) {
        editDialog.addEventListener('click', (event) => {
            if (event.target === editDialog) {
                closeDialog();
            }
        });
    }

    updateBulkActions();
})();