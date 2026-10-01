<?php
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('vorstand');
$pdo = db();

/** Liest und prüft die Formularfelder eines Artikels. */
function product_input(array $src): array
{
    $name = trim((string) ($src['name'] ?? ''));
    $price = parse_euro((string) ($src['price'] ?? ''));
    $cat = (string) ($src['category'] ?? '');
    if ($name === '' || mb_strlen($name) > 40) {
        throw new InvalidArgumentException('Bitte einen Namen (max. 40 Zeichen) angeben.');
    }
    if ($price === null) {
        throw new InvalidArgumentException("Preis für „{$name}“ ist ungültig (z. B. 3,50).");
    }
    return [
        'name'     => $name,
        'icon'     => clean_icon((string) ($src['icon'] ?? '')),
        'category' => isset(CATEGORIES[$cat]) ? $cat : 'getraenk',
        'price'    => $price,
        'active'   => empty($src['active']) ? 0 : 1,
    ];
}

/** Nur bekannte eigene Symbole oder ein kurzes Emoji zulassen. */
function clean_icon(string $icon): string
{
    $icon = trim($icon);
    if (str_starts_with($icon, 'svg:')) {
        $name = substr($icon, 4);
        $name = ICON_ALIASES[$name] ?? $name;
        foreach (ICONS as $group) {
            if (isset($group[$name])) {
                return 'svg:' . $name;
            }
        }
        return '';
    }
    return mb_substr($icon, 0, 8);
}

/** Nächste freie Position am Ende einer Kategorie. */
function next_sort(PDO $pdo, string $cat): int
{
    $stmt = $pdo->prepare('SELECT COALESCE(MAX(sort), 0) + 10 FROM products WHERE category = ?');
    $stmt->execute([$cat]);
    return (int) $stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    try {
        switch ($_POST['action'] ?? '') {
            case 'create':
                $p = product_input($_POST + ['active' => 1]);
                $pdo->prepare('INSERT INTO products (name, icon, category, price_cents, sort, active) VALUES (?, ?, ?, ?, ?, 1)')
                    ->execute([$p['name'], $p['icon'], $p['category'], $p['price'], next_sort($pdo, $p['category'])]);
                flash("„{$p['name']}“ wurde angelegt.");
                break;
            case 'update':
                $p = product_input($_POST);
                $id = (int) $_POST['id'];
                $old = $pdo->prepare('SELECT category, sort FROM products WHERE id = ?');
                $old->execute([$id]);
                $old = $old->fetch();
                // Wechselt die Kategorie, kommt der Artikel ans Ende der neuen Liste
                $sort = $old && $old['category'] === $p['category'] ? (int) $old['sort'] : next_sort($pdo, $p['category']);
                $pdo->prepare('UPDATE products SET name = ?, icon = ?, category = ?, price_cents = ?, sort = ?, active = ? WHERE id = ?')
                    ->execute([$p['name'], $p['icon'], $p['category'], $p['price'], $sort, $p['active'], $id]);
                flash("„{$p['name']}“ wurde gespeichert.");
                redirect('artikel.php#row-' . $id);
            case 'reorder':
                // Neue Reihenfolge einer Kategorie per Drag & Drop (Antwort ohne Seitenwechsel)
                $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
                $cat = (string) ($_POST['category'] ?? '');
                $upd = $pdo->prepare('UPDATE products SET sort = ? WHERE id = ? AND category = ?');
                $pdo->beginTransaction();
                foreach ($ids as $n => $pid) {
                    $upd->execute([($n + 1) * 10, $pid, $cat]);
                }
                $pdo->commit();
                http_response_code(204);
                exit;
            case 'delete':
                $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([(int) $_POST['id']]);
                flash('Artikel gelöscht. Bisherige Verkäufe bleiben in der Auswertung erhalten.');
                break;
        }
    } catch (InvalidArgumentException $ex) {
        flash($ex->getMessage(), 'err');
    }
    redirect('artikel.php');
}

$products = $pdo->query("SELECT * FROM products ORDER BY CASE category WHEN 'getraenk' THEN 1 WHEN 'essen' THEN 2 ELSE 3 END, sort, name")->fetchAll();
$byCat = [];
foreach (array_keys(CATEGORIES) as $cat) {
    $byCat[$cat] = [];
}
foreach ($products as $p) {
    $byCat[$p['category']][] = $p;
}
$emojis = [
    'getraenk'  => ['🍺', '🍻', '🍏', '🍎', '🍷', '🥂', '🍾', '🍹', '🍸', '🥃', '🥤', '🧃', '💧', '☕', '🍵'],
    'essen'     => ['🍟', '🌭', '🥩', '🍖', '🍗', '🍔', '🥨', '🥗', '🍰', '🧇', '🍦', '🍬', '🥜'],
    'sonstiges' => ['♻️', '🎟️', '🧾', '⚽', '👕'],
];
$placeholders = ['getraenk' => 'z. B. Radler', 'essen' => 'z. B. Currywurst', 'sonstiges' => 'z. B. Pfand'];

page_header('Artikel', $user, 'artikel.php');
?>
<main>
  <div class="page-head">
    <div>
      <h1>Artikel</h1>
      <p>Getränke und Speisen für die Kasse.</p>
    </div>
  </div>

  <form method="post" class="card" id="new">
    <h2>Neuer Artikel</h2>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="segmented" role="radiogroup" aria-label="Kategorie">
      <?php foreach (CATEGORIES as $k => $v): ?>
        <label><input type="radio" name="category" value="<?= e($k) ?>" <?= $k === 'getraenk' ? 'checked' : '' ?>><span><?= e($v) ?></span></label>
      <?php endforeach; ?>
    </div>
    <div class="form-grid new-grid">
      <div class="field">
        <span class="field-label">Symbol</span>
        <input type="hidden" name="icon" id="new-icon" value="">
        <button type="button" class="icon-pick" data-for="new-icon" aria-label="Symbol wählen"><span class="icon-pick-empty">＋</span></button>
      </div>
      <label class="wide">Name <input name="name" id="new-name" required maxlength="40" placeholder="<?= e($placeholders['getraenk']) ?>"></label>
      <label>Preis (€) <input name="price" required inputmode="decimal" placeholder="3,50"></label>
    </div>
    <p style="margin:20px 0 0"><button class="btn primary">Hinzufügen</button></p>
  </form>

  <p class="muted" style="margin:36px 4px 14px;font-size:15px">Preise mit Komma eingeben. Deaktivierte Artikel erscheinen nicht an der Kasse. Die Liste zeigt die Reihenfolge an der Kasse – Artikel am Griff ≡ festhalten und an die gewünschte Stelle ziehen. Über „Kategorie“ wandert ein Artikel in die andere Liste. Ein negativer Preis eignet sich z. B. für Pfandrückgabe.</p>
  <?php foreach (CATEGORIES as $cat => $catLabel):
      $list = $byCat[$cat] ?? [];
      if (!$list && $cat === 'sonstiges') continue; ?>
  <section class="card">
    <h2><?= e($catLabel) ?> <span class="muted" style="font-weight:400">· <?= count($list) ?></span></h2>
    <div class="table-wrap">
      <table class="stack">
        <thead>
          <tr><th></th><th>Symbol</th><th>Name</th><th>Kategorie</th><th class="num">Preis (€)</th><th>Aktiv</th><th></th></tr>
        </thead>
        <tbody data-category="<?= e($cat) ?>">
        <?php foreach ($list as $p): $f = 'p' . (int) $p['id']; ?>
          <tr id="row-<?= (int) $p['id'] ?>" data-id="<?= (int) $p['id'] ?>" class="<?= $p['active'] ? '' : 'inactive' ?>">
            <td class="c-move">
              <button type="button" class="drag-handle" aria-label="<?= e($p['name']) ?> verschieben (ziehen oder Pfeiltasten)" title="Ziehen zum Verschieben">
                <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true"><path d="M3 5h12M3 9h12M3 13h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
              </button>
            </td>
            <td class="c-icon">
              <input type="hidden" form="<?= $f ?>" name="icon" id="icon-<?= (int) $p['id'] ?>" value="<?= e($p['icon']) ?>">
              <button type="button" class="icon-pick" data-for="icon-<?= (int) $p['id'] ?>" data-autosave="<?= $f ?>" aria-label="Symbol für <?= e($p['name']) ?> ändern"><?= $p['icon'] !== '' ? icon_html($p['icon']) : '<span class="icon-pick-empty">＋</span>' ?></button>
            </td>
            <td class="c-name"><input form="<?= $f ?>" name="name" value="<?= e($p['name']) ?>" required maxlength="40" aria-label="Name"></td>
            <td class="c-cat" data-label="Kategorie">
              <select form="<?= $f ?>" name="category" aria-label="Kategorie">
                <?php foreach (CATEGORIES as $k => $v): ?>
                  <option value="<?= e($k) ?>" <?= $p['category'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
            <td class="num c-price" data-label="Preis (€)"><input form="<?= $f ?>" name="price" value="<?= e(number_format($p['price_cents'] / 100, 2, ',', '')) ?>" inputmode="decimal" required class="price-input" aria-label="Preis in Euro"></td>
            <td class="c-active" data-label="Aktiv"><input form="<?= $f ?>" name="active" type="checkbox" value="1" <?= $p['active'] ? 'checked' : '' ?> aria-label="Aktiv"></td>
            <td class="c-actions">
              <div class="actions">
                <form method="post" id="<?= $f ?>" class="inline-form">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                  <button class="btn small primary">Sichern</button>
                </form>
                <form method="post" class="inline-form" onsubmit="return confirm('„<?= e(addslashes($p['name'])) ?>“ wirklich löschen?')">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                  <button class="btn small danger">Löschen</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$list): ?><tr class="empty-row" data-empty><td colspan="7" class="muted">Noch keine <?= e($catLabel) ?> angelegt.</td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
  <?php endforeach; ?>
</main>
<div class="toast" id="toast" hidden></div>

<dialog id="icon-dialog" class="sheet icon-sheet" aria-label="Symbol wählen">
  <div class="icon-sheet-head">
    <h2>Symbol wählen</h2>
    <button type="button" class="btn small" data-close>Fertig</button>
  </div>
  <?php foreach (ICONS as $cat => $group): ?>
    <p class="icon-group-title"><?= e(CATEGORIES[$cat]) ?></p>
    <div class="icon-grid">
      <?php foreach ($group as $name => $label): ?>
        <button type="button" data-icon="svg:<?= e($name) ?>" title="<?= e($label) ?>"><?= icon_html('svg:' . $name) ?><span><?= e($label) ?></span></button>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
  <p class="icon-group-title">Emoji</p>
  <div class="icon-grid emoji">
    <?php foreach (array_merge(...array_values($emojis)) as $em): ?>
      <button type="button" data-icon="<?= e($em) ?>"><?= icon_html($em) ?></button>
    <?php endforeach; ?>
  </div>
  <div class="icon-own">
    <input type="text" id="icon-own" maxlength="8" placeholder="Eigenes Emoji" aria-label="Eigenes Emoji">
    <button type="button" class="btn small" id="icon-own-ok">Übernehmen</button>
    <button type="button" class="btn small danger" data-icon="">Kein Symbol</button>
  </div>
</dialog>

<script>
  (function () {
    var placeholders = <?= json_encode($placeholders, JSON_UNESCAPED_UNICODE) ?>;
    var dialog = document.getElementById('icon-dialog');
    var current = null; // Knopf, dessen Symbol gerade gewählt wird

    document.querySelectorAll('.icon-pick').forEach(function (btn) {
      btn.addEventListener('click', function () {
        current = btn;
        document.getElementById('icon-own').value = '';
        var value = document.getElementById(btn.dataset.for).value;
        dialog.querySelectorAll('[data-icon]').forEach(function (o) { o.classList.toggle('on', o.dataset.icon === value && value !== ''); });
        dialog.showModal();
        fitText(dialog);
      });
    });

    function choose(value, preview) {
      if (!current) return;
      document.getElementById(current.dataset.for).value = value;
      current.replaceChildren();
      if (preview) current.append(preview.cloneNode(true));
      else current.innerHTML = '<span class="icon-pick-empty">＋</span>';
      dialog.close();
      // In der Liste sofort speichern, beim neuen Artikel erst mit „Hinzufügen“
      if (current.dataset.autosave) document.getElementById(current.dataset.autosave).requestSubmit();
    }

    dialog.querySelectorAll('[data-icon]').forEach(function (o) {
      o.addEventListener('click', function () { choose(o.dataset.icon, o.querySelector('.ico')); });
    });
    document.getElementById('icon-own-ok').addEventListener('click', function () {
      var v = document.getElementById('icon-own').value.trim();
      if (!v) return;
      var span = document.createElement('span');
      span.className = 'ico ico-emoji';
      span.textContent = v;
      choose(v, span);
    });
    dialog.querySelector('[data-close]').addEventListener('click', function () { dialog.close(); });
    dialog.addEventListener('click', function (e) { if (e.target === dialog) dialog.close(); });

    // ------------------------------------------------ Drag & Drop der Reihenfolge
    var csrf = <?= json_encode(csrf_token()) ?>;
    var toastEl = document.getElementById('toast'), toastTimer;
    function toast(msg) {
      toastEl.textContent = msg; toastEl.hidden = false;
      clearTimeout(toastTimer); toastTimer = setTimeout(function () { toastEl.hidden = true; }, 1800);
    }
    function saveOrder(tbody) {
      var fd = new FormData();
      fd.append('csrf', csrf);
      fd.append('action', 'reorder');
      fd.append('category', tbody.dataset.category);
      tbody.querySelectorAll('tr[data-id]').forEach(function (r) { fd.append('ids[]', r.dataset.id); });
      fetch('artikel.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (res) { toast(res.ok ? 'Reihenfolge gespeichert' : 'Speichern fehlgeschlagen'); })
        .catch(function () { toast('Keine Verbindung – bitte erneut versuchen'); });
    }

    document.querySelectorAll('.drag-handle').forEach(function (handle) {
      var row = handle.closest('tr'), tbody = row.parentNode;
      var startY, startScroll, startOrder, raf, lastY, pointerId = null;

      function siblings() { return Array.prototype.filter.call(tbody.children, function (r) { return r.dataset.id; }); }
      function order() { return siblings().map(function (r) { return r.dataset.id; }).join(','); }

      function update() {
        var dy = lastY - startY + (window.scrollY - startScroll);
        row.style.transform = 'translateY(' + dy + 'px)';
        var rect = row.getBoundingClientRect(), mid = rect.top + rect.height / 2;
        var prev = row.previousElementSibling, next = row.nextElementSibling;
        if (prev && prev.dataset.id) {
          var pr = prev.getBoundingClientRect();
          if (mid < pr.top + pr.height / 2) { tbody.insertBefore(prev, row.nextSibling); startY -= pr.height; return update(); }
        }
        if (next && next.dataset.id) {
          var nr = next.getBoundingClientRect();
          if (mid > nr.top + nr.height / 2) { tbody.insertBefore(next, row); startY += nr.height; return update(); }
        }
      }
      // Am Bildschirmrand automatisch mitscrollen (lange Listen auf dem Handy)
      function autoscroll() {
        var edge = 90, speed = 0;
        if (lastY < edge + 52) speed = -Math.ceil((edge + 52 - lastY) / 6);
        else if (lastY > window.innerHeight - edge) speed = Math.ceil((lastY - (window.innerHeight - edge)) / 6);
        if (speed) { window.scrollBy(0, speed); update(); }
        raf = requestAnimationFrame(autoscroll);
      }

      handle.addEventListener('pointerdown', function (e) {
        if (e.button !== 0) return;
        e.preventDefault();
        pointerId = e.pointerId;
        startY = lastY = e.clientY; startScroll = window.scrollY; startOrder = order();
        row.classList.add('dragging'); document.body.classList.add('is-dragging');
        if (navigator.vibrate) navigator.vibrate(10);
        raf = requestAnimationFrame(autoscroll);
      });
      document.addEventListener('pointermove', function (e) {
        if (!row.classList.contains('dragging') || e.pointerId !== pointerId) return;
        e.preventDefault();
        lastY = e.clientY; update();
      }, { passive: false });
      function end() {
        if (!row.classList.contains('dragging')) return;
        cancelAnimationFrame(raf);
        row.classList.remove('dragging'); document.body.classList.remove('is-dragging');
        row.style.transform = '';
        pointerId = null;
        if (order() !== startOrder) saveOrder(tbody);
      }
      document.addEventListener('pointerup', function (e) { if (e.pointerId === pointerId) end(); });
      document.addEventListener('pointercancel', function (e) { if (e.pointerId === pointerId) end(); });

      // Tastatur: Pfeil hoch/runter verschiebt den fokussierten Artikel
      handle.addEventListener('keydown', function (e) {
        var target = e.key === 'ArrowUp' ? row.previousElementSibling : e.key === 'ArrowDown' ? row.nextElementSibling : null;
        if (!target || !target.dataset.id) return;
        e.preventDefault();
        if (e.key === 'ArrowUp') tbody.insertBefore(target, row.nextSibling); else tbody.insertBefore(target, row);
        handle.focus();
        saveOrder(tbody);
      });
    });

    // Kategorie wechseln: passenden Beispielnamen anzeigen
    document.querySelectorAll('.segmented input').forEach(function (r) {
      r.addEventListener('change', function () {
        document.getElementById('new-name').placeholder = placeholders[r.value];
      });
    });
  })();
</script>
<?php page_footer();
