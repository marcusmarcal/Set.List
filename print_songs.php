<?php
require '_helpers.php';

$activePl = getActivePlaylist();
$plId     = $activePl['id'] ?? 'principal';
$plParam  = '?pl=' . urlencode($plId);
$songs    = loadSongs($activePl);
$total    = count($songs);
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
      --bg: #0a0a0c; --bg2: #111115; --bg3: #18181e;
      --border: #222228; --border2: #2e2e38;
      --text: #f0f0f4; --text2: #8888a0; --text3: #555568;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--bg); color: var(--text); padding: 20px;
    }

    /* ── CONTROLS BAR ── */
    .controls {
      display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
      margin-bottom: 24px; padding: 14px 18px;
      background: var(--bg2); border: 1px solid var(--border); border-radius: 12px;
    }
    .controls-label {
      font-family: 'DM Mono', monospace; font-size: 0.58rem;
      letter-spacing: 0.14em; text-transform: uppercase; color: var(--text3);
    }
    .pg-options { display: flex; gap: 5px; }
    .pg-btn {
      padding: 6px 12px; border-radius: 7px; border: 1px solid var(--border2);
      background: transparent; color: var(--text2);
      font-family: 'DM Sans', sans-serif; font-size: 0.78rem; cursor: pointer; transition: all 0.15s;
    }
    .pg-btn:hover { border-color: var(--accent); color: var(--accent); }
    .pg-btn.active { background: rgba(29,185,84,0.12); border-color: var(--accent); color: var(--accent); }
    .divider { width: 1px; height: 24px; background: var(--border); flex-shrink: 0; }
    .btn {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 7px 16px; border-radius: 8px;
      font-family: 'DM Sans', sans-serif; font-size: 0.8rem; font-weight: 500;
      cursor: pointer; text-decoration: none; border: 1px solid transparent;
    }
    .btn-primary { background: var(--accent); color: #000; border-color: var(--accent); }
    .btn-outline  { background: transparent; color: var(--text2); border-color: var(--border2); }
    .meta-info {
      margin-left: auto; font-family: 'DM Mono', monospace;
      font-size: 0.62rem; color: var(--text3); letter-spacing: 0.08em;
    }

    /* ── PRINT AREA ── */
    .print-area { }

    .print-header {
      text-align: center; margin-bottom: 16px; padding-bottom: 14px;
      border-bottom: 1px solid var(--border);
    }
    .print-header h1 {
      font-family: 'Playfair Display', serif;
      font-size: 1.6rem; font-weight: 900; letter-spacing: -0.02em;
    }
    .print-header h1 span { color: var(--accent); }
    .print-header .meta {
      font-family: 'DM Mono', monospace; font-size: 0.58rem;
      letter-spacing: 0.12em; text-transform: uppercase; color: var(--text3); margin-top: 4px;
    }

    /* ── TABLE ── */
    table { width: 100%; border-collapse: collapse; }
    thead th {
      font-family: 'DM Mono', monospace; font-size: 0.56rem;
      letter-spacing: 0.14em; text-transform: uppercase;
      color: var(--text3); padding: 7px 10px;
      border-bottom: 1px solid var(--border); text-align: left;
    }
    tbody tr { border-bottom: 1px solid var(--bg3); }
    tbody tr:last-child { border-bottom: none; }
    tbody td { padding: 5px 10px; font-size: 0.82rem; }
    .td-num { font-family: 'DM Mono', monospace; font-size: 0.65rem; color: var(--text3); width: 36px; }
    .td-artist { color: var(--text2); font-size: 0.76rem; }

    /* ══════════════════════════════
       PRINT MEDIA QUERY
    ══════════════════════════════ */
    @media print {
      body { background: #fff; color: #000; padding: 6mm 8mm; }
      .controls { display: none !important; }

      .print-header { border-bottom: 1pt solid #bbb; margin-bottom: 8pt; padding-bottom: 8pt; }
      .print-header h1 { color: #000; font-size: 14pt; }
      .print-header h1 span { color: #1db954; }
      .print-header .meta { color: #888; }

      thead th { color: #666; border-bottom: 0.5pt solid #ccc; padding: 4pt 6pt; font-size: 6pt; }
      tbody tr { border-bottom: 0.5pt solid #eee; }
      tbody td { padding: 3pt 6pt; color: #000; }
      .td-num { color: #999; }
      .td-artist { color: #444; }

      /* ── Page size modes ── */
      /* auto (default): browser decides page breaks naturally */
      body.pg-auto  { font-size: 9pt; }

      /* fit-1: try to compress everything onto 1 page */
      body.pg-fit1 {
        /* Scale down font so rows are smaller */
        font-size: 7pt;
      }
      body.pg-fit1 thead th { font-size: 5pt; padding: 2pt 4pt; }
      body.pg-fit1 tbody td { padding: 1.5pt 4pt; font-size: 7pt; }
      body.pg-fit1 .print-header h1 { font-size: 11pt; }
      body.pg-fit1 .print-header { margin-bottom: 5pt; padding-bottom: 5pt; }

      /* fit-2: 2 pages */
      body.pg-fit2 { font-size: 8pt; }
      body.pg-fit2 tbody td { padding: 2pt 5pt; font-size: 8pt; }
      body.pg-fit2 thead th { font-size: 5.5pt; padding: 3pt 5pt; }
    }
  </style>
</head>
<body class="pg-auto" id="printBody">

<!-- CONTROLS -->
<div class="controls">
  <span class="controls-label">Distribuição</span>
  <div class="pg-options">
    <button class="pg-btn" onclick="setMode('pg-fit1')" id="mFit1">1 página</button>
    <button class="pg-btn" onclick="setMode('pg-fit2')" id="mFit2">2 páginas</button>
    <button class="pg-btn active" onclick="setMode('pg-auto')" id="mAuto">Automático</button>
  </div>
  <div class="divider"></div>
  <button class="btn btn-primary" onclick="window.print()">
    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
    Imprimir
  </button>
  <a href="index.php<?= $plParam ?>" class="btn btn-outline">← Voltar</a>
  <span class="meta-info"><?= $total ?> músicas · <?= htmlspecialchars($activePl['name'] ?? '') ?></span>
</div>

<!-- PRINT AREA -->
<div class="print-area">
  <div class="print-header">
    <h1>Set<span>.</span>List</h1>
    <div class="meta"><?= htmlspecialchars($activePl['name'] ?? '') ?> · <?= $total ?> músicas</div>
  </div>

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
        <td><?= htmlspecialchars($song['title']) ?></td>
        <td class="td-artist"><?= htmlspecialchars($song['artist']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
var modes = ['pg-fit1','pg-fit2','pg-auto'];

function setMode(m) {
  var body = document.getElementById('printBody');
  modes.forEach(function(c) { body.classList.remove(c); });
  body.classList.add(m);
  modes.forEach(function(c) {
    var id = 'm' + c.replace('pg-','').charAt(0).toUpperCase() + c.replace('pg-','').slice(1);
    var el = document.getElementById(id);
    if (el) el.classList.toggle('active', c === m);
  });
  try { localStorage.setItem('print_mode', m); } catch(e) {}
}

// Restore saved preference
try {
  var saved = localStorage.getItem('print_mode');
  if (saved && modes.includes(saved)) setMode(saved);
} catch(e) {}
</script>
</body>
</html>
