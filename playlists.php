<?php
require '_helpers.php';

// Handle login/logout via checkAuth()
checkAuth();

$playlists = loadPlaylists();
$error = $success = '';

// ── WRITE ACTIONS ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['_action'])) {
    $action = $_POST['action'] ?? '';

    // Reorder (AJAX)
    if ($action === 'reorder') {
        requireAuthOrDie('playlists.php');
        $newOrder = json_decode($_POST['order'] ?? '[]', true);
        if (is_array($newOrder)) {
            $indexed = [];
            foreach ($playlists as $pl) $indexed[$pl['id']] = $pl;
            $sorted = [];
            foreach ($newOrder as $id) {
                if (isset($indexed[$id])) $sorted[] = $indexed[$id];
            }
            foreach ($sorted as $i => &$pl) $pl['is_default'] = ($i === 0);
            unset($pl);
            savePlaylists($sorted);
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    requireAuthOrDie('playlists.php');

    if ($action === 'add') {
        $name      = trim($_POST['name']       ?? '');
        $spotifyId = trim($_POST['spotify_id'] ?? '');
        if ($name && $spotifyId) {
            $slug     = trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name)), '-');
            $existing = array_column($playlists, 'id');
            $base = $slug; $n = 2;
            while (in_array($slug, $existing)) $slug = $base . '-' . $n++;
            $playlists[] = ['id' => $slug, 'name' => $name,
                            'spotify_id' => $spotifyId, 'is_default' => count($playlists) === 0];
            savePlaylists($playlists);
        } else { $error = 'Nome e ID do Spotify são obrigatórios.'; }
    }

    if ($action === 'delete_playlist') {
        $tid       = $_POST['target_id'] ?? '';
        $playlists = array_values(array_filter($playlists, fn($p) => $p['id'] !== $tid));
        if (count($playlists)) $playlists[0]['is_default'] = true;
        savePlaylists($playlists);
    }

    if ($action === 'edit_playlist') {
        $tid       = $_POST['target_id']  ?? '';
        $name      = trim($_POST['name']       ?? '');
        $spotifyId = trim($_POST['spotify_id'] ?? '');
        foreach ($playlists as &$pl) {
            if ($pl['id'] === $tid) {
                if ($name)      $pl['name']       = $name;
                if ($spotifyId) $pl['spotify_id'] = $spotifyId;
            }
        }
        unset($pl);
        savePlaylists($playlists);
    }

    $playlists = loadPlaylists();
    if (!$error) { header('Location: playlists.php?ok=1'); exit; }
}

if (isset($_GET['ok']))  $success = 'Operação realizada com sucesso.';
if (isset($_GET['err'])) $error   = urldecode($_GET['err']);

$authed = isAuthed();

pageHead('Listas Spotify');
renderSidebar('playlists');
?>
<div class="sidebar-backdrop" id="backdrop"></div>
<link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
<style>
.pl-drag-handle {
  cursor:grab;color:var(--text3);padding:0 4px;
  opacity:.35;transition:opacity .15s;flex-shrink:0;
  display:flex;align-items:center;
}
.pl-card:hover .pl-drag-handle{opacity:1;}
.pl-drag-handle:active{cursor:grabbing;}
.ui-sortable-helper{
  background:var(--bg3)!important;
  box-shadow:0 8px 28px rgba(0,0,0,.5)!important;
  border-radius:var(--radius2)!important;
  opacity:.96!important; list-style:none!important;
}
.ui-sortable-placeholder{
  visibility:visible!important;
  background:var(--accent-dim)!important;
  border:1px dashed var(--accent-glow)!important;
  border-radius:var(--radius2)!important;
  height:70px!important;
}
.default-pill{
  font-family:'DM Mono',monospace;font-size:.5rem;letter-spacing:.1em;text-transform:uppercase;
  background:rgba(29,185,84,.12);color:var(--accent);
  border:1px solid rgba(29,185,84,.25);padding:1px 6px;border-radius:4px;flex-shrink:0;
}
.copy-toast{
  position:fixed;bottom:24px;right:24px;
  background:var(--accent);color:#000;
  font-family:'DM Mono',monospace;font-size:.7rem;letter-spacing:.08em;
  padding:10px 18px;border-radius:8px;
  transform:translateY(60px);opacity:0;
  transition:all .25s cubic-bezier(.4,0,.2,1);
  z-index:300;pointer-events:none;
}
.copy-toast.show{transform:translateY(0);opacity:1;}
.read-only-notice{
  display:flex;align-items:center;gap:8px;padding:9px 14px;margin-bottom:14px;
  background:rgba(29,185,84,.07);border:1px solid rgba(29,185,84,.18);
  border-radius:var(--radius);font-size:.78rem;color:var(--text2);
}
.read-only-notice svg{color:var(--accent);flex-shrink:0;}
</style>

<div class="main">
  <div class="topbar">
    <div style="display:flex;align-items:center;gap:10px">
      <button class="hamburger" id="menuBtn">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div>
        <div class="topbar-title">Listas do Spotify</div>
        <div class="topbar-sub"><?= count($playlists) ?> lista<?= count($playlists) !== 1 ? 's' : '' ?></div>
      </div>
    </div>
    <button class="btn btn-primary" id="addBtn">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      <span class="btn-label">Nova Lista</span>
    </button>
  </div>

  <div class="content fade-up">
    <?php if ($error):   ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>

    <?php if (!$authed && isLocked()): ?>
    <div class="read-only-notice">
      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      Modo leitura — clica em qualquer acção para autenticar e editar.
    </div>
    <?php endif; ?>

    <!-- Info hint -->
    <div style="background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius2);padding:13px 17px;margin-bottom:16px;display:flex;gap:10px;align-items:flex-start">
      <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="1.8" style="flex-shrink:0;margin-top:2px"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <div style="font-size:.75rem;color:var(--text2);line-height:1.6">
        <strong style="color:var(--text)">Arrastar para reordenar.</strong> A primeira lista é sempre a padrão.
        &nbsp;·&nbsp; ID Spotify: ··· → Partilhar → Copiar link →
        <code style="font-family:'DM Mono',monospace;font-size:.65rem;background:var(--bg3);padding:1px 5px;border-radius:3px;color:var(--accent)">open.spotify.com/playlist/<strong>ID</strong></code>
      </div>
    </div>

    <!-- Playlist cards -->
    <ul id="plSortable" style="list-style:none;display:flex;flex-direction:column;gap:10px;padding:0;margin:0">
      <?php foreach ($playlists as $idx => $pl):
        $plSongs   = loadSongs($pl);
        $songCount = count($plSongs);
        $totalMs   = array_sum(array_column($plSongs, 'duration_ms'));
        $dur = '';
        if ($totalMs) {
            $s = intdiv($totalMs,1000); $h = intdiv($s,3600); $s -= $h*3600; $m = intdiv($s,60);
            $dur = $h ? sprintf('%dh %02dm',$h,$m) : sprintf('%dm',$m);
        }
        $isDefault = ($idx === 0);
      ?>
      <li class="pl-card" data-id="<?= htmlspecialchars($pl['id']) ?>"
          style="background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius2);padding:13px 16px">
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <span class="pl-drag-handle" title="Arrastar para reordenar">
            <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="5" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="19" r="1"/></svg>
          </span>
          <div style="width:34px;height:34px;background:var(--accent-dim);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="1.8"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
          </div>
          <div style="flex:1;min-width:0">
            <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
              <span class="pl-name" style="font-weight:500;font-size:.86rem"><?= htmlspecialchars($pl['name']) ?></span>
              <?php if ($isDefault): ?><span class="default-pill">padrão</span><?php endif; ?>
            </div>
            <div style="font-family:'DM Mono',monospace;font-size:.58rem;color:var(--text3);margin-top:1px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
              <?= htmlspecialchars($pl['spotify_id']) ?>
              <?php if ($songCount): ?> · <?= $songCount ?> músicas<?= $dur ? ' · '.$dur : '' ?><?php endif; ?>
            </div>
          </div>
          <div style="display:flex;gap:5px;align-items:center;flex-wrap:wrap;flex-shrink:0">
            <button class="btn btn-outline copy-list-btn"
                    style="font-size:.72rem;padding:5px 10px"
                    data-pl-id="<?= htmlspecialchars($pl['id']) ?>"
                    data-pl-name="<?= htmlspecialchars($pl['name'], ENT_QUOTES) ?>"
                    title="Copiar lista como texto">
              <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
              Copiar
            </button>
            <a href="index.php?pl=<?= urlencode($pl['id']) ?>" class="btn btn-outline" style="font-size:.72rem;padding:5px 10px">Músicas</a>
            <a href="import.php?pl=<?= urlencode($pl['id']) ?>" class="btn btn-outline" style="font-size:.72rem;padding:5px 10px">Importar</a>
            <button class="btn btn-outline edit-pl-btn" style="font-size:.72rem;padding:5px 10px"
                    data-pl="<?= htmlspecialchars(json_encode($pl), ENT_QUOTES) ?>">Editar</button>
            <?php if (count($playlists) > 1): ?>
            <button class="btn btn-danger delete-pl-btn" style="padding:5px 9px"
                    data-id="<?= htmlspecialchars($pl['id']) ?>"
                    data-name="<?= htmlspecialchars($pl['name'], ENT_QUOTES) ?>" title="Remover">
              <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
            </button>
            <?php endif; ?>
          </div>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>

    <!-- Song data for JS copy -->
    <script>
    var allPlaylistSongs = <?php
      $jsData = [];
      foreach ($playlists as $pl) {
          $s = loadSongs($pl);
          $jsData[$pl['id']] = [
              'name'  => $pl['name'],
              'songs' => array_map(fn($x) => ['title' => $x['title'], 'artist' => $x['artist']], $s)
          ];
      }
      echo json_encode($jsData, JSON_UNESCAPED_UNICODE);
    ?>;
    </script>
  </div>
</div>

<!-- ADD MODAL -->
<div class="modal-overlay" id="addModal">
  <div class="modal">
    <div class="modal-title">Nova Lista</div>
    <div class="modal-sub">Adicione uma playlist do Spotify.</div>
    <form id="addForm" action="playlists.php" method="POST">
      <input type="hidden" name="action" value="add">
      <div class="form-group">
        <label class="form-label">Nome da Lista</label>
        <input class="form-input" type="text" name="name" placeholder="Ex: Repertório Acústico" required autofocus>
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
    <form id="editForm" action="playlists.php" method="POST">
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

<!-- DELETE hidden form -->
<form id="deletePlForm" action="playlists.php" method="POST" style="display:none">
  <input type="hidden" name="action" value="delete_playlist">
  <input type="hidden" name="target_id" id="deletePlTargetId">
</form>

<!-- COPY TOAST -->
<div class="copy-toast" id="copyToast">Copiado ✓</div>

<?php renderLockModal(); ?>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
<script>
$(function() {
  // ── Drag-to-reorder ──
  $('#plSortable').sortable({
    handle: '.pl-drag-handle',
    placeholder: 'ui-sortable-placeholder',
    start: function(e, ui) {
      if (_isLocked && !_isAuthed) {
        $('#plSortable').sortable('cancel');
        openLockModal(function() { _isAuthed = true; });
        return false;
      }
    },
    update: function() {
      var ids = $('#plSortable .pl-card').map(function(){ return $(this).data('id'); }).get();
      $.post('playlists.php', { action: 'reorder', order: JSON.stringify(ids) }, function(res) {
        if (res.ok) {
          // Update default-pill to first card only
          $('#plSortable .pl-card').each(function(i) {
            var pill = $(this).find('.default-pill');
            if (i === 0) {
              if (!pill.length)
                $(this).find('.pl-name').after(' <span class="default-pill">padrão</span>');
            } else {
              pill.remove();
            }
          });
        }
      }, 'json');
    }
  });

  // ── Add button ──
  $('#addBtn').on('click', function() {
    guardedAction(function() { openModal('addModal'); });
  });

  // ── Edit button ──
  $(document).on('click', '.edit-pl-btn', function() {
    var pl = $(this).data('pl');
    if (typeof pl === 'string') pl = JSON.parse(pl);
    guardedAction(function() {
      document.getElementById('editTargetId').value  = pl.id;
      document.getElementById('editName').value       = pl.name;
      document.getElementById('editSpotifyId').value  = pl.spotify_id;
      document.getElementById('editModalSub').textContent = 'Editando: ' + pl.name;
      openModal('editModal');
    });
  });

  // ── Delete button ──
  $(document).on('click', '.delete-pl-btn', function() {
    var id   = $(this).data('id');
    var name = $(this).data('name');
    guardedAction(function() {
      if (!confirm('Remover "' + name + '"? Os ficheiros de músicas não são apagados.')) return;
      document.getElementById('deletePlTargetId').value = id;
      document.getElementById('deletePlForm').submit();
    });
  });

  // ── Copy list ──
  $(document).on('click', '.copy-list-btn', function() {
    var plId   = $(this).data('pl-id');
    var plData = allPlaylistSongs[plId];
    if (!plData) return;
    var songs = plData.songs;
    var name  = plData.name;
    var date  = new Date().toLocaleDateString('pt-BR', {day:'2-digit',month:'2-digit',year:'numeric'});
    var lines = [
      '═══════════════════════════════════',
      '  ' + name.toUpperCase(),
      '  ' + songs.length + ' músicas · ' + date,
      '═══════════════════════════════════',
      ''
    ];
    songs.forEach(function(s, i) {
      lines.push(String(i+1).padStart(2,'0') + '.  ' + s.title + '  —  ' + s.artist);
    });
    lines.push(''); lines.push('───────────────────────────────────');
    var text = lines.join('\n');
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(showToast);
    } else {
      var ta = document.createElement('textarea');
      ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select();
      document.execCommand('copy'); document.body.removeChild(ta);
      showToast();
    }
  });

  // ── Guard form submits ──
  $('#addForm,#editForm').on('submit', function(e) {
    if (!_isLocked || _isAuthed) return;
    e.preventDefault();
    var form = this;
    openLockModal(function() { _isAuthed = true; form.submit(); });
  });

  // ── Close modals on backdrop ──
  $('.modal-overlay').on('click', function(e) {
    if (e.target === this) $(this).removeClass('open');
  });

  // ── Mobile sidebar ──
  $('#menuBtn').on('click', function() { $('#sidebar').toggleClass('open'); $('#backdrop').toggleClass('open'); });
  $('#backdrop').on('click', function() { $('#sidebar,#backdrop').removeClass('open'); });
});

function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function showToast() {
  var t = document.getElementById('copyToast');
  t.classList.add('show');
  setTimeout(function(){ t.classList.remove('show'); }, 2200);
}
</script>
</body></html>
