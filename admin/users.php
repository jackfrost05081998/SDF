<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_admin();

$permissionLabels = [
    'pos_access' => 'Access POS',
    'checkout' => 'Process checkout',
    'reports_view' => 'View sales reports',
    'products_manage' => 'Manage products',
];

function normalizeCashierPermissions(mixed $permissions, array $allowed): array
{
    if (!is_array($permissions)) {
        return [];
    }

    $validPermissions = array_values(array_intersect(
        $permissions,
        array_keys($allowed)
    ));

    /*
     * A cashier must have POS access in order to process a checkout.
     */
    if (
        in_array('checkout', $validPermissions, true)
        && !in_array('pos_access', $validPermissions, true)
    ) {
        $validPermissions[] = 'pos_access';
    }

    return array_values(array_unique($validPermissions));
}

function decodeCashierPermissions(?string $permissions): array
{
    $decoded = json_decode((string) $permissions, true);

    return is_array($decoded) ? $decoded : [];
}

$error = '';
$flash = consume_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf($_POST['csrf_token'] ?? null)) {
        $error = 'Your form session has expired. Refresh the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        try {
            /*
             * Update the currently logged-in administrator account.
             * The current password is required for security.
             */
            if ($action === 'update_admin_profile') {
                $fullName = trim((string) ($_POST['full_name'] ?? ''));
                $username = trim((string) ($_POST['username'] ?? ''));
                $currentPassword = (string) ($_POST['current_password'] ?? '');
                $newPassword = (string) ($_POST['new_password'] ?? '');

                if (strlen($fullName) < 2 || strlen($fullName) > 100) {
                    throw new RuntimeException('Enter an administrator name between 2 and 100 characters.');
                }

                if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
                    throw new RuntimeException(
                        'Username must be 3–50 characters and may contain letters, numbers, dots, underscores, or hyphens.'
                    );
                }

                if ($currentPassword === '') {
                    throw new RuntimeException(
                        'Enter your current password before updating your administrator account.'
                    );
                }

                if ($newPassword !== '' && strlen($newPassword) < 8) {
                    throw new RuntimeException('New password must contain at least 8 characters.');
                }

                $adminId = (int) current_user()['id'];

                $adminStatement = db()->prepare(
                    "SELECT password_hash
                     FROM users
                     WHERE id = ? AND role = 'admin'
                     LIMIT 1"
                );
                $adminStatement->execute([$adminId]);
                $admin = $adminStatement->fetch();

                if (!$admin || !password_verify($currentPassword, $admin['password_hash'])) {
                    throw new RuntimeException('Your current password is incorrect.');
                }

                $passwordHash = $newPassword !== ''
                    ? password_hash($newPassword, PASSWORD_DEFAULT)
                    : $admin['password_hash'];

                $updateStatement = db()->prepare(
                    "UPDATE users
                     SET full_name = ?, username = ?, password_hash = ?
                     WHERE id = ? AND role = 'admin'"
                );
                $updateStatement->execute([
                    $fullName,
                    $username,
                    $passwordHash,
                    $adminId,
                ]);

                $_SESSION['user']['full_name'] = $fullName;
                $_SESSION['user']['username'] = $username;

                audit($adminId, 'Updated administrator account');
                flash('success', 'Administrator account updated successfully.');

                header('Location: users.php');
                exit;
            }

            /*
             * Create or update a cashier account.
             */
            if ($action === 'save_cashier') {
                $cashierId = filter_var($_POST['cashier_id'] ?? null, FILTER_VALIDATE_INT);
                $fullName = trim((string) ($_POST['full_name'] ?? ''));
                $username = trim((string) ($_POST['username'] ?? ''));
                $password = (string) ($_POST['password'] ?? '');
                $isActive = isset($_POST['is_active']) ? 1 : 0;

                $permissions = normalizeCashierPermissions(
                    $_POST['permissions'] ?? [],
                    $permissionLabels
                );

                $permissionsJson = json_encode($permissions, JSON_THROW_ON_ERROR);

                if (strlen($fullName) < 2 || strlen($fullName) > 100) {
                    throw new RuntimeException('Enter a cashier name between 2 and 100 characters.');
                }

                if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
                    throw new RuntimeException(
                        'Username must be 3–50 characters and may contain letters, numbers, dots, underscores, or hyphens.'
                    );
                }

                if (!$cashierId && strlen($password) < 8) {
                    throw new RuntimeException(
                        'A new cashier account requires a password with at least 8 characters.'
                    );
                }

                if ($cashierId && $password !== '' && strlen($password) < 8) {
                    throw new RuntimeException(
                        'A replacement password must contain at least 8 characters.'
                    );
                }

                if ($cashierId) {
                    $cashierStatement = db()->prepare(
                        "SELECT id, password_hash
                         FROM users
                         WHERE id = ? AND role = 'cashier'
                         LIMIT 1"
                    );
                    $cashierStatement->execute([$cashierId]);
                    $cashier = $cashierStatement->fetch();

                    if (!$cashier) {
                        throw new RuntimeException('Cashier account was not found.');
                    }

                    $passwordHash = $password !== ''
                        ? password_hash($password, PASSWORD_DEFAULT)
                        : $cashier['password_hash'];

                    $updateStatement = db()->prepare(
                        "UPDATE users
                         SET full_name = ?, username = ?, password_hash = ?,
                             permissions = ?, is_active = ?
                         WHERE id = ? AND role = 'cashier'"
                    );
                    $updateStatement->execute([
                        $fullName,
                        $username,
                        $passwordHash,
                        $permissionsJson,
                        $isActive,
                        $cashierId,
                    ]);

                    audit(
                        (int) current_user()['id'],
                        'Updated cashier account',
                        $fullName
                    );

                    flash('success', $fullName . '\'s account was updated.');
                } else {
                    $insertStatement = db()->prepare(
                        "INSERT INTO users
                         (username, full_name, password_hash, role, permissions, is_active)
                         VALUES (?, ?, ?, 'cashier', ?, ?)"
                    );
                    $insertStatement->execute([
                        $username,
                        $fullName,
                        password_hash($password, PASSWORD_DEFAULT),
                        $permissionsJson,
                        $isActive,
                    ]);

                    audit(
                        (int) current_user()['id'],
                        'Created cashier account',
                        $fullName
                    );

                    flash('success', $fullName . '\'s cashier account was created.');
                }

                header('Location: users.php');
                exit;
            }
        } catch (PDOException $exception) {
            /*
             * Usually a duplicate username constraint error.
             * Do not show raw SQL/database details to the user.
             */
            $error = 'Could not save the account. The username may already be in use.';
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$adminStatement = db()->prepare(
    "SELECT id, username, full_name, created_at
     FROM users
     WHERE id = ? AND role = 'admin'
     LIMIT 1"
);
$adminStatement->execute([(int) current_user()['id']]);
$admin = $adminStatement->fetch();

$cashiers = db()->query(
    "SELECT id, username, full_name, permissions, is_active, created_at
     FROM users
     WHERE role = 'cashier'
     ORDER BY is_active DESC, full_name ASC"
)->fetchAll();

$activeCashiers = 0;

foreach ($cashiers as $cashier) {
    if ((int) $cashier['is_active'] === 1) {
        $activeCashiers++;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Accounts & Permissions | Beanson Brew Cafe</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="kiosk-page admin-page">
<header class="topbar">
    <div>
        <a class="logo" href="../index.php">
            Beanson Brew Cafe <span>— Administration</span>
        </a>
    </div>

    <nav class="top-actions" aria-label="Administration navigation">
        <a class="button" href="products.php">Products</a>
        <a class="button" href="reports.php">Sales Reports</a>
        <a class="button" href="../index.php">Back to POS</a>
    </nav>
</header>

<main class="accounts-page">
    <?php if ($flash): ?>
        <div class="alert <?= e($flash['type']) ?> accounts-alert">
            <?= e($flash['message']) ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error accounts-alert">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <section class="accounts-hero">
        <div>
            <h1>Accounts & Permissions</h1>
        </div>

        <div class="accounts-stats" aria-label="Account summary">
            <article>
                <span>Cashier accounts</span>
                <strong><?= count($cashiers) ?></strong>
            </article>
            <article>
                <span>Active cashiers</span>
                <strong><?= $activeCashiers ?></strong>
            </article>
        </div>
    </section>

    <div class="accounts-top-grid">
        <section class="account-card admin-profile-card">
            <div class="account-card-header">
                <div class="account-icon" aria-hidden="true">A</div>
                <div>
                    <h2>Administrator Profile</h2>
                </div>
            </div>

            <p class="account-card-description">
                Update Information.
            </p>

            <form method="post" class="account-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="update_admin_profile">

                <label>
                    Full name
                    <input
                        type="text"
                        name="full_name"
                        required
                        maxlength="100"
                        value="<?= e($admin['full_name'] ?? '') ?>"
                    >
                </label>

                <label>
                    Username
                    <input
                        type="text"
                        name="username"
                        required
                        maxlength="50"
                        value="<?= e($admin['username'] ?? '') ?>"
                    >
                </label>

                <div class="account-form-divider">
                    <span>Update Password</span>
                </div>

                <label>
                    Current password
                    <input
                        type="password"
                        name="current_password"
                        required
                        autocomplete="current-password"
                    >
                </label>

                <label>
                    New password
                    <input
                        type="password"
                        name="new_password"
                        minlength="8"
                        autocomplete="new-password"
                    >
                    <small>Leave blank to keep your current password.</small>
                </label>

                <button class="button primary account-submit" type="submit">
                    Save Administrator Account
                </button>
            </form>
        </section>

        <section class="account-card create-cashier-card">
            <div class="account-card-header">
                <div class="account-icon" aria-hidden="true">+</div>
                <div>
                    <h2>Create New Account</h2>
                </div>
            </div>

            <p class="account-card-description">
                Add information below.
            </p>

            <form method="post" class="account-form">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_cashier">

                <label>
                    Name
                    <input
                        type="text"
                        name="full_name"
                        required
                        maxlength="100"
                        placeholder="Example: Alexis Diaz"
                    >
                </label>

                <label>
                    Username
                    <input
                        type="text"
                        name="username"
                        required
                        maxlength="50"
                        placeholder="Example: alexis.cashier"
                    >
                </label>

                <label>
                    Password
                    <input
                        type="password"
                        name="password"
                        required
                        minlength="8"
                        autocomplete="new-password"
                    >
                </label>

                <fieldset class="permissions-box">
                    <legend>Cashier permissions</legend>

                    <?php foreach ($permissionLabels as $permission => $label): ?>
                        <label class="permission-option">
                            <input
                                type="checkbox"
                                name="permissions[]"
                                value="<?= e($permission) ?>"
                                <?= in_array($permission, ['pos_access', 'checkout'], true) ? 'checked' : '' ?>
                            >
                            <span>
                                <strong><?= e($label) ?></strong>
                                <?php if ($permission === 'pos_access'): ?>
                                    <small>Open and use the POS page.</small>
                                <?php elseif ($permission === 'checkout'): ?>
                                    <small>Complete sales and create receipts.</small>
                                <?php elseif ($permission === 'reports_view'): ?>
                                    <small>Open their own recorded sales report.</small>
                                <?php else: ?>
                                    <small>Add, edit, delete, and restore products.</small>
                                <?php endif; ?>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </fieldset>

                <label class="account-toggle">
                    <input type="checkbox" name="is_active" checked>
                    <span>
                        <strong>Activate account immediately</strong>
                        <small>The cashier can log in once the account is created.</small>
                    </span>
                </label>

                <button class="button primary account-submit" type="submit">
                    Create Cashier Account
                </button>
            </form>
        </section>
    </div>

    <section class="cashiers-section">
        <div class="cashiers-heading">
            <div>
                <h2>Cashier Accounts</h2>
            </div>
            <span class="cashier-count"><?= count($cashiers) ?> total</span>
        </div>

        <?php if (!$cashiers): ?>
            <div class="cashiers-empty">
                <div class="empty-icon" aria-hidden="true">+</div>
                <h3>No cashiers created yet</h3>
                <p>Create your first cashier account using the form above.</p>
            </div>
        <?php endif; ?>

        <div class="cashier-list">
            <?php foreach ($cashiers as $cashier): ?>
                <?php $cashierPermissions = decodeCashierPermissions($cashier['permissions']); ?>

                <details class="cashier-record">
                    <summary>
                        <div class="cashier-avatar" aria-hidden="true">
                            <?= e(strtoupper(substr($cashier['full_name'], 0, 1))) ?>
                        </div>

                        <div class="cashier-summary-info">
                            <strong><?= e($cashier['full_name']) ?></strong>
                            <span>@<?= e($cashier['username']) ?></span>
                        </div>

                        <div class="cashier-summary-meta">
                            <span class="account-status <?= (int) $cashier['is_active'] === 1 ? 'is-active' : 'is-inactive' ?>">
                                <?= (int) $cashier['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                            </span>
                            <span class="permission-count">
                                <?= count($cashierPermissions) ?> permission(s)
                            </span>
                        </div>

                        <span class="cashier-chevron" aria-hidden="true">⌄</span>
                    </summary>

                    <div class="cashier-record-content">
                        <div class="cashier-record-note">
                            <strong>Manage <?= e($cashier['full_name']) ?></strong>
                            <span>
                                Created <?= e(date('M d, Y', strtotime($cashier['created_at']))) ?>
                            </span>
                        </div>

                        <form method="post" class="cashier-edit-form">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="save_cashier">
                            <input type="hidden" name="cashier_id" value="<?= (int) $cashier['id'] ?>">

                            <div class="cashier-fields">
                                <label>
                                    Full name
                                    <input
                                        type="text"
                                        name="full_name"
                                        required
                                        maxlength="100"
                                        value="<?= e($cashier['full_name']) ?>"
                                    >
                                </label>

                                <label>
                                    Username
                                    <input
                                        type="text"
                                        name="username"
                                        required
                                        maxlength="50"
                                        value="<?= e($cashier['username']) ?>"
                                    >
                                </label>

                                <label>
                                    Reset password
                                    <input
                                        type="password"
                                        name="password"
                                        minlength="8"
                                        autocomplete="new-password"
                                    >
                                </label>
                            </div>

                            <fieldset class="permissions-box cashier-permissions">
                                <legend>Allowed features</legend>

                                <div class="permission-grid">
                                    <?php foreach ($permissionLabels as $permission => $label): ?>
                                        <label class="permission-option">
                                            <input
                                                type="checkbox"
                                                name="permissions[]"
                                                value="<?= e($permission) ?>"
                                                <?= in_array($permission, $cashierPermissions, true) ? 'checked' : '' ?>
                                            >
                                            <span>
                                                <strong><?= e($label) ?></strong>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </fieldset>

                            <div class="cashier-save-row">
                                <label class="account-toggle">
                                    <input
                                        type="checkbox"
                                        name="is_active"
                                        <?= (int) $cashier['is_active'] === 1 ? 'checked' : '' ?>
                                    >
                                    <span>
                                        <strong>Account is active</strong>
                                        <small>Inactive cashiers cannot log in.</small>
                                    </span>
                                </label>

                                <button class="button primary" type="submit">
                                    Save Changes
                                </button>
                            </div>
                        </form>
                    </div>
                </details>
            <?php endforeach; ?>
        </div>
    </section>
</main>
</body>
</html>