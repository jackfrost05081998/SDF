<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_permission('products_manage');

$error = '';
$flash = consume_flash();

function selectedProductIds(): array
{
    $rawIds = $_POST['product_ids'] ?? [];

    if (!is_array($rawIds)) {
        return [];
    }

    $ids = [];

    foreach ($rawIds as $id) {
        $validId = filter_var($id, FILTER_VALIDATE_INT);

        if ($validId && $validId > 0) {
            $ids[] = (int) $validId;
        }
    }

    return array_values(array_unique($ids));
}

function productTemperatureLabel(string $value): string
{
    return match ($value) {
        'hot' => 'Hot only',
        'iced' => 'Iced only',
        default => 'Hot and iced',
    };
}

function validProductInput(): array
{
    $name = trim((string) ($_POST['name'] ?? ''));
    $basePrice = filter_var($_POST['base_price'] ?? null, FILTER_VALIDATE_FLOAT);
    $hotIced = (string) ($_POST['hot_iced'] ?? 'both');
    if (
        strlen($name) < 2 || strlen($name) > 120 ||
        $basePrice === false || $basePrice < 0 ||
        !in_array($hotIced, ['both', 'hot', 'iced'], true)
    ) {
        throw new RuntimeException(
            'Enter a product name, valid temperature, and a non-negative hot price.'
        );
    }
    return [
        'name' => $name,
        'base_price' => (float) $basePrice,
        'hot_iced' => $hotIced,
        // Fixed business rules — not editable in the admin form.
        'iced_extra' => 10.00,
        'size_16_extra' => 20.00,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'This request is invalid or expired. Refresh the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        try {
            /*
             * Create a new active product.
             */
            if ($action === 'create') {
                $product = validProductInput();

                $statement = db()->prepare(
                    'INSERT INTO products
                    (name, base_price, hot_iced, iced_extra, size_16_extra, is_active)
                    VALUES (?, ?, ?, ?, ?, 1)'
                );

                $statement->execute([
                    $product['name'],
                    $product['base_price'],
                    $product['hot_iced'],
                    $product['iced_extra'],
                    $product['size_16_extra'],
                ]);

                audit((int) current_user()['id'], 'Created product', $product['name']);
                flash('success', 'Product added to the active menu.');

                header('Location: products.php');
                exit;
            }

            /*
             * Edit an existing active product.
             */
            if ($action === 'update') {
                $productId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
                $product = validProductInput();

                if (!$productId || $productId < 1) {
                    throw new RuntimeException('The product to update is invalid.');
                }

                $statement = db()->prepare(
                    'UPDATE products
                    SET name = ?, base_price = ?, hot_iced = ?,
                        iced_extra = ?, size_16_extra = ?
                    WHERE id = ? AND is_active = 1'
                );

                $statement->execute([
                    $product['name'],
                    $product['base_price'],
                    $product['hot_iced'],
                    $product['iced_extra'],
                    $product['size_16_extra'],
                    $productId,
                ]);

                if ($statement->rowCount() === 0) {
                    throw new RuntimeException(
                        'The product was not found or has already been deleted.'
                    );
                }

                audit((int) current_user()['id'], 'Updated product', $product['name']);
                flash('success', 'Product details were updated.');

                header('Location: products.php');
                exit;
            }

            /*
             * Soft delete one active product.
             * It stays in MySQL and can later be restored.
             */
            if ($action === 'soft_delete') {
                $productId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);

                if (!$productId || $productId < 1) {
                    throw new RuntimeException('The product is invalid.');
                }

                $statement = db()->prepare(
                    'UPDATE products
                    SET is_active = 0
                    WHERE id = ? AND is_active = 1'
                );

                $statement->execute([$productId]);

                if ($statement->rowCount() === 0) {
                    throw new RuntimeException(
                        'The product was not found or was already deleted.'
                    );
                }

                audit(
                    (int) current_user()['id'],
                    'Moved product to deleted products',
                    'Product ID ' . $productId
                );

                flash('success', 'Product moved to Deleted Products.');

                header('Location: products.php');
                exit;
            }

            /*
             * Restore one or many selected deleted products.
             */
            if ($action === 'bulk_restore') {
                $ids = selectedProductIds();

                if (!$ids) {
                    throw new RuntimeException('Select at least one deleted product to restore.');
                }

                $placeholders = implode(',', array_fill(0, count($ids), '?'));

                $statement = db()->prepare(
                    "UPDATE products
                     SET is_active = 1
                     WHERE is_active = 0
                     AND id IN ($placeholders)"
                );

                $statement->execute($ids);

                audit(
                    (int) current_user()['id'],
                    'Restored deleted products',
                    count($ids) . ' product(s)'
                );

                flash('success', $statement->rowCount() . ' product(s) restored to the active menu.');

                header('Location: products.php');
                exit;
            }

            /*
             * Permanently delete one or many products.
             *
             * The database schema has ON DELETE SET NULL for order_items.product_id.
             * Old receipts keep their product_name, unit_price, quantity, and total.
             */
            if ($action === 'bulk_permanent_delete') {
                $ids = selectedProductIds();

                if (!$ids) {
                    throw new RuntimeException(
                        'Select at least one deleted product to permanently delete.'
                    );
                }

                $pdo = db();
                $pdo->beginTransaction();

                $placeholders = implode(',', array_fill(0, count($ids), '?'));

                $findProducts = $pdo->prepare(
                    "SELECT name
                     FROM products
                     WHERE is_active = 0
                     AND id IN ($placeholders)"
                );
                $findProducts->execute($ids);
                $productsToDelete = $findProducts->fetchAll();

                if (!$productsToDelete) {
                    throw new RuntimeException('No selected deleted products were found.');
                }

                $deleteStatement = $pdo->prepare(
                    "DELETE FROM products
                     WHERE is_active = 0
                     AND id IN ($placeholders)"
                );
                $deleteStatement->execute($ids);

                $names = array_column($productsToDelete, 'name');

                audit(
                    (int) current_user()['id'],
                    'Permanently deleted products',
                    implode(', ', $names)
                );

                $pdo->commit();

                flash(
                    'success',
                    $deleteStatement->rowCount() . ' product(s) permanently deleted.'
                );

                header('Location: products.php');
                exit;
            }
        } catch (Throwable $exception) {
            if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            if ($exception instanceof PDOException) {
                $error = 'The product action could not be completed. Check that the product name is valid.';
            } else {
                $error = $exception->getMessage();
            }
        }
    }
}

$activeProducts = db()->query(
    'SELECT *
     FROM products
     WHERE is_active = 1
     ORDER BY name ASC'
)->fetchAll();

$deletedProducts = db()->query(
    'SELECT *
     FROM products
     WHERE is_active = 0
     ORDER BY updated_at DESC, name ASC'
)->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manage Products | Beanson Brew Cafe</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="kiosk-page admin-page">
<header class="topbar">
    <div>
        <a class="logo" href="../index.php">Beanson Brew Cafe <span>— Administration</span></a>
    </div>

    <div class="top-actions">
        <?php if ((current_user()['role'] ?? '') === 'admin'): ?>
            <a class="button" href="reports.php">Sales Reports</a>
            <a class="button" href="users.php">Accounts</a>
        <?php endif; ?>
        <?php if (has_permission('pos_access')): ?>
            <a class="button" href="../index.php">Back to POS</a>
        <?php endif; ?>
    </div>
</header>

<main class="products-page">
    <?php if ($flash): ?>
        <div class="alert <?= e($flash['type']) ?> page-alert">
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error page-alert">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <section class="panel add-product-panel">
        <div class="panel-title">
            <div>
                <h1>Add Drinks</h1>
            </div>
        </div>

        <form method="post" class="product-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="create">

            <label>
                Product name
                <input name="name" required maxlength="120" placeholder="Example: Cafe Latte">
            </label>

            <label>
                Base price (Hot · 12 Oz)
                <input name="base_price" type="number" min="0" step="0.01" required placeholder="0.00">
            </label>

            <label>
                Available temperature
                <select name="hot_iced">
                    <option value="both">Hot and Iced</option>
                    <option value="hot">Hot only</option>
                    <option value="iced">Iced only</option>
                </select>
            </label>

            <button class="button primary" type="submit">Add Product</button>
        </form>
    </section>

    <section class="panel active-products-panel">
        <div class="panel-title panel-title-row">
            <div>
                <h1>Available Drinks</h1>
            </div>
            <span class="count-label"><?= count($activeProducts) ?> product(s)</span>
        </div>

        <div class="table-scroll">
            <table class="product-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Base Price</th>
                        <th>Temperature</th>
                        <th class="actions-column">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$activeProducts): ?>
                    <tr>
                        <td colspan="4" class="empty-cell">
                            There are no active products. Add a product using the form.
                        </td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($activeProducts as $product): ?>
                    <?php
                    $productJson = json_encode([
                        'id' => (int) $product['id'],
                        'name' => $product['name'],
                        'base_price' => $product['base_price'],
                        'hot_iced' => $product['hot_iced'],
                        'iced_extra' => $product['iced_extra'],
                        'size_16_extra' => $product['size_16_extra'],
                    ], JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                    ?>
                    <tr>
                        <td><strong><?= e($product['name']) ?></strong></td>
                        <td><?= peso((float) $product['base_price']) ?></td>
                        <td><?= e(productTemperatureLabel($product['hot_iced'])) ?></td>
                        <td class="row-actions">
                            <button
                                class="button small"
                                type="button"
                                data-edit-product="<?= e((string) $productJson) ?>"
                            >
                                Edit
                            </button>

                            <form
                                method="post"
                                class="inline-form"
                                onsubmit="return confirm('Move <?= e($product['name']) ?> to Deleted Products? You can restore it later.');"
                            >
                                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                <input type="hidden" name="action" value="soft_delete">
                                <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
                                <button class="button small delete-button" type="submit">Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <section class="panel deleted-products-panel">
        <form method="post" id="deletedProductsForm">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

            <div class="panel-title deleted-title">
                <div>
                    <h1>Deleted Drinks</h1>
                </div>
            </div>

            <div class="table-scroll">
                <table class="product-table">
                    <thead>
                        <tr>
                            <th class="checkbox-column">
                                <input
                                    type="checkbox"
                                    id="selectAllDeleted"
                                    aria-label="Select all deleted products"
                                >
                            </th>
                            <th>Name</th>
                            <th>Base Price</th>
                            <th>Temperature</th>
                            <th>Deleted / Updated</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$deletedProducts): ?>
                        <tr>
                            <td colspan="5" class="empty-cell">No deleted products.</td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($deletedProducts as $product): ?>
                        <tr>
                            <td class="checkbox-column">
                                <input
                                    type="checkbox"
                                    class="deleted-product-checkbox"
                                    name="product_ids[]"
                                    value="<?= (int) $product['id'] ?>"
                                    aria-label="Select <?= e($product['name']) ?>"
                                >
                            </td>
                            <td><strong><?= e($product['name']) ?></strong></td>
                            <td><?= peso((float) $product['base_price']) ?></td>
                            <td><?= e(productTemperatureLabel($product['hot_iced'])) ?></td>
                            <td><?= e(date('M d, Y · h:i A', strtotime($product['updated_at']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="deleted-footer">
                <span class="selected-count" id="selectedCountBottom">0 selected</span>

                <div class="bulk-actions">
                    <button
                        class="button"
                        type="submit"
                        name="action"
                        value="bulk_restore"
                        id="restoreSelectedBottom"
                        disabled
                    >
                        Restore Selected
                    </button>

                    <button
                        class="button delete-button"
                        type="submit"
                        name="action"
                        value="bulk_permanent_delete"
                        id="deletePermanentlyBottom"
                        disabled
                    >
                        Delete Permanently
                    </button>
                </div>
            </div>
        </form>
    </section>
</main>

<dialog id="editProductDialog" class="edit-product-dialog">
    <form method="post" class="product-form" id="editProductForm">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id" id="editProductId">

        <div class="dialog-heading">
            <div>
                <p class="eyebrow">Active menu product</p>
                <h2>Edit Product</h2>
            </div>
            <button class="icon-button" type="button" id="closeEditDialog" aria-label="Close edit form">×</button>
        </div>

        <label>
            Product name
            <input name="name" id="editName" required maxlength="120">
        </label>

        <div class="two-columns">
            <label>
            Base price (Hot · 12 Oz)
            <input name="base_price" id="editBasePrice" type="number" min="0" step="0.01" required>
            </label>

            <label>
                Available temperature
                <select name="hot_iced" id="editTemperature">
                    <option value="both">Hot and Iced</option>
                    <option value="hot">Hot only</option>
                    <option value="iced">Iced only</option>
                </select>
            </label>
        </div>

        <div class="dialog-actions">
            <button class="button" type="button" id="cancelEditDialog">Cancel</button>
            <button class="button primary" type="submit">Save Changes</button>
        </div>
    </form>
</dialog>

<script src="../assets/js/products.js"></script>
</body>
</html>