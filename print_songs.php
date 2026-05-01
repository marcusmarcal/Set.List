<?php
require '_helpers.php';

$activePl = getActivePlaylist();
$plId     = $activePl['id'] ?? 'principal';
$plParam  = '?pl=' . urlencode($plId);
$songs    = loadSongs($activePl);

$sortCol   = $_GET['sort']  ?? '';
$sortOrder = $_GET['order'] ?? 'asc';
if ($sortCol) $songs = sortSongs($songs, $sortCol, $sortOrder);
$total = count($songs);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Imprimir · <?= htmlspecialchars($activePl['name'] ?? 'SetList') ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Mono:wght@300;400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
  <style>
    :root {
      --accent: #1db954;
      --bg: #0a0a0c;
      --bg2: #111115;
      --bg3: #18181e;
      --border: #222228;
      --border2: #2e2e38;
      --text: #f0f0f4;
      --text2: #8888a0;
      --text3: #555568;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--bg);
      color: var(--text);
      padding: 24px;
    }

    .controls {
      display: flex;
      align-items: center;
      gap: 16px;
      flex-wrap: wrap;
      margin-bottom: 28px;
      padding: 18px 22px;
      background: var(--bg2);
      border: 1px solid var(--border);
      border-radius: 12px;
    }
    .controls-label {
      font-family: 'DM Mono', monospace;
      font-size: 0.6rem; letter-spacing: 0.14em; text-transform: uppercase;
      color: var(--text3); white-space: nowrap;
    }
    .col-options { display: flex; gap: 6px; }
    .col-btn {
      padding: 7px 14px; border-radius: 7px;
      border: 1px solid var(--border2);
      background: transparent; color: var(--text2);
      font-family: 'DM Sans', sans-serif; font-size: 0.8rem;
      cursor: pointer; transition: all 0.15s;
    }
    .col-btn:hover { border-color: var(--accent); color: var(--accent); }
    .col-btn.active { background: rgba(29,185,84,0.12); border-color: var(--accent); color: var(--accent); }
    .divider { width: 1px; height: 28px; background: var(--border); flex-shrink: 0; }
    .btn {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 9px 18px; border-radius: 8px;
      font-family: 'DM Sans', sans-serif; font-size: 0.82rem; font-weight: 500;
      cursor: pointer; text-decoration: none; border: 1px solid transparent; white-space: nowrap;
    }
    .btn-primary { background: var(--accent); color: #000; border-color: var(--accent); }
    .btn-outline  { background: transparent; color: var(--text2); border-color: var(--border2); }
    .meta-info {
      margin-left: auto;
      font-family: 'DM Mono', monospace; font-size: 0.65rem;
      color: var(--text3); letter-spacing: 0.08em;
    }

    .print-header {
      text-align: center; margin-bottom: 24px;
      padding-bottom: 18px; border-bottom: 1px solid var(--border);
    }
    .print-header h1 {
      font-family: 'Playfair Display', serif;
      font-size: 2rem; font-weight: 900; letter-spacing: -0.02em;
    }
    .print-header h1 span { color: var(--accent); }
    .print-header .meta {
      font-family: 'DM Mono', monospace; font-size: 0.65rem;
      letter-spacing: 0.12em; text-transform: uppercase; color: var(--text3); margin-top: 6px;
    }

    /* Table (1 col) */
    table { width: 100%; border-collapse: collapse; }
    thead th {
      font-family: 'DM Mono', monospace; font-size: 0.6rem;
      letter-spacing: 0.15em; text-transform: uppercase;
      color: var(--text3); padding: 10px 14px;
      border-bottom: 1px solid var(--border); text-align: left;
    }
    tbody tr { border-bottom: 1px solid var(--bg3); }
    tbody tr:last-child { border-bottom: none; }
    tbody td { padding: 10px 14px; font-size: 0.875rem; }
    .td-num { font-family: 'DM Mono', monospace; font-size: 0.7rem; color: var(--text3); width: 48px; }
    .td-title { font-weight: 500; }
    .td-artist { color: var(--text2); font-size: 0.82rem; }

    /* Grid (multi-col) */
    .col-grid { display: none; gap: 0 24px; }
    .song-item {
      display: flex; align-items: baseline; gap: 8px;
      padding: 7px 0; border-bottom: 1px solid var(--border);
    }
    .song-num { font-family: 'DM Mono', monospace; font-size: 0.65rem; color: var(--text3); flex-shrink:0; width:22px; text-align:right; }
    .song-info { flex:1; min-width:0; }
    .song-title { font-size:0.82rem; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .song-artist { font-size:0.7rem; color:var(--text2); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

    /* Layout switching */
    #songList.layout-2 table,
    #songList.layout-3 table,
    #songList.layout-4 table { display: none; }
    #songList.layout-2 .col-grid { display: grid; grid-template-columns: repeat(2,1fr); }
    #songList.layout-3 .col-grid { display: grid; grid-template-columns: repeat(3,1fr); }
    #songList.layout-4 .col-grid { display: grid; grid-template-columns: repeat(4,1fr); }

    @media print {
      body { background:#fff; color:#000; padding:8mm 10mm; }
      .controls { display:none !important; }
      .print-header { border-bottom:1pt solid #ccc; margin-bottom:12pt; padding-bottom:10pt; }
      .print-header h1 { color:#000; font-size:18pt; }
      .print-header h1 span { color:#1db954; }
      .print-header .meta { color:#888; }
      thead th { color:#777; border-bottom:1pt solid #ccc; font-size:7pt; padding:5pt 8pt; }
      tbody tr { border-bottom:1pt solid #eee; }
      tbody td { padding:5pt 8pt; font-size:9pt; color:#000; }
      .td-num { color:#999; }
      .td-artist { color:#555; }
      .song-item { border-bottom:1pt solid #eee; padding:4pt 0; }
      .song-num { color:#aaa; }
      .song-title { font-size:8pt; color:#000; }
      .song-artist { font-size:7pt; color:#555; }
      #songList.layout-2 .col-grid { grid-template-columns:repeat(2,1fr); gap:0 16pt; }
      #songList.layout-3 .col-grid { grid-template-columns:repeat(3,1fr); gap:0 12pt; }
      #songList.layout-4 .col-grid { grid-template-columns:repeat(4,1fr); gap:0 8pt; }
    }
  </style>
</head>
<body>

<div class="controls">
  <span class="controls-label">Colunas</span>
  <div class="col-options">
    <button class="col-btn active" onclick="setLayout(1)" id="btn1">1 coluna</button>
    <button class="col-btn" onclick="setLayout(2)" id="btn2">2 colunas</button>
    <button class="col-btn" onclick="setLayout(3)" id="btn3">3 colunas</button>
    <button class="col-btn" onclick="setLayout(4)" id="btn4">4 colunas</button>
  </div>
  <div class="divider"></div>
  <button class="btn btn-primary" onclick="window.print()">
    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
    Imprimir
  </button>
  <a href="index.php<?= $plParam ?>" class="btn btn-outline">← Voltar</a>
  <span class="meta-info"><?= $total ?> músicas · <?= htmlspecialchars($activePl['name'] ?? '') ?></span>
</div>

<div class="print-header">
  <h1>Set<span>.</span>List</h1>
  <div class="meta"><?= htmlspecialchars($activePl['name'] ?? '') ?> · <?= $total ?> músicas</div>
</div>

<div class="song-list layout-1" id="songList">
  <table>
    <thead>
      <tr>
        <th class="td-num">#</th>
        <th>Título</th>
        <th>Artista</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($songs as $i => $song): ?>
      <tr>
        <td class="td-num"><?= str_pad($i + 1, 2, '0', STR_PAD_LEFT) ?></td>
        <td class="td-title"><?= htmlspecialchars($song['title']) ?></td>
        <td class="td-artist"><?= htmlspecialchars($song['artist']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="col-grid">
    <?php foreach ($songs as $i => $song): ?>
    <div class="song-item">
      <span class="song-num"><?= str_pad($i + 1, 2, '0', STR_PAD_LEFT) ?></span>
      <div class="song-info">
        <div class="song-title"><?= htmlspecialchars($song['title']) ?></div>
        <div class="song-artist"><?= htmlspecialchars($song['artist']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<script>
function setLayout(n) {
  var list = document.getElementById('songList');
  list.className = 'song-list layout-' + n;
  [1,2,3,4].forEach(function(i) {
    document.getElementById('btn'+i).classList.toggle('active', i === n);
  });
  try { localStorage.setItem('print_layout', n); } catch(e) {}
}
try {
  var saved = parseInt(localStorage.getItem('print_layout'));
  if (saved >= 1 && saved <= 4) setLayout(saved);
} catch(e) {}
</script>
</body>
</html>
