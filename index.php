<?php
require '_helpers.php';

$activePl   = getActivePlaylist();
$playlists  = loadPlaylists();
$plId       = $activePl['id'] ?? 'principal';
$plParam    = '?pl=' . urlencode($plId);

// ── AJAX: reorder ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order'])) {
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

// ── AJAX: inline edit ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_edit'])) {
    header('Content-Type: application/json');
    $songs  = loadSongs($activePl);
    $index  = (int)($_POST['index'] ?? -1);
    $title  = trim($_POST['title']  ?? '');
    $artist = trim($_POST['artist'] ?? '');
    if ($index >= 0 && $index < count($songs) && $title && $artist) {
        $songs[$index]['title']  = $title;
        $songs[$index]['artist'] = $artist;
        saveSongs($activePl, $songs);
        echo json_encode(['ok' => true, 'title' => $title, 'artist' => $artist]);
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

pageHead('Músicas · ' . ($activePl['name'] ?? 'SetList'));
?>
<link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
<style>
  .ui-sortable-helper { background:var(--bg3)!important; box-shadow:0 8px 28px rgba(0,0,0,0.5); opacity:.95; }
  .ui-sortable-placeholder { visibility:visible!important; background:var(--accent-dim)!important; border:1px dashed var(--accent-glow)!important; height:36px!important; }
</style>

<?php renderSidebar('index'); ?>
<div class="sidebar-backdrop" id="backdrop"></div>

<div class="main">
  <div class="topbar">
    <div style="display:flex;align-items:center;gap:10px">
      <button class="hamburger" id="menuBtn" aria-label="Menu">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div>
        <div class="topbar-title"><?= htmlspecialchars($activePl['name'] ?? 'SetList') ?></div>
        <div class="topbar-sub">
          <?= $totalSongs ?> músicas<?php if (!empty($activePl['is_default'])): ?> · <span style="color:var(--accent)">✦ padrão</span><?php endif; ?>
        </div>
      </div>
    </div>
    <div style="display:flex;gap:8px;align-items:center">
      <span class="saving-indicator" id="savingInd">Salvo ✓</span>
      <a href="add.php<?= $plParam ?>" class="btn btn-primary">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        <span class="btn-label">Adicionar</span>
      </a>
    </div>
  </div>

  <div class="content fade-up">
    <div class="stats-row">
      <div class="stat-card">
        <div class="stat-num"><?= str_pad($totalSongs, 2, '0', STR_PAD_LEFT) ?></div>
        <div class="stat-label">Músicas</div>
      </div>
      <div class="stat-card">
        <div class="stat-num"><?= str_pad($artistCount, 2, '0', STR_PAD_LEFT) ?></div>
        <div class="stat-label">Artistas</div>
      </div>
      <div class="stat-card">
        <div class="stat-num"><?= str_pad($withCifra, 2, '0', STR_PAD_LEFT) ?></div>
        <div class="stat-label">Com cifra</div>
      </div>
    </div>

    <div class="search-row">
      <div class="search-wrap">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" class="search-input" id="searchInput" placeholder="Buscar…">
      </div>
      <a href="index.php<?= $plParam ?>&sort=title&order=<?= ($sortCol==='title'&&$sortOrder==='asc') ? 'desc' : 'asc' ?>" class="btn btn-outline">A→Z Título</a>
      <a href="index.php<?= $plParam ?>&sort=artist&order=<?= ($sortCol==='artist'&&$sortOrder==='asc') ? 'desc' : 'asc' ?>" class="btn btn-outline">A→Z Artista</a>
    </div>

    <div class="table-wrap">
      <table id="songsTable">
        <thead>
          <tr>
            <th style="width:36px"></th><!-- drag -->
            <th class="td-num">#</th>
            <th>Título</th>
            <th>Artista</th>
            <th>Cifra</th>
            <th class="td-actions">Ações</th>
          </tr>
        </thead>
        <tbody id="sortable">
          <?php foreach ($songs as $index => $song): ?>
          <?php $cifraUrl = formatCifraUrl($song['cifra_url'] ?? ''); ?>
          <tr data-index="<?= $index ?>" data-title="<?= htmlspecialchars($song['title'], ENT_QUOTES) ?>" data-artist="<?= htmlspecialchars($song['artist'], ENT_QUOTES) ?>" class="song-row">
            <td style="width:36px;padding-right:0">
              <span class="drag-handle" title="Arrastar">
                <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="5" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="19" r="1"/></svg>
              </span>
            </td>
            <td class="td-num"><?= str_pad($index + 1, 2, '0', STR_PAD_LEFT) ?></td>
            <td class="td-title"><?= htmlspecialchars($song['title']) ?></td>
            <td class="td-artist"><?= htmlspecialchars($song['artist']) ?></td>
            <td>
              <?php if ($cifraUrl): ?>
                <a href="<?= htmlspecialchars($cifraUrl) ?>" target="_blank" class="badge badge-green" style="text-decoration:none">cifra</a>
              <?php else: ?>
                <span class="badge badge-gray">—</span>
              <?php endif; ?>
            </td>
            <td class="td-actions">
              <div class="actions-wrap">
                <button class="btn btn-ghost edit-btn" data-index="<?= $index ?>" title="Editar">
                  <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  <span>Editar</span>
                </button>
                <form action="delete.php" method="POST" onsubmit="return confirm('Excluir esta música?')" style="display:inline-flex">
                  <input type="hidden" name="index" value="<?= $index ?>">
                  <input type="hidden" name="pl" value="<?= htmlspecialchars($plId) ?>">
                  <button type="submit" class="btn btn-danger" title="Excluir">
                    <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                  </button>
                </form>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- INLINE EDIT MODAL -->
<div class="modal-overlay" id="editModal">
  <div class="modal">
    <div class="modal-title">Editar Música</div>
    <div class="modal-sub" id="editModalSub"></div>
    <div class="form-group">
      <label class="form-label">Título</label>
      <input class="form-input" type="text" id="editTitle" placeholder="Título">
    </div>
    <div class="form-group">
      <label class="form-label">Artista</label>
      <input class="form-input" type="text" id="editArtist" placeholder="Artista">
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeEditModal()">Cancelar</button>
      <button class="btn btn-primary" id="editSaveBtn">Salvar</button>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
<script>
var plParam = '<?= addslashes($plParam) ?>';
var currentEditIndex = -1;
var currentEditRow   = null;

$(function() {
  // ── Drag-and-drop reorder ──
  $("#sortable").sortable({
    handle: '.drag-handle',
    placeholder: 'ui-sortable-placeholder',
    update: function() {
      var ids = $("#sortable tr.song-row").map(function() { return $(this).data('index'); }).get();
      showSaving('Salvando…');
      $.post("index.php" + plParam, { order: JSON.stringify(ids) }, function() {
        showSaving('Salvo ✓');
        setTimeout(function() { hideSaving(); }, 1600);
        // Re-number visible rows
        $('#sortable tr.song-row:visible').each(function(i) {
          $(this).find('.td-num').text(String(i+1).padStart(2,'0'));
        });
      });
    }
  });

  // ── Live search ──
  $('#searchInput').on('input', function() {
    var q = $(this).val().toLowerCase();
    $('.song-row').each(function() {
      var t = $(this).find('.td-title').text().toLowerCase();
      var a = $(this).find('.td-artist').text().toLowerCase();
      $(this).toggle(!q || t.includes(q) || a.includes(q));
    });
  });

  // ── Inline edit: open modal ──
  $(document).on('click', '.edit-btn', function() {
    var row   = $(this).closest('tr');
    currentEditIndex = parseInt(row.data('index'));
    currentEditRow   = row;
    $('#editTitle').val(row.data('title'));
    $('#editArtist').val(row.data('artist'));
    $('#editModalSub').text('#' + row.find('.td-num').text());
    $('#editModal').addClass('open');
    setTimeout(function(){ $('#editTitle').focus(); }, 80);
  });

  // ── Inline edit: save ──
  $('#editSaveBtn').on('click', saveEdit);
  $('#editModal').on('keydown', function(e) {
    if (e.key === 'Enter') saveEdit();
    if (e.key === 'Escape') closeEditModal();
  });

  // ── Close modal on backdrop click ──
  $('#editModal').on('click', function(e) {
    if (e.target === this) closeEditModal();
  });

  // ── Mobile sidebar ──
  $('#menuBtn').on('click', function() {
    $('.sidebar').toggleClass('open');
    $('#backdrop').toggleClass('open');
  });
  $('#backdrop').on('click', function() {
    $('.sidebar').removeClass('open');
    $('#backdrop').removeClass('open');
  });
});

function saveEdit() {
  var title  = $('#editTitle').val().trim();
  var artist = $('#editArtist').val().trim();
  if (!title || !artist) { $('#editTitle').focus(); return; }

  $('#editSaveBtn').prop('disabled', true).text('Salvando…');

  $.post("index.php" + plParam, {
    ajax_edit: 1,
    index:  currentEditIndex,
    title:  title,
    artist: artist
  }, function(res) {
    $('#editSaveBtn').prop('disabled', false).text('Salvar');
    if (res.ok) {
      // Update row in place — no page reload
      currentEditRow.find('.td-title').text(res.title);
      currentEditRow.find('.td-artist').text(res.artist);
      currentEditRow.data('title',  res.title);
      currentEditRow.data('artist', res.artist);
      currentEditRow.addClass('just-edited');
      setTimeout(function() { currentEditRow.removeClass('just-edited'); }, 1300);
      closeEditModal();
    }
  }, 'json');
}

function closeEditModal() {
  $('#editModal').removeClass('open');
  currentEditIndex = -1;
  currentEditRow   = null;
}

function showSaving(msg) { $('#savingInd').text(msg).addClass('show'); }
function hideSaving()    { $('#savingInd').removeClass('show'); }
</script>
</body></html>
