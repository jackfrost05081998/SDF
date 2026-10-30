<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

function checkout_response(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    checkout_response(405, [
        'ok' => false,
        'message' => 'Method not allowed.'
    ]);
}

$payload = json_decode((string) file_get_contents('php://input'), true);

if (!is_array($payload) || !verify_csrf($payload['csrf_token'] ?? null)) {
    checkout_response(419, [
        'ok' => false,
        'message' => 'Invalid request. Refresh the POS page and try again.'
    ]);
}

if (!has_permission('checkout')) {
    checkout_response(403, [
        'ok' => false,
        'message' => 'You do not have permission to complete sales.'
    ]);
}

$cart = $payload['cart'] ?? [];
$paymentMethod = (string) ($payload['payment_method'] ?? '');

if (!is_array($cart) || count($cart) === 0 || count($cart) > 100) {
    checkout_response(422, [
        'ok' => false,
        'message' => 'Your cart is empty or invalid.'
    ]);
}

if (!in_array($paymentMethod, ['cash', 'gcash', 'maya'], true)) {
    checkout_response(422, [
        'ok' => false,
        'message' => 'Select a valid payment method.'
    ]);
}

try {
    $pdo = db();
    $pdo->beginTransaction();

    $findProduct = $pdo->prepare(
        "SELECT
            id,
            name,
            base_price,
            hot_iced,
            iced_extra,
            size_16_extra
         FROM products
         WHERE id = ? AND is_active = 1
         FOR UPDATE"
    );

    $items = [];
    $subtotal = 0.0;

    foreach ($cart as $cartItem) {
        $productId = filter_var(
            $cartItem['product_id'] ?? null,
            FILTER_VALIDATE_INT
        );

        $quantity = filter_var(
            $cartItem['quantity'] ?? null,
            FILTER_VALIDATE_INT
        );

        $temperature = (string) ($cartItem['temperature'] ?? '');
        $size = (string) ($cartItem['size'] ?? '');

        if (
            !$productId ||
            !$quantity ||
            $quantity < 1 ||
            $quantity > 99 ||
            !in_array($temperature, ['hot', 'iced'], true) ||
            !in_array($size, ['12 Oz', '16 Oz'], true)
        ) {
            throw new RuntimeException('One or more cart items are invalid.');
        }

        /*
         * Security/business rule:
         * Hot drinks are only 12 Oz.
         */
        if ($temperature === 'hot' && $size !== '12 Oz') {
            throw new RuntimeException(
                'Hot drinks are available in 12 Oz only.'
            );
        }

        $findProduct->execute([$productId]);
        $product = $findProduct->fetch();

        if (!$product) {
            throw new RuntimeException(
                'A selected product is no longer available. Refresh the menu and try again.'
            );
        }

        if (
            ($product['hot_iced'] === 'hot' && $temperature !== 'hot') ||
            ($product['hot_iced'] === 'iced' && $temperature !== 'iced')
        ) {
            throw new RuntimeException(
                'An unavailable temperature was selected.'
            );
        }

        $unitPrice = (float) $product['base_price'];

        if ($temperature === 'iced') {
            $unitPrice += (float) $product['iced_extra'];
        }

        if ($temperature === 'iced' && $size === '16 Oz') {
            $unitPrice += (float) $product['size_16_extra'];
        }

        $unitPrice = round($unitPrice, 2);
        $lineTotal = round($unitPrice * $quantity, 2);
        $subtotal += $lineTotal;

        $items[] = [
            'product_id' => (int) $product['id'],
            'product_name' => $product['name'],
            'temperature' => $temperature,
            'size' => $size,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
        ];
    }

    $total = round($subtotal, 2);

    if ($paymentMethod === 'cash') {
        $cashReceived = filter_var(
            $payload['cash_received'] ?? null,
            FILTER_VALIDATE_FLOAT
        );

        if ($cashReceived === false || $cashReceived < 0) {
            throw new RuntimeException('Enter a valid cash amount.');
        }

        $cashReceived = round((float) $cashReceived, 2);

        if ($cashReceived + 0.00001 < $total) {
            throw new RuntimeException(
                'Cash received is less than the total due.'
            );
        }

        $changeAmount = round($cashReceived - $total, 2);
    } else {
        /*
         * For school-project GCash/Maya recording:
         * payment is treated as exact; no external payment API is called.
         */
        $cashReceived = $total;
        $changeAmount = 0.00;
    }

    $receiptNo = 'BBC-' . date('Ymd-His') . '-' . random_int(100, 999);

    $orderStatement = $pdo->prepare(
        "INSERT INTO orders
        (
            receipt_no,
            cashier_id,
            subtotal,
            total,
            cash_received,
            change_amount,
            payment_method
        )
        VALUES (?, ?, ?, ?, ?, ?, ?)"
    );

    $orderStatement->execute([
        $receiptNo,
        (int) current_user()['id'],
        $subtotal,
        $total,
        $cashReceived,
        $changeAmount,
        $paymentMethod,
    ]);

    $orderId = (int) $pdo->lastInsertId();

    $itemStatement = $pdo->prepare(
        "INSERT INTO order_items
        (
            order_id,
            receipt_no,
            product_id,
            product_name,
            temperature,
            drink_size,
            quantity,
            unit_price,
            line_total
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    foreach ($items as $item) {
        $itemStatement->execute([
            $orderId,
            $receiptNo,
            $item['product_id'],
            $item['product_name'],
            $item['temperature'],
            $item['size'],
            $item['quantity'],
            $item['unit_price'],
            $item['line_total'],
        ]);
    }

    audit(
        (int) current_user()['id'],
        'Completed sale',
        'Receipt ' . $receiptNo . ' · ' . strtoupper($paymentMethod)
    );

    $pdo->commit();

    checkout_response(200, [
        'ok' => true,
        'receipt_url' => 'receipt.php?receipt=' . urlencode($receiptNo),
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    // Only our own validation messages (RuntimeException) are safe to show.
    // Database or other unexpected errors must not leak internal details.
    $message = $exception instanceof RuntimeException
        ? $exception->getMessage()
        : 'The sale could not be completed. Refresh the POS page and try again.';

    checkout_response(422, [
        'ok' => false,
        'message' => $message
    ]);
}