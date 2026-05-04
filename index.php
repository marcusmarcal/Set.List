<?php
require '_helpers.php';

// Handle login POST before anything else
checkAuth();

$activePl  = getActivePlaylist();
$playlists = loadPlaylists();
$plId      = $activePl['id'] ?? 'principal';
$plParam   = '?pl=' . urlencode($plId);

// ── AJAX: reorder (write — requires auth) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order'])) {
    requireAuthOrDie('index.php');
    $songs    = loadSongs($activePl);
    $newOrder = json_decode($_POST['order'], true);
    if (is_array($newOrder)) {
        $sorted = [];
        foreach ($newOrder as $idx) {
            if (isset($songs[(int)$idx])) $sorted[] = $songs[(int)$idx];
        }
        saveSongs($activePl, $sorted);
    }
    exit;
}

// ── AJAX: inline edit (write — requires auth) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_edit'])) {
    requireAuthOrDie('index.php');
    header('Content-Type: application/json');
    $songs       = loadSongs($activePl);
    $index       = (int)($_POST['index']       ?? -1);
    $title       = trim($_POST['title']        ?? '');
    $artist      = trim($_POST['artist']       ?? '');
    $cifraUrl    = trim($_POST['cifra_url']    ?? '');
    $cifraSource = trim($_POST['cifra_source'] ?? 'cifraclub');
    if ($index >= 0 && $index < count($songs) && $title && $artist) {
        $songs[$index]['title']        = $title;
        $songs[$index]['artist']       = $artist;
        $songs[$index]['cifra_url']    = $cifraUrl ?: 'N/A';
        $songs[$index]['cifra_source'] = $cifraSource;
        saveSongs($activePl, $songs);
        $displayUrl = cifraDisplayUrl($songs[$index]);
        $srcLabel   = cifraSourceLabel($songs[$index]);
        echo json_encode(['ok' => true, 'title' => $title, 'artist' => $artist,
                          'cifra_url' => $displayUrl, 'cifra_source' => $cifraSource,
                          'cifra_label' => $srcLabel]);
    } else {
        echo json_encode(['ok' => false]);
    }
    exit;
}

$songs     = loadSongs($activePl);
$sortCol   = $_GET['sort']  ?? '';
$sortOrder = $_GET['order'] ?? 'asc';
if ($sortCol) $songs = sortSongs($songs, $sortCol, $sortOrder);

$totalSongs  = count($songs);
$artistCount = count(array_unique(array_column($songs, 'artist')));
$withCifra   = count(array_filter($songs, fn($s) => !empty($s['cifra_url']) && $s['cifra_url'] !== 'N/A'));

$totalMs = array_sum(array_column($songs, 'duration_ms'));
function fmtDuration($ms) {
    if (!$ms) return null;
    $s = intdiv($ms, 1000); $h = intdiv($s, 3600); $s -= $h*3600; $m = intdiv($s,60);
    return $h ? sprintf('%dh %02dm',$h,$m) : sprintf('%dm %02ds',$m,$s);
}
$durationStr = fmtDuration($totalMs);
$authed      = isAuthed();

pageHead('Músicas · ' . ($activePl['name'] ?? 'SetList'));
?>
<link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
<style>
  .ui-sortable-helper{background:var(--bg3)!important;box-shadow:0 8px 28px rgba(0,0,0,.5);opacity:.95;}
  .ui-sortable-placeholder{visibility:visible!important;background:var(--accent-dim)!important;border:1px dashed var(--accent-glow)!important;height:34px!important;}
  /* lock overlay on table when not authed */
  .read-only-notice{display:flex;align-items:center;gap:8px;padding:9px 14px;margin-bottom:12px;background:rgba(29,185,84,.07);border:1px solid rgba(29,185,84,.18);border-radius:var(--radius);font-size:.78rem;color:var(--text2);}
  .read-only-notice svg{color:var(--accent);flex-shrink:0;}
</style>

<?php renderSidebar('index'); ?>
<div class="sidebar-backdrop" id="backdrop"></div>

<div class="main">
  <div class="topbar">
    <div style="display:flex;align-items:center;gap:10px">
      <button class="hamburger" id="menuBtn">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div>
        <div class="topbar-title"><?= htmlspecialchars($activePl['name'] ?? 'SetList') ?></div>
        <div class="topbar-sub">
          <?= $totalSongs ?> músicas<?= $durationStr ? ' · '.$durationStr : '' ?>
          <?php if (!empty($activePl['is_default'])): ?> · <span style="color:var(--accent)">✦ padrão</span><?php endif; ?>
        </div>
      </div>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <span class="saving-indicator" id="savingInd">Salvo ✓</span>
      <!-- Add button — guarded -->
      <button class="btn btn-primary" id="addBtn">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        <span class="btn-label">Adicionar</span>
      </button>
    </div>
  </div>

  <div class="content fade-up">
    <?php if (!$authed && isLocked()): ?>
    <div class="read-only-notice">
      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      Modo leitura — clica em qualquer acção para autenticar e editar.
    </div>
    <?php endif; ?>

    <div class="stats-row">
      <div class="stat-card"><div class="stat-num"><?= str_pad($totalSongs,2,'0',STR_PAD_LEFT) ?></div><div class="stat-label">Músicas</div></div>
      <div class="stat-card"><div class="stat-num"><?= str_pad($artistCount,2,'0',STR_PAD_LEFT) ?></div><div class="stat-label">Artistas</div></div>
      <div class="stat-card"><div class="stat-num"><?= str_pad($withCifra,2,'0',STR_PAD_LEFT) ?></div><div class="stat-label">Com cifra</div></div>
      <?php if ($durationStr): ?>
      <div class="stat-card"><div class="stat-num" style="font-size:1.3rem"><?= $durationStr ?></div><div class="stat-label">Duração</div></div>
      <?php endif; ?>
    </div>

    <div class="search-row">
      <div class="search-wrap">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" class="search-input" id="searchInput" placeholder="Buscar…">
      </div>
      <a href="index.php<?= $plParam ?>&sort=title&order=<?= ($sortCol==='title'&&$sortOrder==='asc')?'desc':'asc' ?>" class="btn btn-outline">A→Z Título</a>
      <a href="index.php<?= $plParam ?>&sort=artist&order=<?= ($sortCol==='artist'&&$sortOrder==='asc')?'desc':'asc' ?>" class="btn btn-outline">A→Z Artista</a>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width:36px"></th>
            <th class="td-num">#</th>
            <th>Título</th>
            <th>Artista</th>
            <th>Cifra</th>
            <th class="td-actions">Ações</th>
          </tr>
        </thead>
        <tbody id="sortable">
          <?php foreach ($songs as $index => $song):
            $cifraUrl   = cifraDisplayUrl($song);
            $cifraLabel = cifraSourceLabel($song);
            $cifraRaw   = $song['cifra_url']    ?? '';
            $cifraSrc   = $song['cifra_source'] ?? detectCifraSource($cifraRaw);
          ?>
          <tr data-index="<?= $index ?>"
              data-title="<?= htmlspecialchars($song['title'],  ENT_QUOTES) ?>"
              data-artist="<?= htmlspecialchars($song['artist'], ENT_QUOTES) ?>"
              data-cifra-url="<?= htmlspecialchars($cifraRaw,   ENT_QUOTES) ?>"
              data-cifra-source="<?= htmlspecialchars($cifraSrc, ENT_QUOTES) ?>"
              class="song-row">
            <td style="width:36px;padding-right:0">
              <span class="drag-handle" title="Arrastar">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="5" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="19" r="1"/></svg>
              </span>
            </td>
            <td class="td-num"><?= str_pad($index+1,2,'0',STR_PAD_LEFT) ?></td>
            <td class="td-title"><?= htmlspecialchars($song['title']) ?></td>
            <td class="td-artist"><?= htmlspecialchars($song['artist']) ?></td>
            <td class="td-cifra">
              <?php if ($cifraUrl): ?>
                <a href="<?= htmlspecialchars($cifraUrl) ?>" target="_blank" class="badge badge-green cifra-badge" style="text-decoration:none"><?= $cifraLabel ?></a>
              <?php else: ?>
                <span class="badge badge-gray cifra-badge">—</span>
              <?php endif; ?>
            </td>
            <td class="td-actions">
              <div class="actions-wrap">
                <button class="btn btn-ghost edit-btn" data-index="<?= $index ?>" title="Editar">
                  <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  <span>Editar</span>
                </button>
                <button class="btn btn-danger delete-btn" data-index="<?= $index ?>" title="Excluir">
                  <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal-overlay" id="editModal">
  <div class="modal" style="max-width:500px">
    <div class="modal-title">Editar Música</div>
    <div class="modal-sub" id="editModalSub"></div>
    <div class="form-group">
      <label class="form-label">Título</label>
      <input class="form-input" type="text" id="editTitle">
    </div>
    <div class="form-group">
      <label class="form-label">Artista</label>
      <input class="form-input" type="text" id="editArtist">
    </div>
    <div class="form-group">
      <label class="form-label">Fonte da Cifra</label>
      <div style="display:flex;gap:6px;margin-bottom:8px">
        <button type="button" class="src-btn" onclick="setEditCifraSource('cifraclub')" data-src="cifraclub">Cifra Club</button>
        <button type="button" class="src-btn" onclick="setEditCifraSource('ultimate_guitar')" data-src="ultimate_guitar">Ultimate Guitar</button>
        <button type="button" class="src-btn" onclick="setEditCifraSource('other')" data-src="other">Outro / URL</button>
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">URL / Caminho <span style="color:var(--text3)">(opcional)</span></label>
      <input class="form-input" type="text" id="editCifraUrl">
      <div style="font-size:.72rem;color:var(--text3);margin-top:5px" id="editCifraHint"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeEditModal()">Cancelar</button>
      <button class="btn btn-primary" id="editSaveBtn">Salvar</button>
    </div>
  </div>
</div>

<!-- DELETE hidden form -->
<form id="deleteForm" action="delete.php" method="POST" style="display:none">
  <input type="hidden" name="index" id="deleteIndex">
  <input type="hidden" name="pl" value="<?= htmlspecialchars($plId) ?>">
</form>

<?php renderLockModal(); ?>

<style>
.src-btn{padding:6px 12px;border-radius:6px;border:1px solid var(--border2);background:transparent;color:var(--text2);font-family:'DM Sans',sans-serif;font-size:.78rem;cursor:pointer;transition:all .15s;}
.src-btn:hover{border-color:var(--accent);color:var(--accent);}
.src-btn.active{background:var(--accent-dim);border-color:var(--accent);color:var(--accent);}
</style>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
<script>
var plParam = '<?= addslashes($plParam) ?>';
var currentEditIndex = -1, currentEditRow = null, currentCifraSource = 'cifraclub';

var cifraHints = {
  cifraclub:       'Caminho: <strong>skank/resposta</strong> ou URL completa do Cifra Club.',
  ultimate_guitar: 'Cole a URL completa do Ultimate Guitar.',
  other:           'Cole qualquer URL de cifra.'
};
var cifraPlaceholders = {
  cifraclub:       'skank/resposta  ou  URL completa',
  ultimate_guitar: 'https://tabs.ultimate-guitar.com/...',
  other:           'https://...'
};

function setEditCifraSource(src) {
  currentCifraSource = src;
  $('#editCifraUrl').attr('placeholder', cifraPlaceholders[src] || '');
  $('#editCifraHint').html(cifraHints[src] || '');
  $('#editModal .src-btn').each(function() {
    $(this).toggleClass('active', $(this).data('src') === src);
  });
}

$(function() {
  // ── Drag reorder — guarded ──
  $("#sortable").sortable({
    handle: '.drag-handle',
    placeholder: 'ui-sortable-placeholder',
    start: function(e, ui) {
      // If not authed, cancel sort and open lock
      if (_isLocked && !_isAuthed) {
        $("#sortable").sortable('cancel');
        openLockModal(function() { _isAuthed = true; });
        return false;
      }
    },
    update: function() {
      var ids = $("#sortable tr.song-row").map(function(){ return $(this).data('index'); }).get();
      showSaving('Salvando…');
      $.post("index.php" + plParam, { order: JSON.stringify(ids) }, function() {
        showSaving('Salvo ✓'); setTimeout(hideSaving, 1600);
        $('#sortable tr.song-row:visible').each(function(i){
          $(this).find('.td-num').text(String(i+1).padStart(2,'0'));
        });
      });
    }
  });

  // ── Search ──
  $('#searchInput').on('input', function() {
    var q = $(this).val().toLowerCase();
    $('.song-row').each(function() {
      var t = $(this).find('.td-title').text().toLowerCase();
      var a = $(this).find('.td-artist').text().toLowerCase();
      $(this).toggle(!q || t.includes(q) || a.includes(q));
    });
  });

  // ── Add button — navigate after auth ──
  $('#addBtn').on('click', function() {
    guardedAction(function() {
      window.location.href = 'add.php' + plParam;
    });
  });

  // ── Edit button — guarded ──
  $(document).on('click', '.edit-btn', function() {
    var row = $(this).closest('tr');
    var idx = parseInt(row.data('index'));
    guardedAction(function() {
      currentEditIndex = idx;
      currentEditRow   = row;
      $('#editTitle').val(row.data('title'));
      $('#editArtist').val(row.data('artist'));
      var src = row.data('cifra-source') || 'cifraclub';
      var url = row.data('cifra-url') || '';
      if (url === 'N/A') url = '';
      $('#editCifraUrl').val(url);
      setEditCifraSource(src);
      $('#editModalSub').text('#' + row.find('.td-num').text());
      $('#editModal').addClass('open');
      setTimeout(function(){ $('#editTitle').focus(); }, 80);
    });
  });

  // ── Delete button — guarded ──
  $(document).on('click', '.delete-btn', function() {
    var idx = $(this).data('index');
    guardedAction(function() {
      if (!confirm('Excluir esta música?')) return;
      $('#deleteIndex').val(idx);
      $('#deleteForm').submit();
    });
  });

  // ── Edit modal ──
  $('#editSaveBtn').on('click', saveEdit);
  $('#editModal').on('keydown', function(e) {
    if (e.key === 'Enter') saveEdit();
    if (e.key === 'Escape') closeEditModal();
  });
  $('#editModal').on('click', function(e) { if (e.target === this) closeEditModal(); });

  // ── Mobile sidebar ──
  $('#menuBtn').on('click', function() { $('#sidebar').toggleClass('open'); $('#backdrop').toggleClass('open'); });
  $('#backdrop').on('click', function() { $('#sidebar,#backdrop').removeClass('open'); });
});

function saveEdit() {
  var title  = $('#editTitle').val().trim();
  var artist = $('#editArtist').val().trim();
  if (!title || !artist) { $('#editTitle').focus(); return; }
  $('#editSaveBtn').prop('disabled', true).text('Salvando…');
  $.post("index.php" + plParam, {
    ajax_edit: 1, index: currentEditIndex,
    title: title, artist: artist,
    cifra_url: $('#editCifraUrl').val().trim(),
    cifra_source: currentCifraSource
  }, function(res) {
    $('#editSaveBtn').prop('disabled', false).text('Salvar');
    if (res.ok) {
      currentEditRow.find('.td-title').text(res.title);
      currentEditRow.find('.td-artist').text(res.artist);
      currentEditRow.data('title', res.title).data('artist', res.artist)
        .data('cifra-url', res.cifra_url || '').data('cifra-source', res.cifra_source || 'cifraclub');
      var cell = currentEditRow.find('.td-cifra');
      if (res.cifra_url) {
        cell.html('<a href="'+res.cifra_url+'" target="_blank" class="badge badge-green cifra-badge" style="text-decoration:none">'+res.cifra_label+'</a>');
      } else {
        cell.html('<span class="badge badge-gray cifra-badge">—</span>');
      }
      currentEditRow.addClass('just-edited');
      setTimeout(function(){ currentEditRow.removeClass('just-edited'); }, 1300);
      closeEditModal();
    }
  }, 'json');
}

function closeEditModal() { $('#editModal').removeClass('open'); currentEditIndex=-1; currentEditRow=null; }
function showSaving(m) { $('#savingInd').text(m).addClass('show'); }
function hideSaving()  { $('#savingInd').removeClass('show'); }
</script>
</body></html>
