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
        if (mb_strlen((string) ($pw[$name] ?? '')) < 6) {
            $errors[] = "Passwort für „{$name}“ muss mindestens 6 Zeichen haben.";
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
  <img class="auth-logo" src="assets/logo.png" alt="Wappen SG Hermania Löschenrod" width="105" height="116">
  <form method="post" class="card">
    <h1>Ersteinrichtung</h1>
    <p class="muted">Lege die Passwörter für die drei Zugänge fest. Weitere Benutzer kann der Vorstand später anlegen.</p>
    <?php foreach ($errors as $err): ?><p class="flash err"><?= e($err) ?></p><?php endforeach; ?>
    <?= csrf_field() ?>
    <?php foreach ($accounts as $name => $role): ?>
      <label>Passwort für <strong><?= e($name) ?></strong> <span class="muted">(<?= e(ROLES[$role]) ?>)</span>
        <input type="password" name="pw[<?= e($name) ?>]" required minlength="6" autocomplete="new-password">
      </label>
    <?php endforeach; ?>
    <button class="btn primary big">Einrichten</button>
  </form>
</main>
<?php page_footer();
