<?php
require '_helpers.php';

// Autoload do Composer (se disponível)
$autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($autoload)) {
    require $autoload;
    if (file_exists(__DIR__ . '/.env')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
        $dotenv->safeLoad();
    }
}

$activePl  = getActivePlaylist();
$playlists = loadPlaylists();
$plId      = $activePl['id'] ?? 'principal';
$plParam   = '?pl=' . urlencode($plId);

$clientId     = $_ENV['CLIENT_ID']     ?? $_ENV['SPOTIPY_CLIENT_ID']     ?? getenv('CLIENT_ID')     ?? '';
$clientSecret = $_ENV['CLIENT_SECRET'] ?? $_ENV['SPOTIPY_CLIENT_SECRET'] ?? getenv('CLIENT_SECRET') ?? '';

$error   = '';
$success = '';
$preview = [];

function getSpotifyToken($clientId, $clientSecret) {
    if (!$clientId || !$clientSecret) return null;
    $ch = curl_init('https://accounts.spotify.com/api/token');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER    => ['Authorization: Basic ' . base64_encode("$clientId:$clientSecret"), 'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS    => 'grant_type=client_credentials'
    ]);
    $res = json_decode(curl_exec($ch), true);
    curl_close($ch);
    return $res['access_token'] ?? null;
}

function fetchSpotifyTracks($token, $playlistId) {
    $songs = [];
    $url   = "https://api.spotify.com/v1/playlists/$playlistId/tracks?limit=100";
    while ($url) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER    => ["Authorization: Bearer $token"],
            CURLOPT_RETURNTRANSFER => true
        ]);
        $data = json_decode(curl_exec($ch), true);
        curl_close($ch);
        if (empty($data['items'])) break;
        foreach ($data['items'] as $item) {
            $t = $item['track'] ?? null;
            if (!$t) continue;
            $songs[] = [
                'title'     => $t['name'],
                'artist'    => $t['artists'][0]['name'] ?? '',
                'cifra_url' => 'N/A'
            ];
        }
        $url = $data['next'] ?? null;
    }
    return $songs;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetPlId = $_POST['target_pl'] ?? $plId;
    $targetPl   = null;
    foreach ($playlists as $pl) { if ($pl['id'] === $targetPlId) { $targetPl = $pl; break; } }
    if (!$targetPl) $targetPl = $activePl;

    $token = getSpotifyToken($clientId, $clientSecret);
    if (!$token) {
        $error = 'Não foi possível autenticar no Spotify. Verifique CLIENT_ID e CLIENT_SECRET no .env';
    } else {
        $songs = fetchSpotifyTracks($token, $targetPl['spotify_id']);
        if (empty($songs)) {
            $error = 'Nenhuma faixa encontrada. Verifique se o ID da playlist está correto e a playlist é pública.';
        } else {
            $mode = $_POST['import_mode'] ?? 'replace';
            if ($mode === 'merge') {
                $existing = loadSongs($targetPl);
                $existingKeys = array_map(fn($s) => strtolower($s['title'] . '|' . $s['artist']), $existing);
                foreach ($songs as $s) {
                    $key = strtolower($s['title'] . '|' . $s['artist']);
                    if (!in_array($key, $existingKeys)) { $existing[] = $s; }
                }
                saveSongs($targetPl, $existing);
                $success = count($existing) . ' músicas no total após merge.';
            } else {
                saveSongs($targetPl, $songs);
                $success = count($songs) . ' músicas importadas para "' . $targetPl['name'] . '".';
            }
        }
    }
}

$hasCredentials = !empty($clientId) && !empty($clientSecret);

pageHead('Importar Spotify');
renderSidebar('import');
?>

<div class="main">
  <div class="topbar">
    <div>
      <div class="topbar-title">Importar do Spotify</div>
      <div class="topbar-sub">Sincronize músicas de uma playlist</div>
    </div>
    <a href="index.php<?= $plParam ?>" class="btn btn-outline">← Voltar</a>
  </div>

  <div class="content fade-up">
    <?php if ($error): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success">
        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
        <?= htmlspecialchars($success) ?>
        — <a href="index.php<?= $plParam ?>" style="color:var(--accent)">Ver músicas →</a>
      </div>
    <?php endif; ?>

    <?php if (!$hasCredentials): ?>
    <!-- No credentials warning -->
    <div style="background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius2);padding:28px 32px;max-width:560px">
      <div style="display:flex;gap:14px;align-items:flex-start;margin-bottom:20px">
        <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#f0b429" stroke-width="1.8" style="flex-shrink:0"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
        <div>
          <div style="font-weight:600;margin-bottom:6px;color:var(--text)">Credenciais do Spotify não configuradas</div>
          <div style="font-size:0.82rem;color:var(--text2);line-height:1.7">
            Para importar, crie um arquivo <code style="font-family:'DM Mono',monospace;background:var(--bg3);padding:1px 5px;border-radius:4px">.env</code> na raiz do projeto com:
          </div>
        </div>
      </div>
      <pre style="background:var(--bg3);border:1px solid var(--border2);border-radius:var(--radius);padding:16px;font-family:'DM Mono',monospace;font-size:0.75rem;color:var(--text2);line-height:1.8;overflow-x:auto">CLIENT_ID=seu_client_id_aqui
CLIENT_SECRET=seu_client_secret_aqui</pre>
      <div style="font-size:0.75rem;color:var(--text3);margin-top:12px">
        Crie um app em <a href="https://developer.spotify.com/dashboard" target="_blank" style="color:var(--accent)">developer.spotify.com/dashboard</a> para obter as credenciais.
      </div>
    </div>
    <?php else: ?>

    <div class="form-card">
      <form action="import.php<?= $plParam ?>" method="POST">
        <div class="form-group">
          <label class="form-label">Importar para qual lista</label>
          <select class="form-input" name="target_pl">
            <?php foreach ($playlists as $pl): ?>
              <option value="<?= htmlspecialchars($pl['id']) ?>"
                      <?= $pl['id'] === $plId ? 'selected' : '' ?>>
                <?= htmlspecialchars($pl['name']) ?>
                <?= !empty($pl['is_default']) ? ' (padrão)' : '' ?>
                — ID: <?= htmlspecialchars($pl['spotify_id']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div style="font-size:0.72rem;color:var(--text3);margin-top:6px">
            As músicas serão buscadas do ID do Spotify configurado em cada lista.
            Para alterar o ID, vá em <a href="playlists.php" style="color:var(--accent)">Listas Spotify</a>.
          </div>
        </div>

        <div class="form-group">
          <label class="form-label">Modo de importação</label>
          <div style="display:flex;flex-direction:column;gap:10px;margin-top:4px">
            <label style="display:flex;gap:12px;align-items:flex-start;cursor:pointer">
              <input type="radio" name="import_mode" value="replace" checked
                     style="margin-top:3px;accent-color:var(--accent)">
              <div>
                <div style="font-size:0.85rem;font-weight:500">Substituir lista</div>
                <div style="font-size:0.75rem;color:var(--text3)">Apaga a lista atual e importa tudo do Spotify</div>
              </div>
            </label>
            <label style="display:flex;gap:12px;align-items:flex-start;cursor:pointer">
              <input type="radio" name="import_mode" value="merge"
                     style="margin-top:3px;accent-color:var(--accent)">
              <div>
                <div style="font-size:0.85rem;font-weight:500">Mesclar (merge)</div>
                <div style="font-size:0.75rem;color:var(--text3)">Adiciona músicas novas sem apagar as existentes</div>
              </div>
            </label>
          </div>
        </div>

        <button type="submit" class="btn btn-primary" style="margin-top:8px">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg>
          Importar do Spotify
        </button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>

</body></html>
