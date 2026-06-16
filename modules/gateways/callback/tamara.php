<?php
/**
 * Tamara Payment Gateway - Callback & Webhook Handler for WHMCS
 *
 * @author    Meshari Alomari
 * @copyright Copyright (c) 2026 Meshari Alomari. All rights reserved.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayModuleName = 'tamara';
$gatewayParams     = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die('Module not activated');
}

$apiToken          = $gatewayParams['apiToken'];
$notificationToken = $gatewayParams['notificationToken'];
$testMode          = $gatewayParams['testMode'];

$action = $_GET['action'] ?? '';

// ─── Webhook Handler ─────────────────────────────────────────────────────────
if ($action === 'webhook') {
    tamara_handleWebhook($apiToken, $notificationToken, $testMode, $gatewayModuleName);
    exit;
}

// ─── Redirect Callbacks ───────────────────────────────────────────────────────
$invoiceId = (int) ($_GET['invoice_id'] ?? 0);

// Tamara sends orderId as a query param on redirect
$tamaraOrderId = $_GET['orderId'] ?? $_GET['order_id'] ?? '';

$invoiceId = checkCbInvoiceID($invoiceId, $gatewayModuleName);

if ($action === 'success' && $tamaraOrderId) {
    tamara_processSuccess($invoiceId, $tamaraOrderId, $apiToken, $testMode, $gatewayModuleName);
} elseif ($action === 'failure') {
    logTransaction($gatewayModuleName, $_GET, 'Payment Failed');
    callback3DSecureRedirect($invoiceId, false);
} elseif ($action === 'cancel') {
    logTransaction($gatewayModuleName, $_GET, 'Payment Cancelled');
    callback3DSecureRedirect($invoiceId, false);
} else {
    logTransaction($gatewayModuleName, $_GET, 'Unknown Callback');
    callback3DSecureRedirect($invoiceId, false);
}

// ─── Functions ────────────────────────────────────────────────────────────────

function tamara_processSuccess($invoiceId, $tamaraOrderId, $apiToken, $testMode, $gatewayModuleName)
{
    // Step 1: Authorise the order
    $authoriseResponse = tamara_cb_apiPost(
        $apiToken,
        'orders/' . $tamaraOrderId . '/authorise',
        [],
        $testMode
    );

    $authoriseStatus = $authoriseResponse['status'] ?? '';

    if (!in_array($authoriseStatus, ['authorised', 'fully_captured'])) {
        logTransaction($gatewayModuleName, $authoriseResponse, 'Authorise Failed');
        callback3DSecureRedirect($invoiceId, false);
        return;
    }

    // Already captured automatically — mark as paid
    if ($authoriseStatus === 'fully_captured' || !empty($authoriseResponse['auto_captured'])) {
        tamara_markPaid($invoiceId, $tamaraOrderId, $authoriseResponse, $gatewayModuleName);
        return;
    }

    // Step 2: Capture the payment
    $authorisedAmount = $authoriseResponse['authorized_amount']['amount']   ?? 0;
    $currency         = $authoriseResponse['authorized_amount']['currency'] ?? 'SAR';

    $capturePayload = [
        'order_id'     => $tamaraOrderId,
        'total_amount' => ['amount' => $authorisedAmount, 'currency' => $currency],
        'shipping_info' => [
            'shipped_at'       => date('Y-m-d\TH:i:s\Z'),
            'shipping_company' => 'Digital Delivery',
            'tracking_number'  => 'INV-' . $invoiceId,
        ],
    ];

    $captureResponse = tamara_cb_apiPost($apiToken, 'payments/capture', $capturePayload, $testMode);

    $captureStatus = $captureResponse['status'] ?? '';

    if (!in_array($captureStatus, ['fully_captured', 'partially_captured'])) {
        logTransaction($gatewayModuleName, $captureResponse, 'Capture Failed');
        callback3DSecureRedirect($invoiceId, false);
        return;
    }

    tamara_markPaid($invoiceId, $tamaraOrderId, $captureResponse, $gatewayModuleName);
}

function tamara_markPaid($invoiceId, $tamaraOrderId, $responseData, $gatewayModuleName)
{
    checkCbTransID($tamaraOrderId);

    $capturedAmount = $responseData['captured_amount']['amount']
        ?? $responseData['authorized_amount']['amount']
        ?? 0;

    addInvoicePayment(
        $invoiceId,
        $tamaraOrderId,
        $capturedAmount,
        0,
        $gatewayModuleName
    );

    logTransaction($gatewayModuleName, $responseData, 'Successful');
    callback3DSecureRedirect($invoiceId, true);
}

function tamara_handleWebhook($apiToken, $notificationToken, $testMode, $gatewayModuleName)
{
    $rawBody = file_get_contents('php://input');
    $payload = json_decode($rawBody, true);

    if (empty($payload)) {
        http_response_code(400);
        exit;
    }

    // Verify webhook via Notification Token
    if ($notificationToken) {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $queryToken = $_GET['tamaraToken'] ?? '';
        $receivedToken = str_replace('Bearer ', '', $authHeader) ?: $queryToken;

        if ($receivedToken !== $notificationToken) {
            logTransaction($gatewayModuleName, $payload, 'Webhook Token Mismatch');
            http_response_code(401);
            exit;
        }
    }

    $eventType      = $payload['event_type']         ?? '';
    $tamaraOrderId  = $payload['order_id']           ?? '';
    $referenceId    = $payload['order_reference_id'] ?? '';
    $invoiceId      = (int) $referenceId;

    logTransaction($gatewayModuleName, $payload, 'Webhook: ' . $eventType);

    if (in_array($eventType, ['order_authorised', 'order_captured']) && $invoiceId && $tamaraOrderId) {
        // Skip if invoice already paid
        $invoiceData = getInvoice($invoiceId);
        if ($invoiceData && $invoiceData['status'] !== 'Paid') {
            tamara_processSuccess($invoiceId, $tamaraOrderId, $apiToken, $testMode, $gatewayModuleName);
        }
    }

    http_response_code(200);
    echo json_encode(['received' => true]);
}

// ─── HTTP Helper ──────────────────────────────────────────────────────────────

function tamara_cb_apiPost($apiToken, $endpoint, array $payload, $testMode)
{
    $base = $testMode ? 'https://api-sandbox.tamara.co/' : 'https://api.tamara.co/';
    $url  = $base . $endpoint;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiToken,
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $result = curl_exec($ch);
    curl_close($ch);

    return json_decode($result, true) ?: [];
}
