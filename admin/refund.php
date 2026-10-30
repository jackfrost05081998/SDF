<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_login();

function refundReturnUrl(string $date, string $source = 'admin'): string
{
    $query = http_build_query(['date' => $date]);

    // A refund can be launched from the admin report or from a cashier's own
    // sales report. Send the browser back to whichever page it came from.
    if ($source === 'cashier') {
        return '../cashier/report.php?' . $query;
    }

    return 'reports.php?' . $query;
}

function validRefundDate(string $value): string
{
    $date = DateTime::createFromFormat('!Y-m-d', $value);

    if (!$date || $date->format('Y-m-d') !== $value) {
        return date('Y-m-d');
    }

    return $value;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed.');
}

$returnDate = validRefundDate((string) ($_POST['return_date'] ?? ''));
$source = (($_POST['source'] ?? '') === 'cashier') ? 'cashier' : 'admin';
$receiptNo = strtoupper(trim((string) ($_POST['receipt_no'] ?? '')));
$orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
$reasonCode = (string) ($_POST['reason_code'] ?? '');
$reasonNote = trim((string) ($_POST['reason_note'] ?? ''));
$adminUsername = trim((string) ($_POST['admin_username'] ?? ''));
$adminPassword = (string) ($_POST['admin_password'] ?? '');
$allowedReasons = [
    'customer_request',
    'wrong_item',
    'duplicate_charge',
    'cashier_error',
    'other',
];

if (!verify_csrf($_POST['csrf_token'] ?? null)) {
    flash('error', 'This refund request is invalid or expired. Refresh the report and try again.');
    header('Location: ' . refundReturnUrl($returnDate, $source));
    exit;
}

if (!$orderId || $orderId < 1 || $receiptNo === '') {
    flash('error', 'The receipt selected for refund is invalid.');
    header('Location: ' . refundReturnUrl($returnDate, $source));
    exit;
}

if (!in_array($reasonCode, $allowedReasons, true) || strlen($reasonNote) < 5 || strlen($reasonNote) > 500) {
    flash('error', 'Select a refund reason and enter an explanation between 5 and 500 characters.');
    header('Location: ' . refundReturnUrl($returnDate, $source));
    exit;
}

if ($adminPassword === '') {
    flash('error', 'Enter your current administrator password to authorize the refund.');
    header('Location: ' . refundReturnUrl($returnDate, $source));
    exit;
}

$pdo = db();

try {
    $pdo->beginTransaction();

    // Resolve the administrator who is authorizing this refund.
    // - From the cashier's report an admin username is supplied (admin override).
    // - From the admin report the logged-in admin authorizes with their password.
    if ($adminUsername !== '') {
        $adminStatement = $pdo->prepare(
            "SELECT id, password_hash
             FROM users
             WHERE username = ? AND role = 'admin' AND is_active = 1
             FOR UPDATE"
        );
        $adminStatement->execute([$adminUsername]);
    } elseif ((current_user()['role'] ?? '') === 'admin') {
        $adminStatement = $pdo->prepare(
            "SELECT id, password_hash
             FROM users
             WHERE id = ? AND role = 'admin' AND is_active = 1
             FOR UPDATE"
        );
        $adminStatement->execute([(int) current_user()['id']]);
    } else {
        $pdo->rollBack();
        audit((int) current_user()['id'], 'Failed refund authorization', 'Receipt ' . $receiptNo . ' · no administrator supplied');
        flash('error', 'An administrator must authorize this refund. Enter an administrator username and password.');
        header('Location: ' . refundReturnUrl($returnDate, $source));
        exit;
    }

    $admin = $adminStatement->fetch();

    if (!$admin || !password_verify($adminPassword, $admin['password_hash'])) {
        $pdo->rollBack();
        audit((int) current_user()['id'], 'Failed refund authorization', 'Receipt ' . $receiptNo);
        flash('error', 'Administrator credentials could not be verified. No refund was processed.');
        header('Location: ' . refundReturnUrl($returnDate, $source));
        exit;
    }

    $orderStatement = $pdo->prepare(
        "SELECT id, receipt_no, total, payment_method, status, cashier_id
         FROM orders
         WHERE id = ? AND receipt_no = ?
         FOR UPDATE"
    );
    $orderStatement->execute([$orderId, $receiptNo]);
    $order = $orderStatement->fetch();

    // A receipt can be refunded while it is completed or only partially
    // refunded. Fully refunded receipts are no longer eligible.
    if (!$order
        || !in_array($order['status'], ['completed', 'partially_refunded'], true)
        || (float) $order['total'] <= 0) {
        throw new RuntimeException('This receipt is not eligible for a refund.');
    }

    // A cashier authorizing from their own report may only refund their own
    // receipts, even though an administrator approves the action.
    if ((current_user()['role'] ?? '') !== 'admin'
        && (int) $order['cashier_id'] !== (int) current_user()['id']) {
        throw new RuntimeException('You can only refund your own receipts.');
    }

    // Load the line items and how much of each has already been refunded.
    $lineStatement = $pdo->prepare(
        'SELECT id, quantity, unit_price FROM order_items WHERE order_id = ? FOR UPDATE'
    );
    $lineStatement->execute([(int) $order['id']]);
    $orderItems = $lineStatement->fetchAll();

    $itemById = [];
    foreach ($orderItems as $orderItem) {
        $itemById[(int) $orderItem['id']] = $orderItem;
    }

    $refundedStatement = $pdo->prepare(
        'SELECT order_item_id, COALESCE(SUM(quantity), 0) AS refunded_qty
         FROM refund_items WHERE order_id = ? GROUP BY order_item_id'
    );
    $refundedStatement->execute([(int) $order['id']]);

    $refundedByItem = [];
    foreach ($refundedStatement->fetchAll() as $refundedRow) {
        $refundedByItem[(int) $refundedRow['order_item_id']] = (int) $refundedRow['refunded_qty'];
    }

    // Validate the requested per-line quantities coming from the form.
    $requestedQuantities = $_POST['refund_qty'] ?? [];
    if (!is_array($requestedQuantities)) {
        $requestedQuantities = [];
    }

    $linesToRefund = [];
    $eventAmount = 0.0;

    foreach ($requestedQuantities as $rawItemId => $rawQuantity) {
        $itemId = filter_var($rawItemId, FILTER_VALIDATE_INT);
        $quantity = filter_var($rawQuantity, FILTER_VALIDATE_INT);

        if (!$itemId || $quantity === false || $quantity <= 0) {
            continue;
        }

        if (!isset($itemById[$itemId])) {
            throw new RuntimeException('A selected item does not belong to this receipt.');
        }

        $soldQuantity = (int) $itemById[$itemId]['quantity'];
        $alreadyRefunded = $refundedByItem[$itemId] ?? 0;
        $remaining = $soldQuantity - $alreadyRefunded;

        if ($remaining <= 0) {
            throw new RuntimeException('One of the selected items has already been fully refunded.');
        }

        if ($quantity > $remaining) {
            throw new RuntimeException('You cannot refund more of an item than remains on the receipt.');
        }

        $lineAmount = round((float) $itemById[$itemId]['unit_price'] * $quantity, 2);
        $eventAmount = round($eventAmount + $lineAmount, 2);

        $linesToRefund[] = [
            'order_item_id' => $itemId,
            'quantity' => $quantity,
            'amount' => $lineAmount,
        ];
    }

    if (!$linesToRefund || $eventAmount <= 0) {
        throw new RuntimeException('Select at least one item and quantity to refund.');
    }

    // Record each refunded line in the ledger.
    $insertLine = $pdo->prepare(
        'INSERT INTO refund_items
        (order_id, order_item_id, quantity, amount, reason_code, reason_note, authorized_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    foreach ($linesToRefund as $line) {
        $insertLine->execute([
            (int) $order['id'],
            $line['order_item_id'],
            $line['quantity'],
            $line['amount'],
            $reasonCode,
            $reasonNote,
            (int) $admin['id'],
        ]);
    }

    // Keep one cumulative refund row per receipt: insert on the first refund,
    // then add to it on subsequent partial refunds.
    $existingStatement = $pdo->prepare(
        'SELECT id, refund_no FROM refunds WHERE order_id = ? FOR UPDATE'
    );
    $existingStatement->execute([(int) $order['id']]);
    $existingRefund = $existingStatement->fetch();

    if ($existingRefund) {
        $refundNo = (string) $existingRefund['refund_no'];
        $updateRefund = $pdo->prepare(
            'UPDATE refunds
             SET refund_amount = refund_amount + ?,
                 reason_code = ?, reason_note = ?,
                 authorized_by = ?, authorized_at = CURRENT_TIMESTAMP
             WHERE id = ?'
        );
        $updateRefund->execute([
            $eventAmount,
            $reasonCode,
            $reasonNote,
            (int) $admin['id'],
            (int) $existingRefund['id'],
        ]);
    } else {
        $refundNo = 'RFD-' . date('Ymd-His') . '-' . random_int(1000, 9999);
        $insertRefund = $pdo->prepare(
            'INSERT INTO refunds
            (refund_no, order_id, refund_amount, original_payment_method, reason_code, reason_note, authorized_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $insertRefund->execute([
            $refundNo,
            (int) $order['id'],
            $eventAmount,
            $order['payment_method'],
            $reasonCode,
            $reasonNote,
            (int) $admin['id'],
        ]);
    }
    // Decide the receipt's new status from total refunded vs total sold.
    // The order row is locked FOR UPDATE, so this count is consistent.
    $totalSold = 0;
    $totalRefunded = 0;
    foreach ($orderItems as $orderItem) {
        $totalSold += (int) $orderItem['quantity'];
        $totalRefunded += $refundedByItem[(int) $orderItem['id']] ?? 0;
    }
    foreach ($linesToRefund as $line) {
        $totalRefunded += $line['quantity'];
    }

    $fullyRefunded = $totalRefunded >= $totalSold;

    if ($fullyRefunded) {
        $statusStatement = $pdo->prepare(
            "UPDATE orders SET status = 'refunded', refunded_at = CURRENT_TIMESTAMP
             WHERE id = ? AND status IN ('completed', 'partially_refunded')"
        );
    } else {
        $statusStatement = $pdo->prepare(
            "UPDATE orders SET status = 'partially_refunded'
             WHERE id = ? AND status IN ('completed', 'partially_refunded')"
        );
    }
    $statusStatement->execute([(int) $order['id']]);

    $refundedUnits = array_sum(array_column($linesToRefund, 'quantity'));

    audit(
        (int) $admin['id'],
        $fullyRefunded ? 'Authorized full refund' : 'Authorized partial refund',
        'Refund ' . $refundNo . ' · Receipt ' . $order['receipt_no'] . ' · ' . peso($eventAmount)
            . ' · ' . $refundedUnits . ' item(s) · ' . $reasonCode
    );

    $pdo->commit();

    $flashMessage = $fullyRefunded
        ? 'Refund ' . $refundNo . ' completed receipt ' . $order['receipt_no'] . ' in full.'
        : 'Partial refund of ' . peso($eventAmount) . ' recorded for receipt '
            . $order['receipt_no'] . ' (' . $refundNo . ').';
    flash('success', $flashMessage);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    flash('error', $exception instanceof PDOException
        ? 'The refund could not be recorded. Refresh the report and confirm the receipt status.'
        : $exception->getMessage());
}

header('Location: ' . refundReturnUrl($returnDate, $source));
exit;

