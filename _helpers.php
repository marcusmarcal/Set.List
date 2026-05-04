<?php
// ── SESSION ───────────────────────────────────────────────────────
// Must be called before any output. Pages that need auth call requireAuth().
if (session_status() === PHP_SESSION_NONE) session_start();

// ── ENV LOADER ────────────────────────────────────────────────────
function loadEnvValue($key) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $envFile = __DIR__ . '/.env';
        if (file_exists($envFile)) {
            foreach (file($envFile) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') continue;
                [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
                $cache[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
            }
        }
    }
    return $cache[$key] ?? null;
}

// ── AUTH HELPERS ──────────────────────────────────────────────────
function getAdminPassword() {
    return loadEnvValue('ADMIN_PASSWORD') ?: '';
}

function isLocked() {
    return getAdminPassword() !== '';
}

function isAuthed() {
    if (!isLocked()) return true;          // no password → always open
    return !empty($_SESSION['sl_authed']); // session set after login
}

/**
 * Call at the top of any page/action that writes data.
 * If not authed: for AJAX/POST-only actions → return JSON error and exit.
 * For pages with UI → set $authRequired = true so the page can show the lock modal.
 * Returns true if authed, false if not.
 */
function checkAuth() {
    // Process login POST (works from any page)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === '_login') {
        header('Content-Type: application/json');
        if ($_POST['password'] === getAdminPassword()) {
            $_SESSION['sl_authed'] = true;
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Senha incorreta.']);
        }
        exit;
    }
    // Logout
    if (isset($_GET['logout'])) {
        unset($_SESSION['sl_authed']);
        $back = $_SERVER['HTTP_REFERER'] ?? 'index.php';
        header('Location: ' . $back);
        exit;
    }
    return isAuthed();
}

/**
 * For pure-action scripts (delete.php, and AJAX endpoints):
 * if not authed, respond and exit immediately.
 */
function requireAuthOrDie($redirectTo = 'index.php') {
    if (!isAuthed()) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || isset($_POST['ajax_edit']) || isset($_POST['order'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'auth']);
            exit;
        }
        $pl = $_POST['pl'] ?? $_GET['pl'] ?? '';
        header('Location: ' . $redirectTo . ($pl ? '?pl=' . urlencode($pl) : ''));
        exit;
    }
}

// ── PLAYLIST MANAGER ──────────────────────────────────────────────
$playlistsFile = __DIR__ . '/playlists.json';

function loadPlaylists() {
    global $playlistsFile;
    if (!file_exists($playlistsFile)) {
        $default = [[
            'id' => 'principal', 'name' => 'Marcvs Marcal',
            'spotify_id' => '4pcomesNQA6DPXj1HFpOjf', 'is_default' => true
        ]];
        file_put_contents($playlistsFile, json_encode($default, JSON_PRETTY_PRINT));
        return $default;
    }
    return json_decode(file_get_contents($playlistsFile), true) ?: [];
}

function savePlaylists($playlists) {
    global $playlistsFile;
    file_put_contents($playlistsFile, json_encode($playlists, JSON_PRETTY_PRINT));
}

function getDefaultPlaylist($playlists) {
    foreach ($playlists as $pl) { if (!empty($pl['is_default'])) return $pl; }
    return $playlists[0] ?? null;
}

function getActivePlaylist() {
    $playlists = loadPlaylists();
    $id = $_GET['pl'] ?? null;
    if ($id) {
        foreach ($playlists as $pl) { if ($pl['id'] === $id) return $pl; }
    }
    return getDefaultPlaylist($playlists);
}

function songsFile($playlist) {
    $id = preg_replace('/[^a-z0-9_-]/i', '', $playlist['id']);
    return __DIR__ . "/songs_{$id}.json";
}

function loadSongs($playlist) {
    $file = songsFile($playlist);
    if (!file_exists($file)) {
        $legacy = __DIR__ . '/songs.json';
        if (!empty($playlist['is_default']) && file_exists($legacy)) {
            $data = json_decode(file_get_contents($legacy), true) ?: [];
            file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
            return $data;
        }
        return [];
    }
    return json_decode(file_get_contents($file), true) ?: [];
}

function saveSongs($playlist, $songs) {
    file_put_contents(songsFile($playlist), json_encode($songs, JSON_PRETTY_PRINT));
}

function sortSongs($songs, $column = 'index', $order = 'asc') {
    usort($songs, function($a, $b) use ($column, $order) {
        $valA = isset($a[$column]) ? $a[$column] : '';
        $valB = isset($b[$column]) ? $b[$column] : '';
        $cmp  = strcmp(strtoupper((string)$valA), strtoupper((string)$valB));
        return $order === 'desc' ? -$cmp : $cmp;
    });
    return $songs;
}

function cleanTitle($title) {
    $keywords = 'live|ao vivo|remaster(?:ed)?(?:\s+\d{4})?|\d{4}[\w\s\-]*mix'
              . '|bonus track|explicit|radio edit|single version|album version'
              . '|deluxe|acoustic|demo|instrumental|extended|intro|outro';
    $title = preg_replace('/\s*[\(\[]\s*(?:' . $keywords . ')[^\)\]]*[\)\]]/iu', '', $title);
    $title = preg_replace('/\s+-\s+(?:' . $keywords . ').*/iu', '', $title);
    return trim($title);
}

function formatCifraUrl($url, $source = null) {
    if (empty($url) || $url === 'N/A') return null;
    if (preg_match('/^https?:\/\//', $url)) return $url;
    if ($source === 'ultimate_guitar')
        return 'https://tabs.ultimate-guitar.com/' . ltrim($url, '/');
    return 'https://www.cifraclub.com.br/' . ltrim($url, '/');
}

function detectCifraSource($url) {
    if (empty($url) || $url === 'N/A') return 'none';
    if (strpos($url, 'ultimate-guitar.com') !== false) return 'ultimate_guitar';
    if (strpos($url, 'cifraclub.com.br') !== false || !preg_match('/^https?:\/\//', $url)) return 'cifraclub';
    return 'other';
}

function cifraDisplayUrl($song) {
    $url    = $song['cifra_url']    ?? '';
    $source = $song['cifra_source'] ?? detectCifraSource($url);
    return formatCifraUrl($url, $source);
}

function cifraSourceLabel($song) {
    $source = $song['cifra_source'] ?? detectCifraSource($song['cifra_url'] ?? '');
    return match($source) {
        'ultimate_guitar' => 'UG',
        'cifraclub'       => 'CC',
        default           => '↗',
    };
}

// ── SIDEBAR ──────────────────────────────────────────────────────
function renderSidebar($activePage = 'index') {
    $playlists  = loadPlaylists();
    $activePl   = getActivePlaylist();
    $activePlId = $activePl['id'] ?? '';
    $plParam    = '?pl=' . urlencode($activePlId);

    $navItems = [
        ['href' => 'index.php'       . $plParam, 'label' => 'Músicas',          'page' => 'index',
         'icon' => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>'],
        ['href' => 'add.php'         . $plParam, 'label' => 'Adicionar',        'page' => 'add',
         'icon' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/>'],
        ['href' => 'import.php'      . $plParam, 'label' => 'Importar Spotify', 'page' => 'import',
         'icon' => '<polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/>'],
        ['href' => 'playlists.php',               'label' => 'Listas Spotify',  'page' => 'playlists',
         'icon' => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>'],
        ['href' => 'print_songs.php' . $plParam, 'label' => 'Imprimir',         'page' => 'print',
         'icon' => '<polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/>'],
    ];
    ?>
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-logo">
        <div class="wordmark">Set<span>.</span>List</div>
        <div class="tagline">Music Manager</div>
      </div>

      <div class="sidebar-playlists">
        <div class="sidebar-section-label" style="margin-bottom:10px">Listas</div>
        <?php foreach ($playlists as $pl): ?>
          <a href="index.php?pl=<?= urlencode($pl['id']) ?>"
             class="playlist-item <?= $pl['id'] === $activePlId ? 'active' : '' ?>">
            <span class="pl-dot"></span>
            <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              <?= htmlspecialchars($pl['name']) ?>
            </span>
            <?php if (!empty($pl['is_default'])): ?>
              <span class="pl-default-badge">padrão</span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>

      <nav class="sidebar-nav">
        <?php foreach ($navItems as $item): ?>
          <a href="<?= $item['href'] ?>"
             class="nav-link <?= $activePage === $item['page'] ? 'active' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none"
                 stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
              <?= $item['icon'] ?>
            </svg>
            <?= $item['label'] ?>
          </a>
        <?php endforeach; ?>

        <!-- Auth status in sidebar bottom -->
        <?php if (isLocked()): ?>
        <div style="margin-top:8px;padding:8px 10px;border-top:1px solid var(--border)">
          <?php if (isAuthed()): ?>
            <div style="display:flex;align-items:center;gap:7px;font-size:.72rem;color:var(--text3)">
              <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <span style="flex:1">Sessão activa</span>
              <a href="?logout" style="color:var(--text3);text-decoration:none;font-size:.65rem" title="Terminar sessão">sair</a>
            </div>
          <?php else: ?>
            <div style="display:flex;align-items:center;gap:7px;font-size:.72rem;color:var(--text3)">
              <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="var(--text3)" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              <span>Modo leitura</span>
            </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </nav>
    </aside>
    <?php
}

function pageHead($title = 'SetList') {
    echo '<!DOCTYPE html><html lang="pt-br"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>' . htmlspecialchars($title) . ' · SetList</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="style.css">
</head><body>';
}

/**
 * Renders the lock modal (hidden by default).
 * Call once per page that has write actions.
 * JS: call openLockModal(callback) to show it; on success the callback runs.
 */
function renderLockModal() {
    if (!isLocked()) return; // no password set → nothing to render
    ?>
    <div class="modal-overlay" id="lockModal">
      <div class="modal" style="max-width:360px;text-align:center">
        <div style="color:var(--accent);margin-bottom:12px">
          <svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        </div>
        <div class="modal-title" style="font-size:1.1rem">Confirmar identidade</div>
        <div class="modal-sub">Insere a senha para continuar.</div>
        <div id="lockError" style="display:none" class="alert alert-error" style="margin-bottom:12px"></div>
        <input class="form-input" type="password" id="lockPasswordInput"
               placeholder="••••••••"
               style="text-align:center;letter-spacing:.2em;font-size:1rem;margin-bottom:12px">
        <div style="display:flex;gap:8px;justify-content:center">
          <button class="btn btn-outline" onclick="closeLockModal()">Cancelar</button>
          <button class="btn btn-primary" id="lockSubmitBtn" onclick="submitLock()">Entrar</button>
        </div>
      </div>
    </div>

    <script>
    var _lockCallback = null;

    function openLockModal(callback) {
      _lockCallback = callback || null;
      document.getElementById('lockPasswordInput').value = '';
      document.getElementById('lockError').style.display = 'none';
      document.getElementById('lockModal').classList.add('open');
      setTimeout(function(){ document.getElementById('lockPasswordInput').focus(); }, 80);
    }

    function closeLockModal() {
      document.getElementById('lockModal').classList.remove('open');
      _lockCallback = null;
    }

    document.addEventListener('DOMContentLoaded', function() {
      var inp = document.getElementById('lockPasswordInput');
      if (inp) {
        inp.addEventListener('keydown', function(e) {
          if (e.key === 'Enter') submitLock();
          if (e.key === 'Escape') closeLockModal();
        });
      }
      var overlay = document.getElementById('lockModal');
      if (overlay) overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeLockModal();
      });
    });

    function submitLock() {
      var pwd = document.getElementById('lockPasswordInput').value;
      var btn = document.getElementById('lockSubmitBtn');
      btn.disabled = true; btn.textContent = '…';

      var fd = new FormData();
      fd.append('_action', '_login');
      fd.append('password', pwd);

      fetch(window.location.pathname + window.location.search, { method:'POST', body:fd })
        .then(function(r){ return r.json(); })
        .then(function(res) {
          btn.disabled = false; btn.textContent = 'Entrar';
          if (res.ok) {
            closeLockModal();
            if (_lockCallback) _lockCallback();
          } else {
            var err = document.getElementById('lockError');
            err.textContent = res.error || 'Senha incorreta.';
            err.style.display = 'flex';
            document.getElementById('lockPasswordInput').select();
          }
        })
        .catch(function() {
          btn.disabled = false; btn.textContent = 'Entrar';
        });
    }

    // Global helper: wrap any action with auth check
    // Usage: guardedAction(function(){ doTheThing(); })
    var _isAuthed = <?= isAuthed() ? 'true' : 'false' ?>;
    var _isLocked = <?= isLocked() ? 'true' : 'false' ?>;

    function guardedAction(fn) {
      if (!_isLocked || _isAuthed) { fn(); return; }
      openLockModal(function() { _isAuthed = true; fn(); });
    }
    </script>
    <?php
}

/** Shared cifra field HTML for add/edit forms */
function renderCifraField($cifraUrl = '', $cifraSource = 'cifraclub') {
    if ($cifraUrl === 'N/A') $cifraUrl = '';
    $isCifraclub = ($cifraSource === 'cifraclub' || $cifraSource === 'none' || !$cifraSource);
    $isUG        = ($cifraSource === 'ultimate_guitar');
    ?>
    <div class="form-group">
      <label class="form-label">Fonte da Cifra</label>
      <div style="display:flex;gap:6px;margin-bottom:8px">
        <button type="button" class="src-btn <?= $isCifraclub ? 'active' : '' ?>"
                onclick="setCifraSource('cifraclub')" data-src="cifraclub">Cifra Club</button>
        <button type="button" class="src-btn <?= $isUG ? 'active' : '' ?>"
                onclick="setCifraSource('ultimate_guitar')" data-src="ultimate_guitar">Ultimate Guitar</button>
        <button type="button" class="src-btn <?= (!$isCifraclub && !$isUG) ? 'active' : '' ?>"
                onclick="setCifraSource('other')" data-src="other">Outro / URL</button>
      </div>
      <input type="hidden" name="cifra_source" id="cifraSourceInput"
             value="<?= htmlspecialchars($cifraSource ?: 'cifraclub') ?>">
    </div>
    <div class="form-group">
      <label class="form-label">URL / Caminho da Cifra <span style="color:var(--text3)">(opcional)</span></label>
      <input class="form-input" type="text" id="cifraUrlInput" name="cifra_url"
             placeholder="" value="<?= htmlspecialchars($cifraUrl) ?>">
      <div style="font-size:0.72rem;color:var(--text3);margin-top:5px" id="cifraUrlHint"></div>
    </div>
    <style>
      .src-btn { padding:6px 12px;border-radius:6px;border:1px solid var(--border2);background:transparent;color:var(--text2);font-family:'DM Sans',sans-serif;font-size:.78rem;cursor:pointer;transition:all .15s; }
      .src-btn:hover { border-color:var(--accent);color:var(--accent); }
      .src-btn.active { background:var(--accent-dim);border-color:var(--accent);color:var(--accent); }
    </style>
    <script>
    var cifraHints = {
      cifraclub:       'Caminho: <strong>skank/resposta</strong> ou URL completa do Cifra Club.',
      ultimate_guitar: 'Cole a URL completa do Ultimate Guitar.',
      other:           'Cole qualquer URL de cifra ou tablatura.'
    };
    var cifraPlaceholders = {
      cifraclub:       'skank/resposta  ou  URL completa',
      ultimate_guitar: 'https://tabs.ultimate-guitar.com/...',
      other:           'https://...'
    };
    function setCifraSource(src) {
      document.getElementById('cifraSourceInput').value = src;
      document.getElementById('cifraUrlInput').placeholder = cifraPlaceholders[src] || '';
      document.getElementById('cifraUrlHint').innerHTML  = cifraHints[src] || '';
      document.querySelectorAll('.src-btn').forEach(function(b) {
        b.classList.toggle('active', b.dataset.src === src);
      });
    }
    setCifraSource(document.getElementById('cifraSourceInput').value || 'cifraclub');
    </script>
    <?php
}
