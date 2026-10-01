<?php
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('vorstand');
$pdo = db();

// Storno einer einzelnen Bestellung (z. B. versehentlich doppelt kassiert)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    if (($_POST['action'] ?? '') === 'delete_order') {
        $pdo->prepare('DELETE FROM orders WHERE id = ?')->execute([(int) $_POST['id']]);
        flash('Bestellung entfernt.');
    }
    redirect('auswertung.php?' . http_build_query(['von' => $_POST['von'] ?? '', 'bis' => $_POST['bis'] ?? '']));
}

// ------------------------------------------------------------- Zeitraum
$today = date('Y-m-d');
$presets = [
    'Heute'        => [$today, $today],
    '7 Tage'       => [date('Y-m-d', strtotime('-6 days')), $today],
    '30 Tage'      => [date('Y-m-d', strtotime('-29 days')), $today],
    'Dieses Jahr'  => [date('Y-01-01'), $today],
    'Gesamt'       => ['2000-01-01', $today],
];
$valid = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : null;
$von = $valid($_GET['von'] ?? null) ?? $presets['30 Tage'][0];
$bis = $valid($_GET['bis'] ?? null) ?? $today;
if ($von > $bis) {
    [$von, $bis] = [$bis, $von];
}
$range = [$von . ' 00:00:00', $bis . ' 23:59:59'];

// ------------------------------------------------------------- Abfragen
$summary = $pdo->prepare('
    SELECT COUNT(*) AS orders, COALESCE(SUM(total_cents), 0) AS revenue
    FROM orders WHERE created_at BETWEEN ? AND ?');
$summary->execute($range);
$summary = $summary->fetch();

$byProduct = $pdo->prepare("
    SELECT COALESCE(p.name, MAX(oi.name)) AS name, COALESCE(p.icon, '') AS icon,
           COALESCE(p.category, 'sonstiges') AS category,
           SUM(oi.qty) AS qty, SUM(oi.qty * oi.price_cents) AS revenue
    FROM order_items oi
    JOIN orders o ON o.id = oi.order_id
    LEFT JOIN products p ON p.id = oi.product_id
    WHERE o.created_at BETWEEN ? AND ?
    GROUP BY COALESCE('p' || oi.product_id, 'n' || oi.name)
    ORDER BY qty DESC, name");
$byProduct->execute($range);
$byProduct = $byProduct->fetchAll();

$itemsSold = array_sum(array_column($byProduct, 'qty'));
$byCat = [];
foreach ($byProduct as $r) {
    $byCat[$r['category']][] = $r;
}

// CSV-Export der Artikelstatistik (öffnet sich in Excel)
if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"auswertung_{$von}_{$bis}.csv\"");
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM, damit Excel Umlaute erkennt
    fputcsv($out, ['Kategorie', 'Artikel', 'Anzahl', 'Umsatz (EUR)'], ';');
    foreach (CATEGORIES as $cat => $label) {
        foreach ($byCat[$cat] ?? [] as $r) {
            fputcsv($out, [$label, $r['name'], $r['qty'], number_format($r['revenue'] / 100, 2, ',', '')], ';');
        }
    }
    exit;
}

$byDay = $pdo->prepare('
    SELECT substr(created_at, 1, 10) AS day, COUNT(*) AS orders, SUM(total_cents) AS revenue
    FROM orders WHERE created_at BETWEEN ? AND ?
    GROUP BY day ORDER BY day DESC');
$byDay->execute($range);
$byDay = $byDay->fetchAll();

$recent = $pdo->prepare("
    SELECT o.id, o.created_at, o.total_cents, COALESCE(u.username, '–') AS username,
           (SELECT GROUP_CONCAT(qty || '× ' || name, ', ') FROM order_items WHERE order_id = o.id) AS items
    FROM orders o LEFT JOIN users u ON u.id = o.user_id
    WHERE o.created_at BETWEEN ? AND ?
    ORDER BY o.created_at DESC, o.id DESC LIMIT 25");
$recent->execute($range);
$recent = $recent->fetchAll();

$weekdays = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'];
$fmtDate = fn(string $d) => $weekdays[(int) date('w', strtotime($d))] . ', ' . date('d.m.Y', strtotime($d));

page_header('Auswertung', $user, 'auswertung.php');
?>
<main>
  <div class="page-head">
    <div>
      <h1>Auswertung</h1>
      <p>Was wie oft bestellt wurde – als Grundlage für den Einkauf.</p>
    </div>
    <a class="btn" href="?<?= e(http_build_query(['von' => $von, 'bis' => $bis, 'csv' => 1])) ?>">Als CSV exportieren</a>
  </div>

  <form method="get" class="filters">
    <div class="chips">
      <?php foreach ($presets as $label => [$pv, $pb]): ?>
        <a href="?<?= e(http_build_query(['von' => $pv, 'bis' => $pb])) ?>" class="<?= $pv === $von && $pb === $bis ? 'on' : '' ?>"><?= e($label) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="date-range">
    <label>Von <input type="date" name="von" value="<?= e($von) ?>"></label>
    <label>Bis <input type="date" name="bis" value="<?= e($bis) ?>"></label>
    <button class="btn">Anzeigen</button>
    </div>
  </form>

  <div class="stats">
    <div class="stat"><span>Umsatz</span><strong><?= e(euro((int) $summary['revenue'])) ?></strong></div>
    <div class="stat"><span>Bestellungen</span><strong><?= (int) $summary['orders'] ?></strong></div>
    <div class="stat"><span>Artikel verkauft</span><strong><?= (int) $itemsSold ?></strong></div>
    <div class="stat"><span>Ø pro Bestellung</span><strong><?= e(euro($summary['orders'] ? intdiv((int) $summary['revenue'], (int) $summary['orders']) : 0)) ?></strong></div>
  </div>

  <?php if (!$byProduct): ?>
  <section class="card"><p class="muted" style="margin:0">Keine Bestellungen in diesem Zeitraum.</p></section>
  <?php endif; ?>
  <?php foreach (CATEGORIES as $cat => $label): if (empty($byCat[$cat])) continue;
      $list = $byCat[$cat];
      $maxQty = max(array_column($list, 'qty'));
      $catQty = array_sum(array_column($list, 'qty'));
      $catRevenue = array_sum(array_column($list, 'revenue')); ?>
  <section class="card">
    <div class="card-head">
      <h2><?= e($label) ?></h2>
      <p class="muted"><?= (int) $catQty ?> Stück · <?= e(euro((int) $catRevenue)) ?></p>
    </div>
    <div class="table-wrap">
      <table>
        <thead><tr><th></th><th>Artikel</th><th class="num">Anzahl</th><th class="c-bar" style="width:40%"></th><th class="num">Umsatz</th></tr></thead>
        <tbody>
        <?php foreach ($list as $r): ?>
          <tr>
            <td class="icon-cell"><?= icon_html($r['icon']) ?></td>
            <td class="c-pname"><span class="fit"><?= e($r['name']) ?></span></td>
            <td class="num"><strong><?= (int) $r['qty'] ?></strong><div class="bar bar-inline"><i style="width:<?= round($r['qty'] / $maxQty * 100, 1) ?>%"></i></div></td>
            <td class="c-bar"><div class="bar"><i style="width:<?= round($r['qty'] / $maxQty * 100, 1) ?>%"></i></div></td>
            <td class="num"><?= e(euro((int) $r['revenue'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endforeach; ?>

  <?php if ($byDay): ?>
  <section class="card">
    <h2>Nach Tag</h2>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Tag</th><th class="num">Bestellungen</th><th class="num">Umsatz</th></tr></thead>
        <tbody>
        <?php foreach ($byDay as $d): ?>
          <tr>
            <td><a href="?<?= e(http_build_query(['von' => $d['day'], 'bis' => $d['day']])) ?>"><?= e($fmtDate($d['day'])) ?></a></td>
            <td class="num"><?= (int) $d['orders'] ?></td>
            <td class="num"><?= e(euro((int) $d['revenue'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="card">
    <h2>Letzte Bestellungen</h2>
    <p class="muted" style="margin-top:-12px;font-size:15px">Versehentlich gebuchte Bestellungen hier entfernen, damit die Auswertung stimmt.</p>
    <div class="table-wrap">
      <table class="stack orders">
        <thead><tr><th>Zeit</th><th>Kasse</th><th>Inhalt</th><th class="num">Summe</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($recent as $o): ?>
          <tr>
            <td class="c-time"><?= e(date('d.m. H:i', strtotime($o['created_at']))) ?> <span class="muted c-who-inline">· <?= e($o['username']) ?></span></td>
            <td class="c-who"><?= e($o['username']) ?></td>
            <td class="c-items" title="<?= e($o['items']) ?>"><span class="fit"><?= e($o['items']) ?></span></td>
            <td class="num c-sum"><?= e(euro((int) $o['total_cents'])) ?></td>
            <td class="num c-del">
              <form method="post" class="inline-form" onsubmit="return confirm('Bestellung entfernen?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_order">
                <input type="hidden" name="id" value="<?= (int) $o['id'] ?>">
                <input type="hidden" name="von" value="<?= e($von) ?>">
                <input type="hidden" name="bis" value="<?= e($bis) ?>">
                <button class="btn small danger">Entfernen</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endif; ?>
</main>
<?php page_footer();
