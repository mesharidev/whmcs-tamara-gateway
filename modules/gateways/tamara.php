<?php
/**
 * Tamara Buy Now Pay Later - WHMCS Payment Gateway
 * Docs: https://docs.tamara.co/
 *
 * @author    Meshari Alomari
 * @copyright Copyright (c) 2026 Meshari Alomari. All rights reserved.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function tamara_MetaData()
{
    return [
        'DisplayName'                 => 'Tamara - Buy Now Pay Later',
        'APIVersion'                  => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage'            => false,
    ];
}

function tamara_config()
{
    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'Tamara',
        ],
        'apiToken' => [
            'FriendlyName' => 'API Token',
            'Type'         => 'text',
            'Size'         => '80',
            'Default'      => '',
            'Description'  => 'Bearer token from Tamara Partners Portal (Integration Settings)',
        ],
        'notificationToken' => [
            'FriendlyName' => 'Notification Token',
            'Type'         => 'text',
            'Size'         => '80',
            'Default'      => '',
            'Description'  => 'JWT token used to verify incoming Webhook requests',
        ],
        'countryCode' => [
            'FriendlyName' => 'Country Code',
            'Type'         => 'dropdown',
            'Options'      => 'SA,AE,BH,KW,OM',
            'Default'      => 'SA',
            'Description'  => 'Supported country code',
        ],
        'buttonText' => [
            'FriendlyName' => 'Payment Button Text',
            'Type'         => 'text',
            'Size'         => '50',
            'Default'      => 'Pay via Tamara',
            'Description'  => 'Label shown on the payment button in the invoice page',
        ],
        'testMode' => [
            'FriendlyName' => 'Test Mode',
            'Type'         => 'yesno',
            'Description'  => 'Enable sandbox (test) environment',
        ],
    ];
}

function tamara_link($params)
{
    $apiToken    = $params['apiToken'];
    $countryCode = $params['countryCode'] ?: 'SA';
    $testMode    = $params['testMode'];

    $invoiceId    = $params['invoiceid'];
    $amount       = (float) $params['amount'];
    $currency     = $params['currency'];
    $systemUrl    = $params['systemurl'];
    $clientLang   = strtolower($params['clientdetails']['language'] ?? 'english');
    $callbackBase = $systemUrl . 'modules/gateways/callback/tamara.php';

    $clientDetails = $params['clientdetails'];
    $fullName      = trim($clientDetails['firstname'] . ' ' . $clientDetails['lastname']);
    $nameParts     = explode(' ', $fullName, 2);

    $basePayload = [
        'order_reference_id' => (string) $invoiceId,
        'total_amount'       => ['amount' => $amount, 'currency' => $currency],
        'description'        => 'Invoice #' . $invoiceId,
        'country_code'       => $countryCode,
        'locale'             => (strpos($clientLang, 'arab') !== false) ? 'ar_SA' : 'en_US',
        'items'              => [[
            'reference_id'    => 'inv-' . $invoiceId,
            'type'            => 'Digital',
            'name'            => !empty($params['description']) ? $params['description'] : 'Invoice #' . $invoiceId,
            'sku'             => 'INV-' . $invoiceId,
            'quantity'        => 1,
            'unit_price'      => ['amount' => $amount, 'currency' => $currency],
            'discount_amount' => ['amount' => 0.00, 'currency' => $currency],
            'tax_amount'      => ['amount' => 0.00, 'currency' => $currency],
            'total_amount'    => ['amount' => $amount, 'currency' => $currency],
        ]],
        'consumer' => [
            'first_name'   => $nameParts[0],
            'last_name'    => $nameParts[1] ?? $nameParts[0],
            'phone_number' => tamara_formatPhone($clientDetails['phonenumber'], $countryCode),
            'email'        => $clientDetails['email'],
        ],
        'billing_address' => [
            'first_name'   => $nameParts[0],
            'last_name'    => $nameParts[1] ?? $nameParts[0],
            'line1'        => $clientDetails['address1'] ?: 'N/A',
            'line2'        => $clientDetails['address2'] ?: '',
            'city'         => $clientDetails['city']    ?: 'N/A',
            'country_code' => $countryCode,
        ],
        'shipping_address' => [
            'first_name'   => $nameParts[0],
            'last_name'    => $nameParts[1] ?? $nameParts[0],
            'line1'        => $clientDetails['address1'] ?: 'N/A',
            'line2'        => $clientDetails['address2'] ?: '',
            'city'         => $clientDetails['city']    ?: 'N/A',
            'country_code' => $countryCode,
        ],
        'discount_amount' => ['amount' => 0.00, 'currency' => $currency],
        'tax_amount'      => ['amount' => 0.00, 'currency' => $currency],
        'shipping_amount' => ['amount' => 0.00, 'currency' => $currency],
        'merchant_url'    => [
            'success'      => $callbackBase . '?action=success&invoice_id=' . $invoiceId,
            'failure'      => $callbackBase . '?action=failure&invoice_id=' . $invoiceId,
            'cancel'       => $callbackBase . '?action=cancel&invoice_id='  . $invoiceId,
            'notification' => $callbackBase . '?action=webhook',
        ],
    ];


    $response = tamara_apiPost($apiToken, 'checkout', $basePayload, $testMode);

    if (!empty($response['checkout_url'])) {
        $url        = htmlspecialchars($response['checkout_url'], ENT_QUOTES, 'UTF-8');
        $buttonText = !empty($params['buttonText']) ? $params['buttonText'] : 'Pay via Tamara';
        return '<a href="' . $url . '" class="btn btn-primary btn-lg">' . htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8') . '</a>';
    }

    $errorMsg = $response['message'] ?? 'Failed to connect to Tamara gateway';
    logTransaction('tamara', $response, 'Checkout Session Error');

    return '<p class="alert alert-danger">' . htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') . '</p>';
}

function tamara_refund($params)
{
    $apiToken  = $params['apiToken'];
    $testMode  = $params['testMode'];
    $orderId   = $params['transid'];
    $amount    = (float) $params['amount'];
    $currency  = $params['currency'];
    $invoiceId = $params['invoiceid'];

    $payload = [
        'total_amount'       => ['amount' => $amount, 'currency' => $currency],
        'comment'            => 'Refund for invoice #' . $invoiceId,
        'merchant_refund_id' => 'refund-' . $invoiceId . '-' . time(),
    ];

    $response = tamara_apiPost($apiToken, 'payments/simplified-refund/' . $orderId, $payload, $testMode);

    if (!empty($response['refund_id'])) {
        return [
            'status'  => 'success',
            'rawdata' => $response,
            'transid' => $response['refund_id'],
        ];
    }

    return [
        'status'  => 'declined',
        'rawdata' => $response,
        'error'   => $response['message'] ?? 'Refund failed',
    ];
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function tamara_formatPhone($phone, $countryCode)
{
    $phone = preg_replace('/\D/', '', $phone);

    $dialCodes = ['SA' => '966', 'AE' => '971', 'BH' => '973', 'KW' => '965', 'OM' => '968'];
    $dialCode  = $dialCodes[$countryCode] ?? '966';

    $phone = ltrim($phone, '0');

    if (strpos($phone, $dialCode) !== 0) {
        $phone = $dialCode . $phone;
    }

    return '+' . $phone;
}

// ─── HTTP Helper ──────────────────────────────────────────────────────────────

function tamara_baseUrl($testMode)
{
    return $testMode ? 'https://api-sandbox.tamara.co/' : 'https://api.tamara.co/';
}

function tamara_apiPost($apiToken, $endpoint, array $payload, $testMode)
{
    $url = tamara_baseUrl($testMode) . $endpoint;

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
