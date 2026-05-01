<?php
require '_helpers.php';

$activePl   = getActivePlaylist();
$playlists  = loadPlaylists();
$plId       = $activePl['id'] ?? 'principal';
$plParam    = '?pl=' . urlencode($plId);

// Handle drag-and-drop reorder POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order'])) {
    $songs      = loadSongs($activePl);
    $newOrder   = json_decode($_POST['order'], true);
    if (is_array($newOrder)) {
        $sorted = [];
        foreach ($newOrder as $idx) {
            if (isset($songs[(int)$idx])) $sorted[] = $songs[(int)$idx];
        }
        saveSongs($activePl, $sorted);
    }
    exit;
}

$songs     = loadSongs($activePl);
$sortCol   = isset($_GET['sort']) ? $_GET['sort'] : '';
$sortOrder = isset($_GET['order']) ? $_GET['order'] : 'asc';
if ($sortCol) $songs = sortSongs($songs, $sortCol, $sortOrder);

$totalSongs   = count($songs);
$artistCount  = count(array_unique(array_column($songs, 'artist')));
$withCifra    = count(array_filter($songs, fn($s) => !empty($s['cifra_url']) && $s['cifra_url'] !== 'N/A'));

pageHead('Músicas · ' . ($activePl['name'] ?? 'SetList'));
?>
<link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
<style>
  .jqui-drag { cursor: grab; }
  .jqui-drag:active { cursor: grabbing; }
  .ui-sortable-helper { background: var(--bg3) !important; box-shadow: 0 8px 32px rgba(0,0,0,0.5); border-radius: 4px; opacity: 0.95; }
  .ui-sortable-placeholder { visibility: visible !important; background: var(--accent-dim) !important; border: 1px dashed var(--accent-glow) !important; }
  .saving-indicator { font-family: 'DM Mono', monospace; font-size: 0.65rem; color: var(--accent); letter-spacing: 0.08em; opacity: 0; transition: opacity 0.3s; }
  .saving-indicator.show { opacity: 1; }
</style>

<?php renderSidebar('index'); ?>

<div class="main">
  <div class="topbar">
    <div>
      <div class="topbar-title"><?= htmlspecialchars($activePl['name'] ?? 'SetList') ?></div>
      <div class="topbar-sub">
        <?= $totalSongs ?> músicas
        <?php if (!empty($activePl['is_default'])): ?>
          &nbsp;·&nbsp; <span style="color:var(--accent)">✦ lista padrão</span>
        <?php endif; ?>
      </div>
    </div>
    <div style="display:flex;gap:10px;align-items:center">
      <span class="saving-indicator" id="savingInd">Salvando…</span>
      <a href="add.php<?= $plParam ?>" class="btn btn-primary">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Adicionar
      </a>
    </div>
  </div>

  <div class="content fade-up">
    <!-- Stats -->
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

    <!-- Search + Sort -->
    <div class="search-row">
      <div class="search-wrap">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" class="search-input" id="searchInput" placeholder="Buscar músicas ou artistas…">
      </div>
      <a href="<?= "index.php{$plParam}&sort=title&order=" . ($sortCol==='title'&&$sortOrder==='asc'?'desc':'asc') ?>" class="btn btn-outline">
        A→Z Título
      </a>
      <a href="<?= "index.php{$plParam}&sort=artist&order=" . ($sortCol==='artist'&&$sortOrder==='asc'?'desc':'asc') ?>" class="btn btn-outline">
        A→Z Artista
      </a>
    </div>

    <!-- Table -->
    <div class="table-wrap">
      <table id="songsTable">
        <thead>
          <tr>
            <th style="width:40px"></th>
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
          <tr data-index="<?= $index ?>" class="jqui-drag song-row">
            <td style="width:40px;padding-right:0">
              <span class="drag-handle" title="Arrastar para reordenar">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="5" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="19" r="1"/></svg>
              </span>
            </td>
            <td class="td-num"><?= str_pad($index + 1, 2, '0', STR_PAD_LEFT) ?></td>
            <td class="td-title"><?= htmlspecialchars($song['title']) ?></td>
            <td class="td-artist"><?= htmlspecialchars($song['artist']) ?></td>
            <td>
              <?php if ($cifraUrl): ?>
                <a href="<?= htmlspecialchars($cifraUrl) ?>" target="_blank" class="badge badge-green" style="text-decoration:none">ver cifra</a>
              <?php else: ?>
                <span class="badge badge-gray">—</span>
              <?php endif; ?>
            </td>
            <td class="td-actions">
              <a href="edit.php?index=<?= $index ?><?= str_replace('?', '&', $plParam) ?>" class="btn btn-ghost">Editar</a>
              <form action="delete.php" method="POST" onsubmit="return confirm('Excluir esta música?')">
                <input type="hidden" name="index" value="<?= $index ?>">
                <input type="hidden" name="pl" value="<?= htmlspecialchars($plId) ?>">
                <button type="submit" class="btn btn-danger">
                  <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                </button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
<script>
$(function() {
  // Drag-and-drop sort
  $("#sortable").sortable({
    handle: '.drag-handle',
    placeholder: 'ui-sortable-placeholder',
    update: function() {
      var ids = $("#sortable tr").map(function() { return $(this).data('index'); }).get();
      $('#savingInd').addClass('show');
      $.post("index.php<?= $plParam ?>", { order: JSON.stringify(ids) }, function() {
        $('#savingInd').text('Salvo ✓');
        setTimeout(function() { $('#savingInd').removeClass('show'); $('#savingInd').text('Salvando…'); }, 1500);
        // Re-number rows
        $('#sortable tr').each(function(i) {
          $(this).find('.td-num').text(String(i+1).padStart(2,'0'));
        });
      });
    }
  });

  // Live search
  $('#searchInput').on('input', function() {
    var q = $(this).val().toLowerCase();
    $('.song-row').each(function() {
      var title  = $(this).find('.td-title').text().toLowerCase();
      var artist = $(this).find('.td-artist').text().toLowerCase();
      $(this).toggle(title.includes(q) || artist.includes(q));
    });
  });
});
</script>
</body></html>
