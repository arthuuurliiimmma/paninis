<?php

declare(strict_types=1);

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function () {
    $error = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];

    if ($error && in_array($error['type'], $fatalTypes, true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'ok' => false,
            'message' => 'Erro interno no endpoint PHP: ' . $error['message'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
});

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(405, [
        'ok' => false,
        'message' => 'Metodo nao permitido.',
    ]);
}

$config = require __DIR__ . '/payment-config.php';

try {
    $payload = read_payload();
    $order = calculate_order($payload, $config);

    if ($order['pix_amount_cents'] <= (int) $config['paradise_threshold_cents']) {
        $result = create_paradise_pix($order, $payload, $config);
    } else {
        $result = create_anubis_pix($order, $payload, $config);
    }

    send_json(200, $result);
} catch (InvalidArgumentException $exception) {
    send_json(422, [
        'ok' => false,
        'message' => $exception->getMessage(),
    ]);
} catch (Throwable $exception) {
    send_json(500, [
        'ok' => false,
        'message' => $exception->getMessage() ?: 'Nao foi possivel gerar o Pix agora.',
    ]);
}

function send_json(int $status, array $payload): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
        $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
    }

    $json = json_encode($payload, $flags);

    if ($json === false) {
        $json = json_encode([
            'ok' => false,
            'message' => 'Falha ao montar resposta JSON: ' . json_last_error_msg(),
        ]);
    }

    echo $json;
    exit;
}

function read_payload(): array
{
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw ?: '', true);

    if (!is_array($payload)) {
        throw new InvalidArgumentException('Dados do pedido invalidos.');
    }

    return $payload;
}

function digits_only($value): string
{
    return preg_replace('/\D+/', '', (string) $value);
}

function clean_text($value, int $maxLength = 120): string
{
    $text = trim((string) $value);
    $text = preg_replace('/\s+/', ' ', $text);

    if (function_exists('mb_substr')) {
        return mb_substr($text ?: '', 0, $maxLength, 'UTF-8');
    }

    return substr($text ?: '', 0, $maxLength);
}

function cents_from_price($value): int
{
    $price = trim((string) $value);
    $price = str_replace(['R$', ' '], '', $price);

    if (strpos($price, ',') !== false) {
        $price = str_replace('.', '', $price);
        $price = str_replace(',', '.', $price);
    }

    return (int) round(((float) $price) * 100);
}

function money_from_cents(int $cents): string
{
    return 'R$ ' . number_format($cents / 100, 2, ',', '.');
}

function fallback_products(): array
{
    return [
        'kit-iniciante-copa-do-mundo-2026-album-30-envelopes' => [
            'handle' => 'kit-iniciante-copa-do-mundo-2026-album-30-envelopes',
            'title' => 'Kit Iniciante Copa do Mundo 2026 - 1 Album Capa Dura + 30 Pacotes',
            'pix_price_cents' => 8700,
        ],
        'kit-campeao-copa-do-mundo-2026-album-60-envelopes' => [
            'handle' => 'kit-campeao-copa-do-mundo-2026-album-60-envelopes',
            'title' => 'Kit Campeao Copa do Mundo 2026 - 1 Album Capa Dura + 60 Pacotes',
            'pix_price_cents' => 13900,
        ],
        'kit-colecionador-copa-do-mundo-2026-album-90-envelopes' => [
            'handle' => 'kit-colecionador-copa-do-mundo-2026-album-90-envelopes',
            'title' => 'Kit Colecionador Copa do Mundo 2026 - 1 Album Capa Dura + 90 Pacotes',
            'pix_price_cents' => 18900,
        ],
    ];
}

function load_products(): array
{
    $products = fallback_products();
    $csvPath = dirname(__DIR__) . '/data/products.csv';

    if (!is_readable($csvPath)) {
        return $products;
    }

    $file = fopen($csvPath, 'rb');
    if (!$file) {
        return $products;
    }

    $headers = fgetcsv($file);
    if (!is_array($headers)) {
        fclose($file);
        return $products;
    }

    while (($row = fgetcsv($file)) !== false) {
        $record = [];

        foreach ($headers as $index => $header) {
            $record[$header] = $row[$index] ?? '';
        }

        $handle = (string) ($record['Handle'] ?? '');
        if ($handle === '') {
            continue;
        }

        if (strtoupper((string) ($record['Published'] ?? 'TRUE')) === 'FALSE') {
            continue;
        }

        $priceCents = cents_from_price($record['Variant Price'] ?? '0');
        if ($priceCents <= 0) {
            continue;
        }

        $tags = (string) ($record['Tags'] ?? '');
        $isChoiceKit = strpos($tags, 'kit-escolha-seu-kit') !== false;

        $products[$handle] = [
            'handle' => $handle,
            'title' => (string) ($record['Title'] ?? $handle),
            'pix_price_cents' => $isChoiceKit ? $priceCents : (int) round($priceCents * 0.95),
        ];
    }

    fclose($file);
    return $products;
}

function calculate_order(array $payload, array $config): array
{
    $cart = $payload['cart'] ?? [];
    if (!is_array($cart) || count($cart) === 0) {
        throw new InvalidArgumentException('Carrinho vazio.');
    }

    $products = load_products();
    $items = [];
    $subtotalCents = 0;

    foreach ($cart as $line) {
        if (!is_array($line)) {
            continue;
        }

        $handle = clean_text($line['handle'] ?? '', 180);
        $quantity = max(1, min(20, (int) ($line['quantity'] ?? 1)));

        if ($handle === '' || !isset($products[$handle])) {
            throw new InvalidArgumentException('Produto do carrinho nao encontrado.');
        }

        $product = $products[$handle];
        $lineTotal = $product['pix_price_cents'] * $quantity;
        $subtotalCents += $lineTotal;

        $items[] = [
            'handle' => $handle,
            'title' => $product['title'],
            'unit_price_cents' => $product['pix_price_cents'],
            'quantity' => $quantity,
            'total_cents' => $lineTotal,
        ];
    }

    if ($subtotalCents <= 0 || count($items) === 0) {
        throw new InvalidArgumentException('Carrinho vazio.');
    }

    $shippingMethod = ($payload['shipping'] ?? '') === 'express' ? 'express' : 'free';
    $shippingCents = (int) ($config['shipping_cents'][$shippingMethod] ?? 0);
    $totalCents = $subtotalCents + $shippingCents;
    $discountCents = (int) round($totalCents * (float) $config['pix_discount_rate']);
    $pixAmountCents = max(1, $totalCents - $discountCents);

    return [
        'items' => $items,
        'shipping_method' => $shippingMethod,
        'shipping_cents' => $shippingCents,
        'subtotal_cents' => $subtotalCents,
        'total_cents' => $totalCents,
        'discount_cents' => $discountCents,
        'pix_amount_cents' => $pixAmountCents,
        'reference' => 'PANINI-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(4))),
        'description' => count($items) === 1 ? $items[0]['title'] : 'Pedido Panini Copa 2026',
    ];
}

function customer_from_payload(array $payload): array
{
    $customer = $payload['customer'] ?? [];
    if (!is_array($customer)) {
        $customer = [];
    }

    $name = clean_text($customer['name'] ?? '', 80);
    $email = clean_text($customer['email'] ?? '', 80);
    $document = digits_only($customer['cpf'] ?? ($customer['document'] ?? ''));
    $phone = digits_only($customer['phone'] ?? '');

    if ($name === '' || $email === '' || $document === '' || $phone === '') {
        throw new InvalidArgumentException('Preencha os dados de identificacao antes de gerar o Pix.');
    }

    return [
        'name' => $name,
        'email' => $email,
        'document' => $document,
        'document_type' => strlen($document) > 11 ? 'cnpj' : 'cpf',
        'phone' => $phone,
    ];
}

function delivery_from_payload(array $payload): array
{
    $delivery = $payload['delivery'] ?? [];
    if (!is_array($delivery)) {
        $delivery = [];
    }

    $state = clean_text($delivery['state'] ?? '', 2);
    $city = clean_text($delivery['city'] ?? '', 60);
    $cityState = (string) ($delivery['cityState'] ?? '');

    if (($state === '' || $city === '') && strpos($cityState, '/') !== false) {
        $parts = explode('/', $cityState, 2);
        $state = $state ?: clean_text($parts[0] ?? '', 2);
        $city = $city ?: clean_text($parts[1] ?? '', 60);
    }

    return [
        'street' => clean_text($delivery['address'] ?? '', 80),
        'streetNumber' => clean_text($delivery['number'] ?? '0', 10) ?: '0',
        'complement' => clean_text($delivery['complement'] ?? '', 50),
        'zipCode' => digits_only($delivery['cep'] ?? ''),
        'neighborhood' => clean_text($delivery['district'] ?? '', 60),
        'city' => $city,
        'state' => strtoupper($state ?: 'SP'),
        'country' => 'BR',
    ];
}

function tracking_from_payload(array $payload): array
{
    $tracking = $payload['tracking'] ?? [];
    if (!is_array($tracking)) {
        return [];
    }

    $allowed = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'src', 'sck'];
    $clean = [];

    foreach ($allowed as $key) {
        if (!empty($tracking[$key])) {
            $clean[$key] = clean_text($tracking[$key], 120);
        }
    }

    return $clean;
}

function postback_url(array $config): string
{
    if (!empty($config['postback_url'])) {
        return (string) $config['postback_url'];
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

    if (!$host || !$isHttps) {
        return '';
    }

    return 'https://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/api'), '/') . '/payment-webhook.php';
}

function request_json(string $url, array $headers, array $payload): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $headerLines = [];

    foreach ($headers as $name => $value) {
        $headerLines[] = $name . ': ' . $value;
    }

    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => 30,
        ]);

        $responseBody = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if ($responseBody === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new RuntimeException($error ?: 'Falha de conexao com a gateway.');
        }

        curl_close($curl);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = file_get_contents($url, false, $context);
        $status = 0;

        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $matches)) {
            $status = (int) $matches[1];
        }

        if ($responseBody === false) {
            throw new RuntimeException('Falha de conexao com a gateway.');
        }
    }

    $responseText = trim((string) $responseBody);
    $data = json_decode($responseText, true);
    if (!is_array($data)) {
        $detail = $responseText !== ''
            ? substr(strip_tags($responseText), 0, 180)
            : 'sem corpo de resposta';
        throw new RuntimeException('A gateway retornou HTTP ' . $status . ' sem JSON valido (' . $detail . ').');
    }

    return [
        'status' => $status,
        'data' => $data,
    ];
}

function gateway_error_message(array $data, string $fallback): string
{
    foreach (['message', 'error', 'detail', 'details'] as $key) {
        if (!empty($data[$key]) && is_string($data[$key])) {
            return $data[$key];
        }
    }

    return $fallback;
}

function qr_image_url(string $qrCode): string
{
    if ($qrCode === '') {
        return '';
    }

    return 'https://api.qrserver.com/v1/create-qr-code/?size=260x260&margin=10&data=' . rawurlencode($qrCode);
}

function normalize_base64_image(string $image): string
{
    $image = trim($image);
    if ($image === '') {
        return '';
    }

    if (strpos($image, 'data:image') === 0) {
        return $image;
    }

    return 'data:image/png;base64,' . $image;
}

function normalized_response(string $gateway, array $order, array $data, string $qrCode, string $qrImage, string $expiresAt = ''): array
{
    return [
        'ok' => true,
        'gateway' => $gateway,
        'reference' => $order['reference'],
        'transaction_id' => $data['transaction_id'] ?? ($data['id'] ?? ($data['secureId'] ?? '')),
        'status' => $data['status'] ?? 'pending',
        'amount_cents' => $order['pix_amount_cents'],
        'amount_formatted' => money_from_cents($order['pix_amount_cents']),
        'subtotal_formatted' => money_from_cents($order['subtotal_cents']),
        'discount_formatted' => money_from_cents($order['discount_cents']),
        'qr_code' => $qrCode,
        'qr_code_image' => $qrImage ?: qr_image_url($qrCode),
        'expires_at' => $expiresAt,
    ];
}

function create_paradise_pix(array $order, array $payload, array $config): array
{
    $customer = customer_from_payload($payload);
    $gateway = $config['gateways']['paradise'];
    $postbackUrl = postback_url($config);

    $body = [
        'amount' => $order['pix_amount_cents'],
        'description' => clean_text($order['description'], 100),
        'reference' => $order['reference'],
        'source' => 'api_externa',
        'customer' => [
            'name' => $customer['name'],
            'email' => $customer['email'],
            'phone' => $customer['phone'],
            'document' => $customer['document'],
        ],
    ];

    if ($postbackUrl !== '') {
        $body['postback_url'] = $postbackUrl;
    }

    $tracking = tracking_from_payload($payload);
    if ($tracking) {
        $body['tracking'] = $tracking;
    }

    $response = request_json(rtrim($gateway['base_url'], '/') . '/api/v1/transaction.php', [
        'X-API-Key' => $gateway['secret_key'],
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ], $body);

    if ($response['status'] < 200 || $response['status'] >= 300) {
        throw new RuntimeException(gateway_error_message($response['data'], 'A Paradise Pag recusou a criacao do Pix.'));
    }

    $data = $response['data'];
    $qrCode = (string) ($data['qr_code'] ?? ($data['pix_code'] ?? ''));
    $qrImage = normalize_base64_image((string) ($data['qr_code_base64'] ?? ($data['qrCodeBase64'] ?? '')));

    if ($qrCode === '') {
        throw new RuntimeException('A Paradise Pag nao retornou o codigo Pix.');
    }

    return normalized_response('paradise', $order, $data, $qrCode, $qrImage, (string) ($data['expires_at'] ?? ''));
}

function create_anubis_pix(array $order, array $payload, array $config): array
{
    $customer = customer_from_payload($payload);
    $delivery = delivery_from_payload($payload);
    $gateway = $config['gateways']['anubis'];
    $publicKey = (string) ($gateway['public_key'] ?? '');
    $secretKey = (string) ($gateway['secret_key'] ?? '');

    if ($publicKey === '' || $secretKey === '') {
        throw new RuntimeException('A chave publica da AnubisPay precisa ser configurada no servidor.');
    }

    $postbackUrl = postback_url($config);
    $body = [
        'amount' => $order['pix_amount_cents'],
        'paymentMethod' => 'pix',
        'installments' => 1,
        'pix' => [
            'expiresInDays' => 1,
        ],
        'items' => [
            [
                'title' => clean_text($order['description'], 50) ?: 'Pedido Panini Copa 2026',
                'unitPrice' => $order['pix_amount_cents'],
                'quantity' => 1,
                'tangible' => true,
                'externalRef' => $order['reference'],
            ],
        ],
        'shipping' => [
            'fee' => 0,
            'address' => $delivery,
        ],
        'customer' => [
            'name' => $customer['name'],
            'email' => $customer['email'],
            'phone' => $customer['phone'],
            'document' => [
                'number' => $customer['document'],
                'type' => $customer['document_type'],
            ],
            'address' => $delivery,
        ],
        'externalRef' => $order['reference'],
        'metadata' => json_encode([
            'reference' => $order['reference'],
            'subtotal_cents' => $order['subtotal_cents'],
            'shipping_cents' => $order['shipping_cents'],
            'discount_cents' => $order['discount_cents'],
            'items' => $order['items'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ];

    if ($postbackUrl !== '') {
        $body['postbackUrl'] = $postbackUrl;
    }

    $response = request_json(rtrim($gateway['base_url'], '/') . '/v1/transactions', [
        'Authorization' => 'Basic ' . base64_encode($publicKey . ':' . $secretKey),
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ], $body);

    if ($response['status'] < 200 || $response['status'] >= 300) {
        throw new RuntimeException(gateway_error_message($response['data'], 'A AnubisPay recusou a criacao do Pix.'));
    }

    $data = $response['data'];
    $pix = $data['pix'] ?? [];

    if (is_string($pix)) {
        $qrCode = $pix;
        $expiresAt = '';
    } else {
        $qrCode = (string) ($pix['qrcode'] ?? ($pix['qrCode'] ?? ($pix['copyPaste'] ?? '')));
        $expiresAt = (string) ($pix['expirationDate'] ?? '');
    }

    if ($qrCode === '') {
        throw new RuntimeException('A AnubisPay nao retornou o codigo Pix.');
    }

    return normalized_response('anubis', $order, $data, $qrCode, '', $expiresAt);
}
