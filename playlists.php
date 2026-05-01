<?php
require '_helpers.php';

$playlists = loadPlaylists();
$error     = '';
$success   = '';

// ── ADD PLAYLIST ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action === 'add') {
        $name       = trim($_POST['name'] ?? '');
        $spotifyId  = trim($_POST['spotify_id'] ?? '');
        if ($name && $spotifyId) {
            // Generate slug id
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
            $slug = trim($slug, '-');
            // Ensure uniqueness
            $existing = array_column($playlists, 'id');
            $base = $slug; $n = 2;
            while (in_array($slug, $existing)) { $slug = $base . '-' . $n++; }

            $playlists[] = [
                'id'         => $slug,
                'name'       => $name,
                'spotify_id' => $spotifyId,
                'is_default' => count($playlists) === 0
            ];
            savePlaylists($playlists);
            $success = "Lista \"$name\" criada com sucesso.";
        } else {
            $error = 'Nome e ID do Spotify são obrigatórios.';
        }
    }

    if ($action === 'set_default') {
        $targetId = $_POST['target_id'] ?? '';
        foreach ($playlists as &$pl) {
            $pl['is_default'] = ($pl['id'] === $targetId);
        }
        unset($pl);
        savePlaylists($playlists);
        $success = 'Lista padrão atualizada.';
    }

    if ($action === 'delete_playlist') {
        $targetId = $_POST['target_id'] ?? '';
        $playlists = array_values(array_filter($playlists, fn($p) => $p['id'] !== $targetId));
        // Ensure at least one default
        $hasDefault = !empty(array_filter($playlists, fn($p) => !empty($p['is_default'])));
        if (!$hasDefault && count($playlists) > 0) $playlists[0]['is_default'] = true;
        savePlaylists($playlists);
        $success = 'Lista removida.';
    }

    if ($action === 'edit_playlist') {
        $targetId  = $_POST['target_id'] ?? '';
        $name      = trim($_POST['name'] ?? '');
        $spotifyId = trim($_POST['spotify_id'] ?? '');
        foreach ($playlists as &$pl) {
            if ($pl['id'] === $targetId) {
                if ($name)      $pl['name']       = $name;
                if ($spotifyId) $pl['spotify_id'] = $spotifyId;
            }
        }
        unset($pl);
        savePlaylists($playlists);
        $success = 'Lista atualizada.';
    }

    // Reload after modification
    $playlists = loadPlaylists();
    header('Location: playlists.php' . ($success ? '?ok=1' : ($error ? '?err=' . urlencode($error) : '')));
    exit;
}

// Flash messages from redirect
if (isset($_GET['ok']))  $success = 'Operação realizada com sucesso.';
if (isset($_GET['err'])) $error   = urldecode($_GET['err']);

pageHead('Listas Spotify');
renderSidebar('playlists');
?>

<div class="main">
  <div class="topbar">
    <div>
      <div class="topbar-title">Listas do Spotify</div>
      <div class="topbar-sub"><?= count($playlists) ?> lista<?= count($playlists) !== 1 ? 's' : '' ?> cadastrada<?= count($playlists) !== 1 ? 's' : '' ?></div>
    </div>
    <button class="btn btn-primary" onclick="openModal('addModal')">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Nova Lista
    </button>
  </div>

  <div class="content fade-up">
    <?php if ($error): ?>
      <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert alert-success"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>

    <!-- Hint box -->
    <div style="background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius2);padding:20px 24px;margin-bottom:24px;display:flex;gap:16px;align-items:flex-start">
      <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="1.8" style="flex-shrink:0;margin-top:2px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <div>
        <div style="font-size:0.82rem;color:var(--text);margin-bottom:4px">Como encontrar o ID de uma playlist do Spotify</div>
        <div style="font-size:0.78rem;color:var(--text3);line-height:1.6">
          Abra a playlist no Spotify → clique em <strong style="color:var(--text2)">···</strong> → <em>Compartilhar</em> → <em>Copiar link da playlist</em>.<br>
          O ID é a parte final da URL: <code style="font-family:'DM Mono',monospace;font-size:0.72rem;background:var(--bg3);padding:1px 6px;border-radius:4px;color:var(--accent)">https://open.spotify.com/playlist/<strong>4pcomesNQA6DPXj1HFpOjf</strong></code>
        </div>
      </div>
    </div>

    <!-- Playlist cards -->
    <div style="display:flex;flex-direction:column;gap:12px">
      <?php foreach ($playlists as $pl): ?>
      <div style="background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius2);padding:20px 24px;display:flex;align-items:center;gap:16px">
        <div style="width:42px;height:42px;background:var(--accent-dim);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="1.8"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
        </div>
        <div style="flex:1;min-width:0">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:2px">
            <span style="font-weight:500;font-size:0.9rem"><?= htmlspecialchars($pl['name']) ?></span>
            <?php if (!empty($pl['is_default'])): ?>
              <span class="badge badge-green">padrão</span>
            <?php endif; ?>
          </div>
          <div style="font-family:'DM Mono',monospace;font-size:0.65rem;color:var(--text3)">
            ID: <?= htmlspecialchars($pl['spotify_id']) ?>
          </div>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-shrink:0">
          <a href="index.php?pl=<?= urlencode($pl['id']) ?>" class="btn btn-outline" style="font-size:0.78rem;padding:7px 14px">Ver músicas</a>
          <a href="import.php?pl=<?= urlencode($pl['id']) ?>" class="btn btn-outline" style="font-size:0.78rem;padding:7px 14px">Importar</a>
          <?php if (empty($pl['is_default'])): ?>
          <form action="playlists.php" method="POST" style="display:inline">
            <input type="hidden" name="action" value="set_default">
            <input type="hidden" name="target_id" value="<?= htmlspecialchars($pl['id']) ?>">
            <button class="btn btn-ghost" style="font-size:0.78rem;padding:7px 14px" title="Tornar padrão">★ Padrão</button>
          </form>
          <?php endif; ?>
          <button class="btn btn-ghost" style="font-size:0.78rem;padding:7px 14px"
                  onclick="openEditModal('<?= htmlspecialchars(json_encode($pl), ENT_QUOTES) ?>')">Editar</button>
          <?php if (empty($pl['is_default']) && count($playlists) > 1): ?>
          <form action="playlists.php" method="POST" style="display:inline"
                onsubmit="return confirm('Remover esta lista? As músicas salvas NÃO serão apagadas do disco.')">
            <input type="hidden" name="action" value="delete_playlist">
            <input type="hidden" name="target_id" value="<?= htmlspecialchars($pl['id']) ?>">
            <button class="btn btn-danger" title="Remover lista">
              <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
            </button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ADD MODAL -->
<div class="modal-overlay" id="addModal">
  <div class="modal">
    <div class="modal-title">Nova Lista</div>
    <div class="modal-sub">Adicione uma playlist do Spotify para gerenciar.</div>
    <form action="playlists.php" method="POST">
      <input type="hidden" name="action" value="add">
      <div class="form-group">
        <label class="form-label">Nome da Lista</label>
        <input class="form-input" type="text" name="name" placeholder="Ex: Repertório Acústico" required>
      </div>
      <div class="form-group">
        <label class="form-label">ID da Playlist Spotify</label>
        <input class="form-input" type="text" name="spotify_id" placeholder="4pcomesNQA6DPXj1HFpOjf" required>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('addModal')">Cancelar</button>
        <button type="submit" class="btn btn-primary">Criar Lista</button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <div class="modal-title">Editar Lista</div>
    <div class="modal-sub" id="editModalSub"></div>
    <form action="playlists.php" method="POST">
      <input type="hidden" name="action" value="edit_playlist">
      <input type="hidden" name="target_id" id="editTargetId">
      <div class="form-group">
        <label class="form-label">Nome da Lista</label>
        <input class="form-input" type="text" name="name" id="editName">
      </div>
      <div class="form-group">
        <label class="form-label">ID da Playlist Spotify</label>
        <input class="form-input" type="text" name="spotify_id" id="editSpotifyId">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline" onclick="closeModal('editModal')">Cancelar</button>
        <button type="submit" class="btn btn-primary">Salvar</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(id) {
  document.getElementById(id).classList.add('open');
}
function closeModal(id) {
  document.getElementById(id).classList.remove('open');
}
function openEditModal(plJson) {
  var pl = JSON.parse(plJson);
  document.getElementById('editTargetId').value  = pl.id;
  document.getElementById('editName').value       = pl.name;
  document.getElementById('editSpotifyId').value  = pl.spotify_id;
  document.getElementById('editModalSub').textContent = 'Editando: ' + pl.name;
  openModal('editModal');
}
// Close modal on overlay click
document.querySelectorAll('.modal-overlay').forEach(function(el) {
  el.addEventListener('click', function(e) {
    if (e.target === el) el.classList.remove('open');
  });
});
</script>
</body></html>
