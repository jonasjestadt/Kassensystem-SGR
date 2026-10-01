<?php
require __DIR__ . '/../app/bootstrap.php';
$user = require_login();

$products = db()->query('SELECT id, name, icon, category, price_cents FROM products WHERE active = 1 ORDER BY sort, name')->fetchAll();
$byCat = [];
foreach ($products as $p) {
    $byCat[$p['category']][] = $p;
}

page_header('Kasse', $user, 'kasse.php', 'pos-page');
?>
<main class="pos">
  <section class="catalog">
    <?php if (!$products): ?>
      <p class="empty">Noch keine Artikel angelegt.<?= $user['role'] === 'vorstand' ? ' <a href="artikel.php">Artikel anlegen</a>' : '' ?></p>
    <?php endif; ?>
    <?php foreach (CATEGORIES as $cat => $label): if (empty($byCat[$cat])) continue; ?>
      <h2 class="section-title"><?= e($label) ?></h2>
      <div class="tiles">
        <?php foreach ($byCat[$cat] as $p): ?>
          <button type="button" class="tile" data-id="<?= (int) $p['id'] ?>">
            <span class="tile-icon" aria-hidden="true"><?= icon_html($p['icon']) ?></span>
            <span class="tile-name"><?= e($p['name']) ?></span>
            <span class="tile-price"><?= e(euro((int) $p['price_cents'])) ?></span>
            <span class="tile-badge" hidden></span>
            <span class="tile-minus" role="button" aria-label="<?= e($p['name']) ?> entfernen" hidden>−</span>
          </button>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </section>

  <aside class="ticket" aria-live="polite">
    <div class="ticket-head">
      <h2>Bestellung</h2>
      <button type="button" class="link" id="clear" disabled>Leeren</button>
    </div>
    <ul class="ticket-lines" id="lines"><li class="ticket-empty">Tippe auf einen Artikel.</li></ul>
    <div class="ticket-foot">
      <div class="total"><span>Gesamt</span><strong id="total">0,00 €</strong></div>
      <button type="button" class="btn primary big" id="checkout" disabled>Kassieren</button>
    </div>
  </aside>
</main>

<dialog id="pay" class="sheet">
  <form method="dialog">
    <p class="sheet-label">Zu zahlen</p>
    <p class="sheet-total" id="pay-total">0,00 €</p>
    <p class="sheet-label">Gegeben</p>
    <div class="given" id="given"></div>
    <input type="text" id="given-custom" class="given-custom" inputmode="decimal" placeholder="Anderer Betrag (€)" autocomplete="off" aria-label="Anderer Betrag in Euro">
    <div class="change" id="change" hidden><span>Rückgeld</span><strong id="change-amount"></strong></div>
    <div class="sheet-actions">
      <button value="cancel" class="btn secondary big">Zurück</button>
      <button value="done" class="btn primary big" id="done">Fertig</button>
    </div>
  </form>
</dialog>

<div class="toast" id="toast" hidden></div>

<script>
  window.KASSE = {
    products: <?= json_encode(array_map(fn($p) => [
        'id' => (int) $p['id'], 'name' => $p['name'], 'iconHtml' => icon_html($p['icon'], 'line-ico'), 'price' => (int) $p['price_cents'],
    ], $products), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    csrf: <?= json_encode(csrf_token()) ?>
  };
</script>
<script src="<?= asset('assets/kasse.js') ?>"></script>
<?php page_footer();
