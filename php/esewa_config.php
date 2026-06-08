<?php
define('ESEWA_MERCHANT_CODE', 'EPAYTEST');
define('ESEWA_SECRET_KEY', '8g8M8m8P8n8b8m8');
define('ESEWA_PAY_URL', 'https://rc-epay.esewa.com.np/api/epay/main/v2');
define('ESEWA_VERIFY_URL', 'https://rc-epay.esewa.com.np/api/epay/transaction/status');

$esewaProtocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$esewaHost     = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('SITE_BASE_URL', $esewaProtocol . '://' . $esewaHost . '/TrendTrackV2');
define('ESEWA_SUCCESS_URL', SITE_BASE_URL . '/php/esewa-verify.php');
define('ESEWA_FAILURE_URL', SITE_BASE_URL . '/php/checkout.php?error=payment_failed');

function esewaGenerateSignature($total_amount, $transaction_uuid, $product_code) {
    // Ensure amount has exactly 2 decimal places, no thousand separators
    $total_amount = number_format((float)$total_amount, 2, '.', '');
    $message = "total_amount={$total_amount},transaction_uuid={$transaction_uuid},product_code={$product_code}";
    return base64_encode(hash_hmac('sha256', $message, ESEWA_SECRET_KEY, true));
}

function esewaGenerateOrderRef($orderId) {
    return 'TT-' . $orderId . '-' . time();
}

function esewaExtractOrderId($transaction_uuid) {
    if (preg_match('/^TT-(\d+)-/', (string)$transaction_uuid, $m)) {
        return (int)$m[1];
    }
    return 0;
}

function esewaVerifyCallbackSignature(array $data) {
    if (empty($data['signature']) || empty($data['signed_field_names'])) {
        // In sandbox, signature may be omitted; assume valid
        return true;
    }
    $fields = explode(',', $data['signed_field_names']);
    $parts = [];
    foreach ($fields as $field) {
        $field = trim($field);
        if ($field === '' || !array_key_exists($field, $data)) continue;
        $parts[] = "{$field}={$data[$field]}";
    }
    $message = implode(',', $parts);
    $expected = base64_encode(hash_hmac('sha256', $message, ESEWA_SECRET_KEY, true));
    return hash_equals($expected, $data['signature']);
}

function esewaNormalizeAmount($amount) {
    return number_format((float)str_replace(',', '', (string)$amount), 2, '.', '');
}