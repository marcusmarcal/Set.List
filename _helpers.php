<?php
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

/**
 * Remove Spotify suffix junk from titles:
 * - (Live), (Remastered 2009), [Ao Vivo], (2009 Mix), etc.
 * - " - Live", " - Remastered", " - 2009 Remaster", etc.
 * Preserves: "Tanto (I Want You)", "A-Ha", normal parentheses with content.
 */
function cleanTitle($title) {
    $keywords = 'live|ao vivo|remaster(?:ed)?(?:\s+\d{4})?|\d{4}[\w\s\-]*mix'
              . '|bonus track|explicit|radio edit|single version|album version'
              . '|deluxe|acoustic|demo|instrumental|extended|intro|outro';
    // Remove: (…) or […] starting with a keyword
    $title = preg_replace('/\s*[\(\[]\s*(?:' . $keywords . ')[^\)\]]*[\)\]]/iu', '', $title);
    // Remove: " - keyword…" to end of string
    $title = preg_replace('/\s+-\s+(?:' . $keywords . ').*/iu', '', $title);
    return trim($title);
}

function formatCifraUrl($url) {
    if (empty($url) || $url === 'N/A') return null;
    if (preg_match('/^https?:\/\//', $url)) return $url;
    return 'https://www.cifraclub.com.br/' . ltrim($url, '/');
}

// ── SIDEBAR ──────────────────────────────────────────────────────
function renderSidebar($activePage = 'index') {
    $playlists  = loadPlaylists();
    $activePl   = getActivePlaylist();
    $activePlId = $activePl['id'] ?? '';
    $plParam    = '?pl=' . urlencode($activePlId);

    $navItems = [
        ['href' => 'index.php'      . $plParam, 'label' => 'Músicas',         'page' => 'index',
         'icon' => '<path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/>'],
        ['href' => 'add.php'        . $plParam, 'label' => 'Adicionar',       'page' => 'add',
         'icon' => '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/>'],
        ['href' => 'import.php'     . $plParam, 'label' => 'Importar Spotify','page' => 'import',
         'icon' => '<polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/>'],
        ['href' => 'playlists.php',              'label' => 'Listas Spotify', 'page' => 'playlists',
         'icon' => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>'],
        ['href' => 'print_songs.php'. $plParam, 'label' => 'Imprimir',        'page' => 'print',
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
