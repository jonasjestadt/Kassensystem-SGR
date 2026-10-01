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
            } elseif (mb_strlen($password) < 6) {
                flash('Das Passwort muss mindestens 6 Zeichen haben.', 'err');
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
            if (mb_strlen($password) < 6) {
                flash('Das Passwort muss mindestens 6 Zeichen haben.', 'err');
                break;
            }
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            flash("Passwort für „{$target['username']}“ geändert.");
            break;

        case 'role':
            if (!$target) break;
            if ($target['role'] === 'vorstand' && $role !== 'vorstand' && vorstand_count($pdo) <= 1) {
                flash('Es muss mindestens ein Vorstand bleiben.', 'err');
                break;
            }
            $pdo->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $id]);
            flash("Rolle von „{$target['username']}“ ist jetzt " . ROLES[$role] . ".");
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

$users = $pdo->query('SELECT id, username, role, created_at FROM users ORDER BY role DESC, username')->fetchAll();

page_header('Benutzer', $user, 'benutzer.php');
?>
<main>
  <div class="page-head">
    <div>
      <h1>Benutzer</h1>
      <p>Wer sich an der Theke anmelden darf.</p>
    </div>
  </div>

  <section class="card">
    <h2>Zugänge</h2>
    <div class="table-wrap">
      <table class="stack users">
        <thead><tr><th>Benutzer</th><th>Rolle</th><th>Neues Passwort</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td class="c-user"><strong><?= e($u['username']) ?></strong><?= (int) $u['id'] === (int) $user['id'] ? ' <span class="muted">(du)</span>' : '' ?></td>
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
            <td class="c-pw" data-label="Neues Passwort">
              <form method="post" class="pw-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="password">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <input type="password" name="password" minlength="6" required placeholder="mind. 6 Zeichen" autocomplete="new-password" aria-label="Neues Passwort für <?= e($u['username']) ?>">
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
        <select name="role">
          <?php foreach (ROLES as $k => $v): ?><option value="<?= e($k) ?>" <?= $k === 'kasse' ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
        </select>
      </label>
      <label class="wide">Passwort <input type="password" name="password" required minlength="6" autocomplete="new-password"></label>
    </div>
    <p style="margin:20px 0 0"><button class="btn primary">Anlegen</button></p>
  </form>
</main>
<?php page_footer();
