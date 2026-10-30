<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_login();

/**
 * Status pill CSS class + label for a receipt status.
 *
 * @return array{0:string,1:string}
 */
function receiptStatusPill(string $status): array
{
    if ($status === 'refunded') {
        return ['is-refunded', 'Refunded'];
    }

    if ($status === 'partially_refunded') {
        return ['is-partial', 'Partially Refunded'];
    }

    return ['is-completed', 'Completed'];
}

/**
 * JSON of still-refundable lines for the refund dialog's data-refund-items
 * attribute. Only lines that still have a remaining quantity are included.
 */
function refundableItemsJson(array $items): string
{
    $payload = [];

    foreach ($items as $item) {
        $remaining = (int) $item['quantity'] - (int) ($item['refunded_qty'] ?? 0);

        if ($remaining <= 0) {
            continue;
        }

        $variant = trim(
            ucfirst((string) ($item['temperature'] ?? '')) . ' · ' . (string) ($item['drink_size'] ?? '')
        );

        $payload[] = [
            'id' => (int) $item['id'],
            'name' => (string) $item['product_name'],
            'variant' => trim($variant, ' ·'),
            'unit_price' => round((float) $item['unit_price'], 2),
            'remaining' => $remaining,
        ];
    }

    return json_encode($payload, JSON_UNESCAPED_UNICODE) ?: '[]';
}

/** True when a receipt still has at least one refundable line. */
function hasRefundableItems(array $items): bool
{
    foreach ($items as $item) {
        if ((int) $item['quantity'] - (int) ($item['refunded_qty'] ?? 0) > 0) {
            return true;
        }
    }

    return false;
}

if ((current_user()['role'] ?? '') !== 'cashier') {
    http_response_code(403);
    exit('This page is only available to cashier accounts.');
}

if (!has_permission('reports_view')) {
    http_response_code(403);
    exit('You do not have permission to view sales reports.');
}

$date = $_GET['date'] ?? date('Y-m-d');

$dateObject = DateTime::createFromFormat('Y-m-d', $date);

if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
    $date = date('Y-m-d');
}

$cashierId = (int) current_user()['id'];

$flash = consume_flash();

$summaryStatement = db()->prepare(
    'SELECT
        COUNT(*) AS transaction_count,
        COALESCE(SUM(total), 0) AS total_sales
     FROM orders
     WHERE cashier_id = ?
       AND DATE(created_at) = ?'
);
$summaryStatement->execute([$cashierId, $date]);
$summary = $summaryStatement->fetch();

$refundSummaryStatement = db()->prepare(
    'SELECT
        COUNT(*) AS refund_count,
        COALESCE(SUM(r.refund_amount), 0) AS refund_total
     FROM refunds r
     INNER JOIN orders o ON o.id = r.order_id
     WHERE o.cashier_id = ?
       AND DATE(o.created_at) = ?'
);
$refundSummaryStatement->execute([$cashierId, $date]);
$refundSummary = $refundSummaryStatement->fetch();

$grossSales = (float) $summary['total_sales'];
$refundTotal = (float) $refundSummary['refund_total'];
$netSales = $grossSales - $refundTotal;

$transactionStatement = db()->prepare(
    'SELECT o.id, o.receipt_no, o.total, o.cash_received, o.change_amount,
            o.status, o.created_at,
            COALESCE(r.refund_amount, 0) AS refund_amount
     FROM orders o
     LEFT JOIN refunds r ON r.order_id = o.id
     WHERE o.cashier_id = ?
       AND DATE(o.created_at) = ?
     ORDER BY o.created_at DESC'
);
$transactionStatement->execute([$cashierId, $date]);
$transactions = $transactionStatement->fetchAll();

$itemStatement = db()->prepare(
    'SELECT oi.id, oi.product_name, oi.temperature, oi.drink_size,
            oi.quantity, oi.unit_price,
            COALESCE(SUM(ri.quantity), 0) AS refunded_qty
     FROM order_items oi
     LEFT JOIN refund_items ri ON ri.order_item_id = oi.id
     WHERE oi.order_id = ?
     GROUP BY oi.id, oi.product_name, oi.temperature, oi.drink_size, oi.quantity, oi.unit_price
     ORDER BY oi.id ASC'
);
foreach ($transactions as &$transaction) {
    $itemStatement->execute([(int) $transaction['id']]);
    $transaction['items'] = $itemStatement->fetchAll();
}
unset($transaction);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>My Sales Report | Beanson Brew Cafe</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="kiosk-page admin-page">
<header class="topbar">
    <div>
        <a class="logo" href="../index.php">
            Beanson Brew Cafe <span>— My Sales Report</span>
        </a>
        <small><?= e(current_user()['full_name']) ?></small>
    </div>

    <div class="top-actions">
        <?php if (has_permission('pos_access')): ?>
            <a class="button" href="../index.php">Back to POS</a>
        <?php endif; ?>
        <a class="button" href="../logout.php">Log out</a>
    </div>
</header>

<main class="report-page">
    <?php if ($flash): ?>
        <div class="alert <?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <section class="panel">
        <div class="report-toolbar">
            <form method="get">
                <label>
                    Report date
                    <input type="date" name="date" value="<?= e($date) ?>">
                </label>

                <button class="button primary" type="submit">View My Report</button>
            </form>
        </div>

        <div class="stat-grid report-stat-grid">
            <article class="stat">
                <span>My Total Sales</span>
                <strong><?= peso($grossSales) ?></strong>
            </article>

            <article class="stat">
                <span>Refunds</span>
                <strong><?= peso($refundTotal) ?></strong>
            </article>

            <article class="stat">
                <span>Net Sales</span>
                <strong><?= peso($netSales) ?></strong>
            </article>

            <article class="stat">
                <span>My Transactions</span>
                <strong><?= (int) $summary['transaction_count'] ?></strong>
            </article>

            <article class="stat">
                <span>Refunded</span>
                <strong><?= (int) $refundSummary['refund_count'] ?></strong>
            </article>
        </div>
    </section>

    <section class="panel">
        <div class="panel-title">
            <h1>My Transaction Log</h1>
        </div>

        <div class="table-scroll">
            <table class="transaction-log-table">
                <thead>
                    <tr>
                        <th>Receipt No.</th>
                        <th>Products</th>
                        <th>Qty</th>
                        <th>Date and Time</th>
                        <th>Total</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$transactions): ?>
                    <tr>
                        <td colspan="7" class="empty-cell">No transactions recorded for this date.</td>
                    </tr>
                <?php endif; ?>

                <?php foreach ($transactions as $transaction): ?>
                    <tr>
                        <td><?= e($transaction['receipt_no']) ?></td>
                        <td>
                            <div class="transaction-products">
                                <?php foreach ($transaction['items'] as $item): ?>
                                    <span><?= e($item['product_name']) ?></span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td>
                            <div class="transaction-quantities">
                                <?php foreach ($transaction['items'] as $item): ?>
                                    <span><?= (int) $item['quantity'] ?></span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td>
                            <?= e(date(
                                'M d, Y · h:i:s A',
                                strtotime($transaction['created_at'])
                            )) ?>
                        </td>
                        <td>
                            <?php $refundedAmount = (float) ($transaction['refund_amount'] ?? 0); ?>
                            <?php if ($refundedAmount > 0): ?>
                                <?= peso((float) $transaction['total'] - $refundedAmount) ?>
                                <small class="net-total-note">was <?= peso((float) $transaction['total']) ?> · −<?= peso($refundedAmount) ?> refunded</small>
                            <?php else: ?>
                                <?= peso((float) $transaction['total']) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php [$pillClass, $pillLabel] = receiptStatusPill($transaction['status']); ?>
                            <span class="receipt-status <?= $pillClass ?>">
                                <?= $pillLabel ?>
                            </span>
                        </td>
                        <td class="transaction-actions">
                            <?php if ($transaction['status'] !== 'refunded' && hasRefundableItems($transaction['items'])): ?>
                                <button
                                    class="button small delete-button"
                                    type="button"
                                    data-open-refund
                                    data-order-id="<?= (int) $transaction['id'] ?>"
                                    data-receipt-no="<?= e($transaction['receipt_no']) ?>"
                                    data-refund-total="<?= e(peso((float) $transaction['total'])) ?>"
                                    data-refund-items="<?= e(refundableItemsJson($transaction['items'])) ?>"
                                >Refund</button>
                            <?php endif; ?>
                            <a
                                class="button small"
                                href="../receipt.php?receipt=<?= e(urlencode($transaction['receipt_no'])) ?>"
                            >View</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<dialog id="refundDialog" class="refund-dialog">
    <form method="post" action="../admin/refund.php" id="refundForm">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="order_id" id="refundOrderId">
        <input type="hidden" name="receipt_no" id="refundReceiptNo">
        <input type="hidden" name="return_date" value="<?= e($date) ?>">
        <input type="hidden" name="source" value="cashier">
        <div class="dialog-heading">
            <div>
                <p class="eyebrow">Irreversible financial action</p>
                <h2 id="refundDialogTitle">Refund Receipt</h2>
            </div>
            <button class="icon-button" type="button" data-close-refund aria-label="Close refund form">×</button>
        </div>
        <p class="refund-warning">Choose how many of each item to refund. This must be authorized by an administrator. It does not delete the original receipt or send a gateway reversal for digital payments.</p>
        <div class="refund-line-list" id="refundLineList"></div>
        <div class="refund-total-row"><span>Refund total</span><strong id="refundDialogTotal">₱0.00</strong></div>
        <label>Refund reason
            <select name="reason_code" required>
                <option value="customer_request">Customer request</option>
                <option value="wrong_item">Wrong item</option>
                <option value="duplicate_charge">Duplicate charge</option>
                <option value="cashier_error">Cashier error</option>
                <option value="other">Other</option>
            </select>
        </label>
        <label>Explanation
            <textarea name="reason_note" minlength="5" maxlength="500" required placeholder="Explain why this receipt is being refunded."></textarea>
        </label>
        <label>Administrator username
            <input type="text" name="admin_username" autocomplete="username" autocapitalize="none" spellcheck="false" required placeholder="Manager account">
        </label>
        <label>Administrator password
            <input type="password" name="admin_password" autocomplete="current-password" required>
        </label>
        <div class="dialog-actions">
            <button class="button" type="button" data-close-refund>Cancel</button>
            <button class="button delete-button" type="submit">Authorize Refund</button>
        </div>
    </form>
</dialog>
<script src="../assets/js/reports.js"></script>
</body>
</html>