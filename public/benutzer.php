<?php
require __DIR__ . '/../app/bootstrap.php';
$user = require_login('vorstand');
$pdo = db();

function vorstand_count(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'vorstand'")->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $target = $stmt->fetch();
    $password = (string) ($_POST['password'] ?? '');
    $role = isset(ROLES[$_POST['role'] ?? '']) ? $_POST['role'] : 'kasse';

    switch ($_POST['action'] ?? '') {
        case 'create':
            $name = trim((string) ($_POST['username'] ?? ''));
            if (!preg_match('/^[\p{L}0-9._-]{2,30}$/u', $name)) {
                flash('Benutzername: 2–30 Zeichen, nur Buchstaben, Ziffern, Punkt, Binde- oder Unterstrich.', 'err');
            } elseif ($err = credential_error($role, $password)) {
                flash($err, 'err');
            } else {
                try {
                    $pdo->prepare('INSERT INTO users (username, password_hash, role, created_at) VALUES (?, ?, ?, ?)')
                        ->execute([$name, password_hash($password, PASSWORD_DEFAULT), $role, date('Y-m-d H:i:s')]);
                    flash("Benutzer „{$name}“ wurde angelegt.");
                } catch (PDOException) {
                    flash("Den Benutzernamen „{$name}“ gibt es bereits.", 'err');
                }
            }
            break;

        case 'password':
            if (!$target) break;
            if ($err = credential_error($target['role'], $password)) {
                flash($err, 'err');
                break;
            }
            // Neue Zugangsdaten heben auch eine Sperre nach Fehlversuchen auf
            $pdo->prepare('UPDATE users SET password_hash = ?, failed_logins = 0, locked_until = 0 WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            $what = uses_pin($target['role']) ? 'PIN' : 'Passwort';
            flash("{$what} für „{$target['username']}“ geändert.");
            break;

        case 'role':
            if (!$target) break;
            if ($target['role'] === 'vorstand' && $role !== 'vorstand' && vorstand_count($pdo) <= 1) {
                flash('Es muss mindestens ein Vorstand bleiben.', 'err');
                break;
            }
            if ($role === $target['role']) break;
            // Kasse nutzt eine PIN, Vorstand ein Passwort: alte Zugangsdaten passen nicht mehr
            $pdo->prepare('UPDATE users SET role = ?, password_hash = ? WHERE id = ?')
                ->execute([$role, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $id]);
            $what = uses_pin($role) ? 'eine neue 4-stellige PIN' : 'ein neues Passwort';
            flash("„{$target['username']}“ ist jetzt " . ROLES[$role] . ". Bitte jetzt {$what} festlegen – vorher ist keine Anmeldung möglich.");
            break;

        case 'delete':
            if (!$target) break;
            if ($id === (int) $user['id']) {
                flash('Du kannst dich nicht selbst löschen.', 'err');
                break;
            }
            if ($target['role'] === 'vorstand' && vorstand_count($pdo) <= 1) {
                flash('Es muss mindestens ein Vorstand bleiben.', 'err');
                break;
            }
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            flash("Benutzer „{$target['username']}“ gelöscht.");
            break;
    }
    redirect('benutzer.php');
}

$users = $pdo->query('SELECT id, username, role, created_at, locked_until FROM users ORDER BY role DESC, username')->fetchAll();

page_header('Benutzer', $user, 'benutzer.php');
?>
<main>
  <div class="page-head">
    <div>
      <h1>Benutzer</h1>
      <p>Wer sich an der Theke anmelden darf. Kassen melden sich mit einer 4-stelligen PIN an, der Vorstand mit Passwort.</p>
    </div>
  </div>

  <section class="card">
    <h2>Zugänge</h2>
    <div class="table-wrap">
      <table class="stack users">
        <thead><tr><th>Benutzer</th><th>Rolle</th><th>Neue PIN / neues Passwort</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td class="c-user"><strong><?= e($u['username']) ?></strong><?= (int) $u['id'] === (int) $user['id'] ? ' <span class="muted">(du)</span>' : '' ?><?= (int) $u['locked_until'] > time() ? ' <span class="badge-locked">gesperrt</span>' : '' ?></td>
            <td class="c-role" data-label="Rolle">
              <form method="post" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="role">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <select name="role" onchange="this.form.submit()" aria-label="Rolle von <?= e($u['username']) ?>">
                  <?php foreach (ROLES as $k => $v): ?>
                    <option value="<?= e($k) ?>" <?= $u['role'] === $k ? 'selected' : '' ?>><?= e($v) ?></option>
                  <?php endforeach; ?>
                </select>
              </form>
            </td>
            <?php $pin = uses_pin($u['role']); ?>
            <td class="c-pw" data-label="<?= $pin ? 'Neue PIN' : 'Neues Passwort' ?>">
              <form method="post" class="pw-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="password">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <?php if ($pin): ?>
                <input type="password" name="password" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" minlength="4" required placeholder="4-stellige PIN" autocomplete="off" title="Genau 4 Ziffern" aria-label="Neue PIN für <?= e($u['username']) ?>">
                <?php else: ?>
                <input type="password" name="password" minlength="6" required placeholder="mind. 6 Zeichen" autocomplete="new-password" aria-label="Neues Passwort für <?= e($u['username']) ?>">
                <?php endif; ?>
                <button class="btn small">Ändern</button>
              </form>
            </td>
            <td class="num c-del">
              <?php if ((int) $u['id'] !== (int) $user['id']): ?>
              <form method="post" class="inline-form" onsubmit="return confirm('Benutzer wirklich löschen?')">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <button class="btn small danger">Löschen</button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <form method="post" class="card">
    <h2>Neuer Benutzer</h2>
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="form-grid user-grid">
      <label class="wide">Benutzername <input name="username" required maxlength="30" placeholder="z. B. kasse3"></label>
      <label>Rolle
        <select name="role" id="new-role">
          <?php foreach (ROLES as $k => $v): ?><option value="<?= e($k) ?>" <?= $k === 'kasse' ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label class="wide"><span id="new-secret-label">PIN (4 Ziffern)</span>
        <input type="password" name="password" id="new-secret" required inputmode="numeric" pattern="[0-9]{4}" minlength="4" maxlength="4" autocomplete="off" title="Genau 4 Ziffern">
      </label>
    </div>
    <p style="margin:20px 0 0"><button class="btn primary">Anlegen</button></p>
  </form>
</main>
<script>
  // Beim Anlegen: Kasse bekommt eine PIN, Vorstand ein Passwort
  (function () {
    var role = document.getElementById('new-role'), input = document.getElementById('new-secret');
    role.addEventListener('change', function () {
      var pin = role.value === 'kasse';
      document.getElementById('new-secret-label').textContent = pin ? 'PIN (4 Ziffern)' : 'Passwort (mind. 6 Zeichen)';
      input.value = '';
      input.inputMode = pin ? 'numeric' : 'text';
      if (pin) { input.pattern = '[0-9]{4}'; input.minLength = 4; input.maxLength = 4; input.title = 'Genau 4 Ziffern'; input.autocomplete = 'off'; }
      else { input.removeAttribute('pattern'); input.minLength = 6; input.removeAttribute('maxlength'); input.title = ''; input.autocomplete = 'new-password'; }
    });
  })();
</script>
<?php page_footer();
