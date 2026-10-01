<?php
require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

function respond(int $status, array $data): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$user = current_user();
if (!$user) {
    respond(401, ['error' => 'Nicht angemeldet']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['error' => 'Nur POST erlaubt']);
}
$token = $_SERVER['HTTP_X_CSRF'] ?? '';
if (!hash_equals(csrf_token(), $token)) {
    respond(400, ['error' => 'Ungültiges Token']);
}

$in = json_decode(file_get_contents('php://input') ?: '', true);
$clientId = is_array($in) ? (string) ($in['client_id'] ?? '') : '';
$items = is_array($in['items'] ?? null) ? $in['items'] : [];
if (!preg_match('/^[a-zA-Z0-9-]{8,64}$/', $clientId) || !$items) {
    respond(422, ['error' => 'Ungültige Bestellung']);
}

// Offline gepufferte Bestellungen bringen ihren Zeitpunkt mit (max. 7 Tage alt).
$createdAt = date('Y-m-d H:i:s');
if (isset($in['created_at']) && is_numeric($in['created_at'])) {
    $ts = (int) ($in['created_at'] / 1000);
    if ($ts > time() - 7 * 86400 && $ts <= time() + 300) {
        $createdAt = date('Y-m-d H:i:s', $ts);
    }
}

$pdo = db();
$exists = $pdo->prepare('SELECT id FROM orders WHERE client_id = ?');
$exists->execute([$clientId]);
if ($exists->fetchColumn()) {
    respond(200, ['ok' => true, 'duplicate' => true]); // schon gespeichert (z. B. erneuter Versand)
}

$lookup = $pdo->prepare('SELECT id, name, price_cents FROM products WHERE id = ?');
$lines = [];
$total = 0;
foreach ($items as $item) {
    $id = (int) ($item['id'] ?? 0);
    $qty = (int) ($item['qty'] ?? 0);
    if ($qty < 1 || $qty > 999) {
        continue;
    }
    $lookup->execute([$id]);
    if ($p = $lookup->fetch()) {
        $lines[] = [$p['id'], $p['name'], (int) $p['price_cents'], $qty];
        $total += (int) $p['price_cents'] * $qty;
    }
}
if (!$lines) {
    respond(422, ['error' => 'Keine gültigen Artikel']);
}

$pdo->beginTransaction();
$pdo->prepare('INSERT INTO orders (client_id, user_id, created_at, total_cents) VALUES (?, ?, ?, ?)')
    ->execute([$clientId, $user['id'], $createdAt, $total]);
$orderId = (int) $pdo->lastInsertId();
$ins = $pdo->prepare('INSERT INTO order_items (order_id, product_id, name, price_cents, qty) VALUES (?, ?, ?, ?, ?)');
foreach ($lines as $l) {
    $ins->execute([$orderId, ...$l]);
}
$pdo->commit();

respond(200, ['ok' => true, 'total' => $total]);
