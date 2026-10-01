<?php
require __DIR__ . '/../app/bootstrap.php';

// Nur beim allerersten Aufruf erreichbar – danach existiert ein Vorstand.
if (!needs_setup()) {
    redirect('login.php');
}

$accounts = ['vorstand' => 'vorstand', 'kasse1' => 'kasse', 'kasse2' => 'kasse'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $pw = $_POST['pw'] ?? [];
    foreach ($accounts as $name => $role) {
        if ($err = credential_error($role, (string) ($pw[$name] ?? ''))) {
            $errors[] = "„{$name}“: {$err}";
        }
    }
    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        $ins = $pdo->prepare('INSERT INTO users (username, password_hash, role, created_at) VALUES (?, ?, ?, ?)');
        foreach ($accounts as $name => $role) {
            $ins->execute([$name, password_hash((string) $pw[$name], PASSWORD_DEFAULT), $role, date('Y-m-d H:i:s')]);
        }
        if ((int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn() === 0) {
            // Startsortiment – Preise sind Platzhalter und im Bereich „Artikel“ änderbar.
            $seed = [
                ['Bier',      'svg:bier', 'getraenk', 300],
                ['Apfelwein', 'svg:geripptes', 'getraenk', 250],
                ['Wein',      'svg:wein', 'getraenk', 350],
                ['Aperol',    'svg:aperol', 'getraenk', 550],
                ['Apfelschorle', 'svg:apfelschorle', 'getraenk', 200],
                ['Cola',         'svg:cola',         'getraenk', 200],
                ['Fanta',        'svg:fanta',        'getraenk', 200],
                ['Wasser',       'svg:wasser',       'getraenk', 150],
                ['Pommes',    'svg:pommes', 'essen',    300],
                ['Bratwurst mit Brötchen', 'svg:bratwurst', 'essen', 350],
                ['Steak mit Brötchen',     'svg:steak',     'essen', 600],
                ['Bratwurst mit Pommes', 'svg:bratwurst-pommes', 'essen', 600],
                ['Steak mit Pommes',     'svg:steak-pommes',     'essen', 850],
            ];
            $p = $pdo->prepare('INSERT INTO products (name, icon, category, price_cents, sort) VALUES (?, ?, ?, ?, ?)');
            foreach ($seed as $i => [$n, $icon, $cat, $price]) {
                $p->execute([$n, $icon, $cat, $price, ($i + 1) * 10]);
            }
        }
        $pdo->commit();
        login_user((int) $pdo->query("SELECT id FROM users WHERE username = 'vorstand'")->fetchColumn());
        flash('Einrichtung abgeschlossen. Bitte die Preise unter „Artikel“ prüfen.');
        redirect('artikel.php');
    }
}

page_header('Ersteinrichtung');
?>
<main class="narrow">
  <img class="auth-logo" src="<?= asset('assets/logo.png') ?>" alt="Wappen SG Rönshausen" width="105" height="125">
  <form method="post" class="card">
    <h1>Ersteinrichtung</h1>
    <p class="muted">Lege das Passwort für den Vorstand und die 4-stelligen PINs für die beiden Kassen fest. Weitere Benutzer kann der Vorstand später anlegen.</p>
    <?php foreach ($errors as $err): ?><p class="flash err"><?= e($err) ?></p><?php endforeach; ?>
    <?= csrf_field() ?>
    <?php foreach ($accounts as $name => $role): ?>
      <?php if (uses_pin($role)): ?>
      <label>PIN für <strong><?= e($name) ?></strong> <span class="muted">(<?= e(ROLES[$role]) ?>, 4 Ziffern)</span>
        <input type="password" name="pw[<?= e($name) ?>]" required inputmode="numeric" pattern="[0-9]{4}" minlength="4" maxlength="4" autocomplete="off" title="Genau 4 Ziffern">
      </label>
      <?php else: ?>
      <label>Passwort für <strong><?= e($name) ?></strong> <span class="muted">(<?= e(ROLES[$role]) ?>, mind. 6 Zeichen)</span>
        <input type="password" name="pw[<?= e($name) ?>]" required minlength="6" autocomplete="new-password">
      </label>
      <?php endif; ?>
    <?php endforeach; ?>
    <button class="btn primary big">Einrichten</button>
  </form>
</main>
<?php page_footer();
