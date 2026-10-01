<?php
require __DIR__ . '/../app/bootstrap.php';

if (needs_setup()) {
    redirect('setup.php');
}
if (current_user()) {
    redirect('kasse.php');
}

$error = null;
$username = (string) ($_GET['u'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $username = trim((string) ($_POST['username'] ?? ''));
    $secret = (string) ($_POST['password'] ?? '');
    $stmt = db()->prepare('SELECT id, role, password_hash, failed_logins, locked_until FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    if ($row && (int) $row['locked_until'] > time()) {
        $min = (int) ceil(((int) $row['locked_until'] - time()) / 60);
        $error = "Zu viele Fehlversuche. Bitte in {$min} " . ($min === 1 ? 'Minute' : 'Minuten') . ' erneut versuchen.';
    } elseif ($row && credential_error($row['role'], $secret) === null && password_verify($secret, $row['password_hash'])) {
        db()->prepare('UPDATE users SET failed_logins = 0, locked_until = 0 WHERE id = ?')->execute([$row['id']]);
        login_user((int) $row['id']);
        redirect('kasse.php');
    } else {
        sleep(1); // bremst Raten zusätzlich
        $what = $row && uses_pin($row['role']) ? 'PIN' : 'Passwort';
        $error = "{$what} falsch.";
        if ($row) {
            $failed = (int) $row['failed_logins'] + 1;
            $lock = $failed >= MAX_FAILED_LOGINS ? time() + LOCK_MINUTES * 60 : 0;
            db()->prepare('UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?')
                ->execute([$lock ? 0 : $failed, $lock, $row['id']]);
            if ($lock) {
                $error = 'Zu viele Fehlversuche. Der Zugang ist für ' . LOCK_MINUTES . ' Minuten gesperrt.';
            } elseif (MAX_FAILED_LOGINS - $failed <= 2) {
                $left = MAX_FAILED_LOGINS - $failed;
                $error .= " Noch {$left} " . ($left === 1 ? 'Versuch' : 'Versuche') . ' bis zur Sperre.';
            }
        }
    }
}

$users = db()->query("SELECT username, role FROM users ORDER BY CASE role WHEN 'kasse' THEN 0 ELSE 1 END, username")->fetchAll();
$selected = null;
foreach ($users as $u) {
    if (strcasecmp($u['username'], $username) === 0) {
        $selected = $u;
    }
}

page_header('Anmelden');
?>
<main class="narrow login">
  <img class="auth-logo" src="assets/logo.png" alt="Wappen SG Hermania Löschenrod" width="105" height="116">

  <!-- Schritt 1: Wer hat Dienst? -->
  <section class="card login-step" id="step-user" <?= $selected ? 'hidden' : '' ?>>
    <h1>Wer hat Dienst?</h1>
    <div class="user-tiles">
      <?php foreach ($users as $u): ?>
        <button type="button" class="user-tile" data-user="<?= e($u['username']) ?>" data-pin="<?= uses_pin($u['role']) ? '1' : '0' ?>">
          <span class="avatar <?= e($u['role']) ?>"><?= e(mb_strtoupper(mb_substr($u['username'], 0, 1))) ?></span>
          <span class="user-name"><?= e($u['username']) ?></span>
          <span class="user-role"><?= e(ROLES[$u['role']]) ?></span>
        </button>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- Schritt 2: PIN oder Passwort -->
  <form method="post" class="card login-step" id="step-secret" <?= $selected ? '' : 'hidden' ?>>
    <?= csrf_field() ?>
    <input type="hidden" name="username" id="login-user" value="<?= e($selected['username'] ?? '') ?>">
    <button type="button" class="link back" id="back">‹ Anderer Benutzer</button>
    <div class="login-who">
      <span class="avatar <?= e($selected['role'] ?? 'kasse') ?>" id="who-avatar"><?= e(mb_strtoupper(mb_substr($selected['username'] ?? '', 0, 1))) ?></span>
      <h1 id="who-name"><?= e($selected['username'] ?? '') ?></h1>
    </div>
    <p class="login-error" id="login-error" role="alert" <?= $error ? '' : 'hidden' ?>><?= e($error) ?></p>

    <div id="pin-area" <?= $selected && !uses_pin($selected['role']) ? 'hidden' : '' ?>>
      <p class="pin-hint">PIN eingeben</p>
      <div class="pin-dots <?= $error ? 'shake' : '' ?>" id="pin-dots" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
      <div class="keypad" role="group" aria-label="Ziffernfeld">
        <?php foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9] as $d): ?>
          <button type="button" data-digit="<?= $d ?>"><?= $d ?></button>
        <?php endforeach; ?>
        <span></span>
        <button type="button" data-digit="0">0</button>
        <button type="button" class="key-del" id="pin-del" aria-label="Letzte Ziffer löschen">
          <svg width="28" height="22" viewBox="0 0 28 22" aria-hidden="true"><path d="M9 2h15a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H9L2 11z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M13 7l7 8M20 7l-7 8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
        </button>
      </div>
    </div>

    <div id="pw-area" <?= $selected && !uses_pin($selected['role']) ? '' : 'hidden' ?>>
      <label>Passwort
        <input type="password" name="password" id="login-pw" autocomplete="current-password">
      </label>
      <button class="btn primary big">Anmelden</button>
    </div>
  </form>
</main>

<script>
  (function () {
    var stepUser = document.getElementById('step-user'), stepSecret = document.getElementById('step-secret');
    var userIn = document.getElementById('login-user'), pwIn = document.getElementById('login-pw');
    var pinArea = document.getElementById('pin-area'), pwArea = document.getElementById('pw-area');
    var dots = document.getElementById('pin-dots').children, errEl = document.getElementById('login-error');
    var pin = '', usesPin = pinArea.hidden === false && stepSecret.hidden === false;

    function showDots() {
      for (var i = 0; i < dots.length; i++) dots[i].classList.toggle('on', i < pin.length);
    }
    function choose(tile) {
      userIn.value = tile.dataset.user;
      usesPin = tile.dataset.pin === '1';
      document.getElementById('who-name').textContent = tile.dataset.user;
      var av = document.getElementById('who-avatar');
      av.textContent = tile.querySelector('.avatar').textContent;
      av.className = tile.querySelector('.avatar').className;
      pinArea.hidden = !usesPin; pwArea.hidden = usesPin;
      errEl.hidden = true; pin = ''; showDots(); pwIn.value = '';
      stepUser.hidden = true; stepSecret.hidden = false;
      if (!usesPin) pwIn.focus();
    }
    document.querySelectorAll('.user-tile').forEach(function (t) { t.addEventListener('click', function () { choose(t); }); });
    document.getElementById('back').addEventListener('click', function () {
      stepSecret.hidden = true; stepUser.hidden = false; pin = ''; showDots();
    });

    function type(d) {
      if (pin.length >= 4) return;
      pin += d; showDots();
      document.getElementById('pin-dots').classList.remove('shake');
      if (pin.length === 4) {
        pwIn.value = pin;
        setTimeout(function () { stepSecret.submit(); }, 120); // Punkt kurz zeigen, dann anmelden
      }
    }
    document.querySelectorAll('[data-digit]').forEach(function (b) {
      b.addEventListener('click', function () { type(b.dataset.digit); });
    });
    document.getElementById('pin-del').addEventListener('click', function () { pin = pin.slice(0, -1); showDots(); });
    // Ziffern auch über eine Tastatur
    document.addEventListener('keydown', function (e) {
      if (stepSecret.hidden || !usesPin) return;
      if (/^\d$/.test(e.key)) type(e.key);
      else if (e.key === 'Backspace') { pin = pin.slice(0, -1); showDots(); }
    });
    showDots();
  })();
</script>
<?php page_footer();
