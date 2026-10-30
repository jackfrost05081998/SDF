<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_admin();

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
 * Build the JSON of still-refundable lines for the refund dialog's
 * data-refund-items attribute. Only lines with a remaining quantity appear.
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

$flash = consume_flash();
$date = (string) ($_GET['date'] ?? date('Y-m-d'));
$dateObject = DateTime::createFromFormat('!Y-m-d', $date);
if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
    $date = date('Y-m-d');
    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
}
$nextDate = (clone $dateObject)->modify('+1 day')->format('Y-m-d');
$receiptSearch = strtoupper(trim((string) ($_GET['receipt'] ?? '')));
$receiptSearch = substr($receiptSearch, 0, 40);

$summaryStatement = db()->prepare(
    'SELECT COUNT(*) AS transaction_count, COALESCE(SUM(total), 0) AS gross_sales
     FROM orders WHERE created_at >= ? AND created_at < ?'
);
$summaryStatement->execute([$date, $nextDate]);
$summary = $summaryStatement->fetch();
$refundSummaryStatement = db()->prepare(
    'SELECT COUNT(*) AS refund_count, COALESCE(SUM(refund_amount), 0) AS refund_total
     FROM refunds WHERE authorized_at >= ? AND authorized_at < ?'
);
$refundSummaryStatement->execute([$date, $nextDate]);
$refundSummary = $refundSummaryStatement->fetch();

// Enriched line-item query, reused for the receipt search result and for each
// cashier transaction. It reports how many of each line remain refundable.
$itemStatement = db()->prepare(
    "SELECT oi.id, oi.product_name, oi.temperature, oi.drink_size,
            oi.quantity, oi.unit_price,
            COALESCE(SUM(ri.quantity), 0) AS refunded_qty
     FROM order_items oi
     LEFT JOIN refund_items ri ON ri.order_item_id = oi.id
     WHERE oi.order_id = ?
     GROUP BY oi.id, oi.product_name, oi.temperature, oi.drink_size, oi.quantity, oi.unit_price
     ORDER BY oi.id ASC"
);

$receipt = null;
if ($receiptSearch !== '') {
    $receiptStatement = db()->prepare(
        "SELECT o.id, o.receipt_no, o.total, o.payment_method, o.status, o.created_at,
                u.full_name AS cashier_name, r.refund_no, r.refund_amount,
                r.reason_code, r.reason_note, r.authorized_at,
                au.full_name AS authorized_by_name
         FROM orders o
         INNER JOIN users u ON u.id = o.cashier_id
         LEFT JOIN refunds r ON r.order_id = o.id
         LEFT JOIN users au ON au.id = r.authorized_by
         WHERE o.receipt_no = ? LIMIT 1"
    );
    $receiptStatement->execute([$receiptSearch]);
    $receipt = $receiptStatement->fetch();

    if ($receipt) {
        $itemStatement->execute([(int) $receipt['id']]);
        $receipt['items'] = $itemStatement->fetchAll();
    }
}

$cashierStatement = db()->prepare(
    "SELECT o.cashier_id, u.full_name, u.username,
            COUNT(DISTINCT o.id) AS transaction_count,
            COALESCE(SUM(o.total), 0) AS gross_sales
     FROM orders o
     INNER JOIN users u ON u.id = o.cashier_id
     WHERE o.created_at >= ? AND o.created_at < ?
     GROUP BY o.cashier_id, u.full_name, u.username
     ORDER BY u.full_name ASC"
);
$cashierStatement->execute([$date, $nextDate]);
$cashiers = $cashierStatement->fetchAll();

$transactionStatement = db()->prepare(
    "SELECT o.id, o.receipt_no, o.total, o.payment_method, o.status, o.created_at,
            COALESCE(r.refund_amount, 0) AS refund_amount
     FROM orders o
     LEFT JOIN refunds r ON r.order_id = o.id
     WHERE o.created_at >= ? AND o.created_at < ? AND o.cashier_id = ?
     ORDER BY o.created_at DESC"
);
foreach ($cashiers as &$cashier) {
    $transactionStatement->execute([$date, $nextDate, $cashier['cashier_id']]);
    $cashier['transactions'] = $transactionStatement->fetchAll();

    foreach ($cashier['transactions'] as &$transaction) {
        $itemStatement->execute([(int) $transaction['id']]);
        $transaction['items'] = $itemStatement->fetchAll();
    }
    unset($transaction);
}
unset($cashier);

$refundActivityStatement = db()->prepare(
    "SELECT r.refund_no, r.refund_amount, r.reason_code, r.authorized_at,
            o.receipt_no, o.created_at AS sale_created_at,
            cashier.full_name AS cashier_name, admin.full_name AS admin_name
     FROM refunds r
     INNER JOIN orders o ON o.id = r.order_id
     INNER JOIN users cashier ON cashier.id = o.cashier_id
     INNER JOIN users admin ON admin.id = r.authorized_by
     WHERE r.authorized_at >= ? AND r.authorized_at < ?
     ORDER BY r.authorized_at DESC"
);
$refundActivityStatement->execute([$date, $nextDate]);
$refundActivity = $refundActivityStatement->fetchAll();

if (isset($_GET['xml'])) {
    $xml = new DOMDocument('1.0', 'UTF-8');
    $xml->formatOutput = true;
    $root = $xml->createElement('daily_sales_report');
    $root->setAttribute('date', $date);
    $root->appendChild($xml->createElement('gross_sales', number_format((float) $summary['gross_sales'], 2, '.', '')));
    $root->appendChild($xml->createElement('refunds_processed', number_format((float) $refundSummary['refund_total'], 2, '.', '')));
    $root->appendChild($xml->createElement('net_sales_activity', number_format((float) $summary['gross_sales'] - (float) $refundSummary['refund_total'], 2, '.', '')));
    $root->appendChild($xml->createElement('gross_transactions', (string) $summary['transaction_count']));
    $root->appendChild($xml->createElement('refund_transactions', (string) $refundSummary['refund_count']));
    $xml->appendChild($root);
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="beanson-sales-report-' . $date . '.xml"');
    echo $xml->saveXML();
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sales Reports | Beanson Brew Cafe</title>
    <link rel="stylesheet" href="../assets/css/style.css">
</head>
<body class="kiosk-page admin-page">
<header class="topbar">
    <div>
        <a class="logo" href="../index.php">Beanson Brew Cafe <span>— Sales Reports</span></a>
    </div>
    <nav class="top-actions" aria-label="Administration navigation">
        <a class="button" href="products.php">Manage Products</a>
        <a class="button" href="users.php">Accounts</a>
        <a class="button" href="../index.php">Back to POS</a>
    </nav>
</header>

<main class="report-page">
    <?php if ($flash): ?>
        <div class="alert <?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div>
    <?php endif; ?>

    <section class="panel">
        <div class="report-toolbar">
            <form method="get">
                <label>Report date
                    <input type="date" name="date" value="<?= e($date) ?>">
                </label>
                <button class="button primary" type="submit">View Report</button>
            </form>
            <a class="button" href="reports.php?date=<?= e($date) ?>&amp;xml=1">Export XML</a>
        </div>
        <div class="stat-grid report-stat-grid">
            <article class="stat"><span>Gross Receipt Sales</span><strong><?= peso((float) $summary['gross_sales']) ?></strong></article>
            <article class="stat"><span>Refunds Processed</span><strong><?= peso((float) $refundSummary['refund_total']) ?></strong></article>
            <article class="stat"><span>Net Sales Activity</span><strong><?= peso((float) $summary['gross_sales'] - (float) $refundSummary['refund_total']) ?></strong></article>
            <article class="stat"><span>Gross Receipts</span><strong><?= (int) $summary['transaction_count'] ?></strong></article>
            <article class="stat"><span>Refund Transactions</span><strong><?= (int) $refundSummary['refund_count'] ?></strong></article>
        </div>
    </section>

    <section class="panel receipt-search-panel">
        <form method="get" class="receipt-search-form">
            <input type="hidden" name="date" value="<?= e($date) ?>">
            <label>Exact receipt number
                <input name="receipt" maxlength="40" value="<?= e($receiptSearch) ?>" placeholder="Example: BBC-20260908-120000-123" autocomplete="off">
            </label>
            <button class="button primary" type="submit">Search Receipt</button>
        </form>
        <?php if ($receiptSearch !== '' && !$receipt): ?>
            <p class="search-empty">No receipt matched <strong><?= e($receiptSearch) ?></strong>.</p>
        <?php endif; ?>
        <?php if ($receipt): ?>
            <div class="receipt-search-result">
                <div class="receipt-result-heading">
                    <div><p class="eyebrow">Receipt <?= e($receipt['receipt_no']) ?></p><h2><?= peso((float) $receipt['total']) ?></h2></div>
                    <?php [$pillClass, $pillLabel] = receiptStatusPill($receipt['status']); ?>
                    <span class="receipt-status <?= $pillClass ?>"><?= $pillLabel ?></span>
                </div>
                <dl class="receipt-details">
                    <div><dt>Sold</dt><dd><?= e(date('M d, Y · h:i A', strtotime($receipt['created_at']))) ?></dd></div>
                    <div><dt>Cashier</dt><dd><?= e($receipt['cashier_name']) ?></dd></div>
                    <div><dt>Payment</dt><dd><?= e(strtoupper($receipt['payment_method'])) ?></dd></div>
                </dl>
                <?php if ($receipt['refund_no']): ?>
                    <div class="refund-summary"><strong><?= e($receipt['refund_no']) ?> · <?= peso((float) $receipt['refund_amount']) ?> refunded</strong><span>Net total <?= peso((float) $receipt['total'] - (float) $receipt['refund_amount']) ?> after refunds.</span><span>Last authorized <?= e(date('M d, Y · h:i A', strtotime($receipt['authorized_at']))) ?> by <?= e($receipt['authorized_by_name']) ?>.</span><small><?= e($receipt['reason_note']) ?></small></div>
                <?php endif; ?>
                <div class="receipt-result-actions">
                    <?php if ($receipt['status'] !== 'refunded' && hasRefundableItems($receipt['items'])): ?>
                        <button
                            class="button delete-button"
                            type="button"
                            data-open-refund
                            data-order-id="<?= (int) $receipt['id'] ?>"
                            data-receipt-no="<?= e($receipt['receipt_no']) ?>"
                            data-refund-total="<?= e(peso((float) $receipt['total'])) ?>"
                            data-refund-items="<?= e(refundableItemsJson($receipt['items'])) ?>"
                        >Refund Items</button>
                    <?php endif; ?>
                    <a class="button" href="../receipt.php?receipt=<?= e(urlencode($receipt['receipt_no'])) ?>">View Receipt</a>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($refundActivity): ?>
        <section class="panel"><div class="panel-title"><div><p class="eyebrow">Financial reversal activity</p><h1>Refunds Processed</h1></div></div><div class="table-scroll"><table><thead><tr><th>Refund</th><th>Original Receipt</th><th>Amount</th><th>Authorized By</th><th>Processed</th></tr></thead><tbody><?php foreach ($refundActivity as $refund): ?><tr><td><?= e($refund['refund_no']) ?><br><small><?= e(str_replace('_', ' ', $refund['reason_code'])) ?></small></td><td><?= e($refund['receipt_no']) ?><br><small><?= e($refund['cashier_name']) ?></small></td><td><?= peso((float) $refund['refund_amount']) ?></td><td><?= e($refund['admin_name']) ?></td><td><?= e(date('M d, Y · h:i A', strtotime($refund['authorized_at']))) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
    <?php endif; ?>

    <?php foreach ($cashiers as $cashier): ?>
        <section class="panel cashier-report-card">
            <div class="panel-title panel-title-row"><div><p class="eyebrow">Cashier</p><h1><?= e($cashier['full_name']) ?></h1></div><div class="report-group-total"><small>Gross Total</small><strong><?= peso((float) $cashier['gross_sales']) ?></strong><small><?= (int) $cashier['transaction_count'] ?> receipt(s)</small></div></div>
            <div class="cashier-report-content transaction-report-content">
                <div>
                    <h2 class="report-section-title">Transactions</h2>
                    <div class="table-scroll">
                        <table class="transaction-log-table">
                            <thead><tr><th>Receipt</th><th>Products</th><th>Qty</th><th>Time</th><th>Total</th><th>Status</th><th>Actions</th></tr></thead>
                            <tbody>
                            <?php foreach ($cashier['transactions'] as $transaction): ?>
                                <tr>
                                    <td><?= e($transaction['receipt_no']) ?></td>
                                    <td><div class="transaction-products"><?php foreach ($transaction['items'] as $item): ?><span><?= e($item['product_name']) ?></span><?php endforeach; ?></div></td>
                                    <td><div class="transaction-quantities"><?php foreach ($transaction['items'] as $item): ?><span><?= (int) $item['quantity'] ?></span><?php endforeach; ?></div></td>
                                    <td><?= e(date('M d · h:i A', strtotime($transaction['created_at']))) ?></td>
                                    <td>
                                        <?php $refundedAmount = (float) ($transaction['refund_amount'] ?? 0); ?>
                                        <?php if ($refundedAmount > 0): ?>
                                            <?= peso((float) $transaction['total'] - $refundedAmount) ?>
                                            <small class="net-total-note">was <?= peso((float) $transaction['total']) ?> · −<?= peso($refundedAmount) ?> refunded</small>
                                        <?php else: ?>
                                            <?= peso((float) $transaction['total']) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php [$pillClass, $pillLabel] = receiptStatusPill($transaction['status']); ?><span class="receipt-status <?= $pillClass ?>"><?= $pillLabel ?></span></td>
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
                                        <a class="button small" href="../receipt.php?receipt=<?= e(urlencode($transaction['receipt_no'])) ?>">View</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </section>
    <?php endforeach; ?>
</main>

<dialog id="refundDialog" class="refund-dialog">
    <form method="post" action="refund.php" id="refundForm">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="order_id" id="refundOrderId">
        <input type="hidden" name="receipt_no" id="refundReceiptNo">
        <input type="hidden" name="return_date" value="<?= e($date) ?>">
        <div class="dialog-heading"><div><p class="eyebrow">Irreversible Action</p><h2 id="refundDialogTitle">Refund Receipt</h2></div><button class="icon-button" type="button" data-close-refund aria-label="Close refund form">×</button></div>
        <div class="refund-line-list" id="refundLineList"></div>
        <div class="refund-total-row"><span>Refund total</span><strong id="refundDialogTotal">₱0.00</strong></div>
        <label>Refund reason<select name="reason_code" required><option value="customer_request">Customer request</option><option value="wrong_item">Wrong item</option><option value="duplicate_charge">Duplicate charge</option><option value="cashier_error">Cashier error</option><option value="other">Other</option></select></label>
        <label>Explanation<textarea name="reason_note" minlength="5" maxlength="500" required placeholder="Explain why these items are being refunded."></textarea></label>
        <label>Admin Password<input type="password" name="admin_password" autocomplete="current-password" required></label>
        <div class="dialog-actions"><button class="button" type="button" data-close-refund>Cancel</button><button class="button delete-button" type="submit">Authorize Refund</button></div>
    </form>
</dialog>
<script src="../assets/js/reports.js"></script>
</body>
</html>
