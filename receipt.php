<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_login();

// A receipt can be opened by its public receipt number (preferred — it is
// not guessable in sequence) or by the internal order id (kept so older
// links and bookmarks still work).
$receiptNo = strtoupper(trim((string) ($_GET['receipt'] ?? '')));
$orderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($receiptNo === '' && !$orderId) {
    http_response_code(404);
    exit('Receipt not found.');
}

$user = current_user();
$statement = db()->prepare(
    'SELECT
        o.*,
        u.full_name AS cashier_name,
        r.refund_no,
        r.refund_amount,
        r.reason_note,
        r.authorized_at,
        refund_admin.full_name AS refund_admin_name
     FROM orders o
     INNER JOIN users u ON u.id = o.cashier_id
     LEFT JOIN refunds r ON r.order_id = o.id
     LEFT JOIN users refund_admin ON refund_admin.id = r.authorized_by
     WHERE ' . ($receiptNo !== '' ? 'o.receipt_no = ?' : 'o.id = ?') . '
     LIMIT 1'
);
$statement->execute([$receiptNo !== '' ? $receiptNo : $orderId]);
$order = $statement->fetch();

if (!$order || ($user['role'] !== 'admin' && (int) $order['cashier_id'] !== (int) $user['id'])) {
    http_response_code(403);
    exit('Receipt not available.');
}

$items = db()->prepare(
    'SELECT oi.id, oi.product_name, oi.temperature, oi.drink_size,
            oi.quantity, oi.unit_price, oi.line_total,
            COALESCE(SUM(ri.quantity), 0) AS refunded_qty
     FROM order_items oi
     LEFT JOIN refund_items ri ON ri.order_item_id = oi.id
     WHERE oi.order_id = ?
     GROUP BY oi.id, oi.product_name, oi.temperature, oi.drink_size, oi.quantity, oi.unit_price, oi.line_total
     ORDER BY oi.id'
);
$items->execute([(int) $order['id']]);
$items = $items->fetchAll();

$orderStatus = $order['status'] ?? 'completed';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($order['receipt_no']) ?> | Receipt</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="receipt-page">
<main class="receipt">
    <header>
        <h1>Beanson Brew Cafe</h1>
        <p>Sales Receipt</p>
    </header>
    <?php if ($orderStatus === 'refunded'): ?>
        <p class="receipt-refund-status">REFUNDED IN FULL</p>
    <?php elseif ($orderStatus === 'partially_refunded'): ?>
        <p class="receipt-refund-status">PARTIALLY REFUNDED</p>
    <?php endif; ?>
    <hr>
    <p>Receipt: <?= e($order['receipt_no']) ?><br>
       Date: <?= e(date('M d, Y h:i A', strtotime($order['created_at']))) ?><br>
       Cashier: <?= e($order['cashier_name']) ?></p>
    <hr>
    <?php foreach ($items as $item): ?>
        <div class="receipt-line">
            <span><?= e($item['product_name']) ?><br><small><?= e(ucfirst($item['temperature'])) ?>, <?= e($item['drink_size']) ?> × <?= (int) $item['quantity'] ?></small><?php if ((int) $item['refunded_qty'] > 0): ?><br><small class="receipt-line-refunded"><?= (int) $item['refunded_qty'] ?> refunded</small><?php endif; ?></span>
            <strong><?= peso((float) $item['line_total']) ?></strong>
        </div>
    <?php endforeach; ?>
    <hr>
<?php
$paymentLabels = [
    'cash' => 'Cash',
    'gcash' => 'GCash',
    'maya' => 'Maya',
];
$paymentLabel = $paymentLabels[$order['payment_method'] ?? 'cash'] ?? 'Cash';
?>
<div class="receipt-line">
    <span>Total</span>
    <strong><?= peso((float) $order['total']) ?></strong>
</div>
<div class="receipt-line">
    <span>Payment Method</span>
    <span><?= e($paymentLabel) ?></span>
</div>
<div class="receipt-line">
    <span><?= $paymentLabel === 'Cash' ? 'Cash Received' : 'Amount Paid' ?></span>
    <span><?= peso((float) $order['cash_received']) ?></span>
</div>
<div class="receipt-line">
    <span>Change</span>
    <span><?= peso((float) $order['change_amount']) ?></span>
</div>
<?php if (!empty($order['refund_no'])): ?>
    <div class="receipt-line">
        <span>Refunded</span>
        <strong>−<?= peso((float) $order['refund_amount']) ?></strong>
    </div>
    <div class="receipt-line">
        <span>Net Total</span>
        <strong><?= peso((float) $order['total'] - (float) $order['refund_amount']) ?></strong>
    </div>
    <div class="receipt-refund-status">
        <?= $orderStatus === 'refunded' ? 'Refund' : 'Partial refund' ?> <?= e($order['refund_no']) ?> · <?= peso((float) $order['refund_amount']) ?><br>
        Recorded <?= e(date('M d, Y h:i A', strtotime($order['authorized_at']))) ?>.
        <?php if (($user['role'] ?? '') === 'admin'): ?>
            <br><small>Authorized by <?= e($order['refund_admin_name']) ?>: <?= e($order['reason_note']) ?></small>
        <?php endif; ?>
    </div>
<?php endif; ?>
    <hr>
    <p class="center">Thank you for visiting!</p>
    <p class="center no-print"><button class="button primary" onclick="window.print()">Print Receipt</button> <a class="button secondary" href="index.php">Back to POS</a></p>
</main>
</body>
</html>
