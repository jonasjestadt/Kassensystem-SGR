<?php
declare(strict_types=1);

/*
 * Gemeinsamer Einstieg für alle Seiten: Konfiguration, Datenbank, Session,
 * Login-Prüfung und kleine Hilfsfunktionen.
 */

date_default_timezone_set('Europe/Berlin');

const APP_NAME = 'SG Rönshausen – Theke';
const DATA_DIR = __DIR__ . '/../data';
const DB_FILE  = DATA_DIR . '/kasse.sqlite';
const SESSION_DAYS = 30; // Tablets an der Theke bleiben lange angemeldet

const ROLES = ['vorstand' => 'Vorstand', 'kasse' => 'Kasse'];
const CATEGORIES = ['getraenk' => 'Getränke', 'essen' => 'Essen', 'sonstiges' => 'Sonstiges'];

/** Umbenannte Symbole, damit bereits gespeicherte Artikel weiter funktionieren. */
const ICON_ALIASES = ['softdrink' => 'cola', 'limo' => 'fanta'];

/** Eigene Artikel-Symbole (public/assets/icons/<name>.svg), gespeichert als "svg:<name>". */
const ICONS = [
    'getraenk' => [
        'geripptes' => 'Apfelwein im Gerippten', 'bembel' => 'Bembel',
        'bier' => 'Bier', 'radler' => 'Radler', 'weizen' => 'Weißbier', 'wein' => 'Weißwein', 'rotwein' => 'Rotwein',
        'aperol' => 'Aperol Spritz', 'schnaps' => 'Schnaps', 'apfelschorle' => 'Apfelschorle',
        'cola' => 'Cola', 'fanta' => 'Fanta / Orangenlimo', 'wasser' => 'Wasser', 'kaffee' => 'Kaffee',
    ],
    'essen' => [
        'bratwurst' => 'Bratwurst mit Brötchen', 'bratwurst-pommes' => 'Bratwurst mit Pommes',
        'steak' => 'Steak mit Brötchen', 'steak-pommes' => 'Steak mit Pommes',
        'pommes' => 'Pommes', 'kuchen' => 'Kuchen',
    ],
    'sonstiges' => ['pfand' => 'Pfand'],
];

// ---------------------------------------------------------------- Datenbank

function db(): PDO
{
    static $pdo = null;
    if ($pdo) {
        return $pdo;
    }
    if (!is_dir(DATA_DIR)) {
        mkdir(DATA_DIR, 0750, true);
    }
    $pdo = new PDO('sqlite:' . DB_FILE, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY,
            username      TEXT NOT NULL UNIQUE COLLATE NOCASE,
            password_hash TEXT NOT NULL,
            role          TEXT NOT NULL CHECK (role IN ('vorstand', 'kasse')),
            created_at    TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS products (
            id          INTEGER PRIMARY KEY,
            name        TEXT NOT NULL,
            icon        TEXT NOT NULL DEFAULT '',
            category    TEXT NOT NULL DEFAULT 'getraenk',
            price_cents INTEGER NOT NULL,
            active      INTEGER NOT NULL DEFAULT 1,
            sort        INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS orders (
            id          INTEGER PRIMARY KEY,
            client_id   TEXT NOT NULL UNIQUE,
            user_id     INTEGER REFERENCES users(id) ON DELETE SET NULL,
            created_at  TEXT NOT NULL,
            total_cents INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS order_items (
            id          INTEGER PRIMARY KEY,
            order_id    INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
            product_id  INTEGER REFERENCES products(id) ON DELETE SET NULL,
            name        TEXT NOT NULL,
            price_cents INTEGER NOT NULL,
            qty         INTEGER NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_orders_created ON orders(created_at);
        CREATE INDEX IF NOT EXISTS idx_items_order ON order_items(order_id);
    ");

    // Spalten, die nach der ersten Version dazugekommen sind
    $cols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(), 'name');
    if (!in_array('failed_logins', $cols, true)) {
        $pdo->exec('ALTER TABLE users ADD COLUMN failed_logins INTEGER NOT NULL DEFAULT 0');
        $pdo->exec('ALTER TABLE users ADD COLUMN locked_until INTEGER NOT NULL DEFAULT 0');
    }
}

// ------------------------------------------------------- Zugangsdaten

const MAX_FAILED_LOGINS = 5;      // danach wird der Zugang gesperrt …
const LOCK_MINUTES = 5;           // … für so viele Minuten

/** Kasse meldet sich mit 4-stelliger PIN an, Vorstand mit Passwort. */
function uses_pin(string $role): bool
{
    return $role === 'kasse';
}

/** Prüft PIN bzw. Passwort für eine Rolle; liefert Fehlermeldung oder null. */
function credential_error(string $role, string $secret): ?string
{
    if (uses_pin($role)) {
        return preg_match('/^\d{4}$/', $secret) ? null : 'Die PIN muss aus genau 4 Ziffern bestehen.';
    }
    return mb_strlen($secret) >= 6 ? null : 'Das Passwort muss mindestens 6 Zeichen haben.';
}

function needs_setup(): bool
{
    return (int) db()->query("SELECT COUNT(*) FROM users WHERE role = 'vorstand'")->fetchColumn() === 0;
}

// ------------------------------------------------------------------ Session

function start_session(): void
{
    $dir = DATA_DIR . '/sessions';
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $lifetime = SESSION_DAYS * 86400;
    session_save_path($dir);
    ini_set('session.gc_maxlifetime', (string) $lifetime);
    session_name('sgr_kasse');
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

start_session();

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $user = null;
    if (!empty($_SESSION['uid'])) {
        $stmt = db()->prepare('SELECT id, username, role FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['uid']]);
        $user = $stmt->fetch() ?: null;
    }
    return $user;
}

function require_login(?string $role = null): array
{
    if (needs_setup()) {
        redirect('setup.php');
    }
    $user = current_user();
    if (!$user) {
        redirect('login.php');
    }
    if ($role && $user['role'] !== $role) {
        http_response_code(403);
        exit('Keine Berechtigung.');
    }
    return $user;
}

function login_user(int $id): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = $id;
}

// ------------------------------------------------------------------- CSRF

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

function check_csrf(): void
{
    $token = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF'] ?? '';
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(400);
        exit('Ungültige Anfrage – bitte Seite neu laden.');
    }
}

// --------------------------------------------------------------- Helfer

function e(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function euro(int $cents): string
{
    return number_format($cents / 100, 2, ',', '.') . ' €';
}

/** "3,50" / "3.50" / "3" -> 350; null bei ungültiger Eingabe */
function parse_euro(string $input): ?int
{
    $s = str_replace([' ', '€'], '', trim($input));
    $s = str_replace(',', '.', $s);
    if (!preg_match('/^-?\d+(\.\d{1,2})?$/', $s)) {
        return null;
    }
    return (int) round((float) $s * 100);
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

// ---------------------------------------------------------------- Layout

/** HTML für ein Artikel-Symbol: eigenes SVG ("svg:name") oder Emoji. */
function icon_html(string $icon, string $class = 'ico'): string
{
    if (str_starts_with($icon, 'svg:')) {
        $name = substr($icon, 4);
        $name = ICON_ALIASES[$name] ?? $name;
        foreach (ICONS as $group) {
            if (isset($group[$name])) {
                return '<img class="' . e($class) . '" src="' . asset("assets/icons/{$name}.svg") . '" alt="" draggable="false">';
            }
        }
        return '<span class="' . e($class) . ' ico-emoji">❔</span>';
    }
    return '<span class="' . e($class) . ' ico-emoji">' . e($icon) . '</span>';
}

/** Asset-URL mit Änderungszeit, damit Geräte nach einem Update nicht die alte Version aus dem Cache nehmen. */
function asset(string $path): string
{
    $file = __DIR__ . '/../public/' . $path;
    return e($path) . (is_file($file) ? '?v=' . filemtime($file) : '');
}

/** Farbschema dieses Geräts: auto (folgt Systemeinstellung), light oder dark. */
function theme(): string
{
    $t = $_COOKIE['theme'] ?? 'auto';
    return in_array($t, ['light', 'dark'], true) ? $t : 'auto';
}

function page_header(string $title, ?array $user = null, string $active = '', string $bodyClass = ''): void
{
    $nav = [];
    if ($user) {
        $nav['kasse.php'] = 'Kasse';
        if ($user['role'] === 'vorstand') {
            $nav['artikel.php']    = 'Artikel';
            $nav['auswertung.php'] = 'Auswertung';
            $nav['benutzer.php']   = 'Benutzer';
        }
    }
    ?>
<!doctype html>
<html lang="de"<?= theme() !== 'auto' ? ' data-theme="' . theme() . '"' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#f5f5f7" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#000000" media="(prefers-color-scheme: dark)">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Theke">
<meta name="format-detection" content="telephone=no">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="icon" type="image/png" href="assets/favicon.png">
<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">
<link rel="manifest" href="manifest.webmanifest">
<script>
  if ('serviceWorker' in navigator && window.isSecureContext) {
    window.addEventListener('load', function () { navigator.serviceWorker.register('sw.js').catch(function () {}); });
  }
</script>
<link rel="stylesheet" href="<?= asset('assets/style.css') ?>">
</head>
<body class="<?= e($bodyClass) ?>">
<header class="topbar">
  <a class="brand" href="kasse.php"><img src="<?= asset('assets/logo.png') ?>" alt="" width="35" height="42">SG Rönshausen</a>
  <button type="button" class="theme-toggle" aria-label="Hell/Dunkel umschalten" title="Hell/Dunkel umschalten">
    <svg class="i-moon" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 14.5A8.5 8.5 0 0 1 9.5 3.5a8.5 8.5 0 1 0 11 11z" fill="currentColor"/></svg>
    <svg class="i-sun" width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="4.5" fill="currentColor"/><g stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.9 4.9l1.8 1.8M17.3 17.3l1.8 1.8M4.9 19.1l1.8-1.8M17.3 6.7l1.8-1.8"/></g></svg>
  </button>
  <script>
    // Ein Tipp wechselt zwischen hell und dunkel; gemerkt wird es für dieses Gerät
    document.currentScript.previousElementSibling.addEventListener('click', function () {
      var root = document.documentElement;
      var dark = root.dataset.theme === 'dark' || (!root.dataset.theme && matchMedia('(prefers-color-scheme: dark)').matches);
      var next = dark ? 'light' : 'dark';
      root.dataset.theme = next;
      document.cookie = 'theme=' + next + '; path=/; max-age=31536000; SameSite=Lax';
    });
  </script>
  <?php if ($user): ?>
  <button type="button" class="menu-toggle" aria-expanded="false" aria-controls="mainnav">
    <span class="menu-current"><?= e($nav[$active] ?? 'Menü') ?></span>
    <svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true"><path d="M2 5h14M2 9h14M2 13h14" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
  </button>
  <nav id="mainnav">
    <?php foreach ($nav as $href => $label): ?>
      <a href="<?= e($href) ?>" class="<?= $active === $href ? 'active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
    <a href="logout.php" class="logout" title="Abmelden"><span class="who"><?= e($user['username']) ?> · </span>Abmelden</a>
  </nav>
  <script>
    (function () {
      var bar = document.currentScript.parentNode, btn = bar.querySelector('.menu-toggle');
      var nav = bar.querySelector('nav'), brand = bar.querySelector('.brand');
      // Menü-Knopf statt Leiste, sobald die Einträge nicht in eine Zeile passen
      // (schmaler Bildschirm oder vergrößerte Systemschrift)
      function layout() {
        bar.classList.remove('compact', 'open');
        var tight = window.innerWidth <= 700 || nav.scrollWidth > nav.clientWidth + 1 ||
          brand.scrollWidth > brand.clientWidth + 1 || bar.scrollWidth > bar.clientWidth + 1;
        bar.classList.toggle('compact', tight);
      }
      layout();
      window.addEventListener('resize', layout);
      if (document.fonts) document.fonts.ready.then(layout);
      btn.addEventListener('click', function () {
        var open = bar.classList.toggle('open');
        btn.setAttribute('aria-expanded', open);
      });
      document.addEventListener('click', function (e) {
        if (!bar.contains(e.target)) { bar.classList.remove('open'); btn.setAttribute('aria-expanded', false); }
      });
    })();
  </script>
  <dialog class="sheet confirm" id="logout-dialog" aria-labelledby="logout-title">
    <form method="dialog">
      <p class="confirm-title" id="logout-title">Wirklich abmelden?</p>
      <p class="confirm-text">Angemeldet als <strong><?= e($user['username']) ?></strong>. Danach ist eine erneute Anmeldung mit Passwort nötig.</p>
      <div class="sheet-actions">
        <button value="cancel" class="btn secondary big" autofocus>Abbrechen</button>
        <a href="logout.php" class="btn danger-fill big">Abmelden</a>
      </div>
    </form>
  </dialog>
  <script>
    (function () {
      var dlg = document.getElementById('logout-dialog');
      document.querySelectorAll('a.logout').forEach(function (a) {
        a.addEventListener('click', function (e) {
          if (!dlg.showModal) return; // sehr alte Browser: direkt abmelden
          e.preventDefault();
          dlg.showModal();
        });
      });
      dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
    })();
  </script>
  <?php endif; ?>
</header>
<?php
    if ($f = flash()) {
        echo '<div class="flash ' . e($f['type']) . '">' . e($f['msg']) . '</div>';
    }
}

function page_footer(): void
{
    ?>
<script>
  // Lange Texte (z. B. Artikelnamen) verkleinern statt umbrechen
  window.fitText = function (root) {
    (root || document).querySelectorAll('.tile-name, .icon-grid button span:not(.ico), .fit').forEach(function (el) {
      el.style.fontSize = '';
      var size = parseFloat(getComputedStyle(el).fontSize);
      while (el.clientWidth && el.scrollWidth > el.clientWidth && size > 12) {
        size -= 0.5;
        el.style.fontSize = size + 'px';
      }
    });
  };
  fitText();
  window.addEventListener('resize', function () { fitText(); });
  if (document.fonts) document.fonts.ready.then(function () { fitText(); });
</script>
</body>
</html>
<?php
}
