<?php
require __DIR__ . '/../app/bootstrap.php';

if (needs_setup()) {
    redirect('setup.php');
}
if (current_user()) {
    redirect('kasse.php');
}

$error = null;
$username = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $stmt = db()->prepare('SELECT id, password_hash FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if ($row && password_verify((string) ($_POST['password'] ?? ''), $row['password_hash'])) {
        login_user((int) $row['id']);
        redirect('kasse.php');
    }
    sleep(1); // bremst Passwort-Raten
    $error = 'Benutzername oder Passwort falsch.';
}

$users = db()->query('SELECT username FROM users ORDER BY role DESC, username')->fetchAll(PDO::FETCH_COLUMN);

page_header('Anmelden');
?>
<main class="narrow">
  <img class="auth-logo" src="assets/logo.png" alt="Wappen SG Hermania Löschenrod" width="105" height="116">
  <form method="post" class="card">
    <h1>Anmelden</h1>
    <?php if ($error): ?><p class="flash err"><?= e($error) ?></p><?php endif; ?>
    <?= csrf_field() ?>
    <label>Benutzer
      <select name="username" required>
        <?php foreach ($users as $u): ?>
          <option <?= strcasecmp($u, $username) === 0 ? 'selected' : '' ?>><?= e($u) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Passwort
      <input type="password" name="password" required autocomplete="current-password" autofocus>
    </label>
    <button class="btn primary big">Anmelden</button>
  </form>
</main>
<?php page_footer();
