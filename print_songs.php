<?php
require '_helpers.php';

$activePl  = getActivePlaylist();
$playlists = loadPlaylists();
$plId      = $activePl['id'] ?? 'principal';
$plParam   = '?pl=' . urlencode($plId);
$songs     = loadSongs($activePl);
$total     = count($songs);

// Duration
$totalMs = array_sum(array_column($songs, 'duration_ms'));
function fmtDur($ms) {
    if (!$ms) return null;
    $s = intdiv($ms, 1000);
    $h = intdiv($s, 3600); $s -= $h * 3600;
    $m = intdiv($s, 60);
    return $h ? sprintf('%dh %02dm', $h, $m) : sprintf('%dm', $m);
}
$durationStr = fmtDur($totalMs);
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
      --accent:#1db954; --bg:#0a0a0c; --bg2:#111115; --bg3:#18181e;
      --border:#222228; --border2:#2e2e38;
      --text:#f0f0f4; --text2:#8888a0; --text3:#555568;
      --radius:8px; --radius2:14px;
    }
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);padding:20px;}

    /* ── CONTROLS ── */
    .controls{
      display:flex;align-items:center;gap:10px;flex-wrap:wrap;
      margin-bottom:22px;padding:14px 18px;
      background:var(--bg2);border:1px solid var(--border);border-radius:var(--radius2);
    }
    .controls-label{font-family:'DM Mono',monospace;font-size:.58rem;letter-spacing:.14em;text-transform:uppercase;color:var(--text3);}
    .pg-options{display:flex;gap:5px;}
    .pg-btn{
      padding:6px 12px;border-radius:6px;border:1px solid var(--border2);
      background:transparent;color:var(--text2);font-family:'DM Sans',sans-serif;font-size:.78rem;cursor:pointer;transition:all .15s;
    }
    .pg-btn:hover{border-color:var(--accent);color:var(--accent);}
    .pg-btn.active{background:rgba(29,185,84,.12);border-color:var(--accent);color:var(--accent);}

    /* ── PLAYLIST DROPDOWN ── */
    .pl-dropdown-wrap{position:relative;}
    .pl-dropdown-btn{
      display:inline-flex;align-items:center;gap:6px;
      padding:6px 12px;border-radius:6px;border:1px solid var(--border2);
      background:transparent;color:var(--text2);font-family:'DM Sans',sans-serif;font-size:.78rem;cursor:pointer;transition:all .15s;
    }
    .pl-dropdown-btn:hover{border-color:var(--accent);color:var(--accent);}
    .pl-dropdown-btn svg{width:11px;height:11px;transition:transform .15s;}
    .pl-dropdown-btn.open svg{transform:rotate(180deg);}
    .pl-dropdown-menu{
      position:absolute;top:calc(100% + 6px);left:0;
      background:var(--bg2);border:1px solid var(--border2);border-radius:var(--radius2);
      min-width:180px;z-index:50;
      box-shadow:0 8px 28px rgba(0,0,0,.5);
      overflow:hidden;
      display:none;
    }
    .pl-dropdown-menu.open{display:block;}
    .pl-dropdown-item{
      display:block;padding:9px 14px;font-size:.8rem;color:var(--text2);
      text-decoration:none;transition:background .12s;white-space:nowrap;
    }
    .pl-dropdown-item:hover{background:var(--bg3);color:var(--text);}
    .pl-dropdown-item.active{color:var(--accent);}

    .divider{width:1px;height:24px;background:var(--border);flex-shrink:0;}
    .btn{
      display:inline-flex;align-items:center;gap:6px;padding:7px 16px;border-radius:var(--radius);
      font-family:'DM Sans',sans-serif;font-size:.8rem;font-weight:500;
      cursor:pointer;text-decoration:none;border:1px solid transparent;
    }
    .btn-primary{background:var(--accent);color:#000;border-color:var(--accent);}
    .btn-outline{background:transparent;color:var(--text2);border-color:var(--border2);}
    .meta-info{margin-left:auto;font-family:'DM Mono',monospace;font-size:.62rem;color:var(--text3);letter-spacing:.08em;}

    /* ── PRINT HEADER ── */
    .print-header{text-align:center;margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid var(--border);}
    .print-header h1{font-family:'Playfair Display',serif;font-size:1.6rem;font-weight:900;letter-spacing:-.02em;}
    .print-header h1 span{color:var(--accent);}
    .print-header .meta{font-family:'DM Mono',monospace;font-size:.58rem;letter-spacing:.12em;text-transform:uppercase;color:var(--text3);margin-top:4px;}

    /* ── TABLE ── */
    table{width:100%;border-collapse:collapse;}
    thead th{
      font-family:'DM Mono',monospace;font-size:.56rem;letter-spacing:.14em;text-transform:uppercase;
      color:var(--text3);padding:7px 10px;border-bottom:1px solid var(--border);text-align:left;
    }
    tbody tr{border-bottom:1px solid var(--bg3);}
    tbody tr:last-child{border-bottom:none;}
    tbody td{padding:5px 10px;font-size:.82rem;}
    .td-num{font-family:'DM Mono',monospace;font-size:.65rem;color:var(--text3);width:36px;}
    .td-artist{color:var(--text2);font-size:.76rem;}

    /* ── PRINT MEDIA ── */
    @media print{
      body{background:#fff;color:#000;padding:6mm 8mm;}
      .controls{display:none!important;}
      .print-header{border-bottom:1pt solid #bbb;margin-bottom:8pt;padding-bottom:8pt;}
      .print-header h1{color:#000;font-size:14pt;}
      .print-header h1 span{color:#1db954;}
      .print-header .meta{color:#888;}
      thead th{color:#666;border-bottom:.5pt solid #ccc;padding:4pt 6pt;font-size:6pt;}
      tbody tr{border-bottom:.5pt solid #eee;}
      tbody td{padding:3pt 6pt;color:#000;}
      .td-num{color:#999;}
      .td-artist{color:#444;}

      body.pg-fit1 thead th{font-size:5pt;padding:2pt 4pt;}
      body.pg-fit1 tbody td{padding:1.5pt 4pt;font-size:7pt;}
      body.pg-fit1 .print-header h1{font-size:11pt;}
      body.pg-fit1 .print-header{margin-bottom:4pt;padding-bottom:4pt;}

      body.pg-fit2 tbody td{padding:2pt 5pt;font-size:8pt;}
      body.pg-fit2 thead th{font-size:5.5pt;padding:3pt 5pt;}
    }
  </style>
</head>
<body class="pg-auto" id="printBody">

<div class="controls">
  <span class="controls-label">Distribuição</span>
  <div class="pg-options">
    <button class="pg-btn" onclick="setMode('pg-fit1')" id="mFit1">1 página</button>
    <button class="pg-btn" onclick="setMode('pg-fit2')" id="mFit2">2 páginas</button>
    <button class="pg-btn active" onclick="setMode('pg-auto')" id="mAuto">Automático</button>
  </div>

  <div class="divider"></div>

  <!-- Playlist switcher dropdown -->
  <div class="pl-dropdown-wrap" id="plDropWrap">
    <button class="pl-dropdown-btn" id="plDropBtn" onclick="togglePlDrop()">
      <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
      <?= htmlspecialchars($activePl['name'] ?? 'Lista') ?>
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
    </button>
    <div class="pl-dropdown-menu" id="plDropMenu">
      <?php foreach ($playlists as $pl): ?>
        <a href="print_songs.php?pl=<?= urlencode($pl['id']) ?>"
           class="pl-dropdown-item <?= $pl['id'] === $plId ? 'active' : '' ?>">
          <?= htmlspecialchars($pl['name']) ?>
          <?php if (!empty($pl['is_default'])): ?><span style="font-size:.6rem;color:var(--text3)"> · padrão</span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="divider"></div>

  <button class="btn btn-primary" onclick="window.print()">
    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
    Imprimir
  </button>
  <a href="index.php<?= $plParam ?>" class="btn btn-outline">← Voltar</a>
  <span class="meta-info">
    <?= $total ?> músicas<?= $durationStr ? ' · ' . $durationStr : '' ?>
    · <?= htmlspecialchars($activePl['name'] ?? '') ?>
  </span>
</div>

<div class="print-header">
  <h1>Set<span>.</span>List</h1>
  <div class="meta">
    <?= htmlspecialchars($activePl['name'] ?? '') ?>
    · <?= $total ?> músicas<?= $durationStr ? ' · ' . $durationStr : '' ?>
  </div>
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

<script>
var modes = ['pg-fit1','pg-fit2','pg-auto'];
var modeIds = {
  'pg-fit1': 'mFit1',
  'pg-fit2': 'mFit2',
  'pg-auto': 'mAuto'
};

function setMode(m) {
  var body = document.getElementById('printBody');
  modes.forEach(function(c){ body.classList.remove(c); });
  body.classList.add(m);
  modes.forEach(function(c){
    var el = document.getElementById(modeIds[c]);
    if (el) el.classList.toggle('active', c === m);
  });
  try { localStorage.setItem('print_mode', m); } catch(e) {}
}

function togglePlDrop() {
  var btn  = document.getElementById('plDropBtn');
  var menu = document.getElementById('plDropMenu');
  btn.classList.toggle('open');
  menu.classList.toggle('open');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
  var wrap = document.getElementById('plDropWrap');
  if (wrap && !wrap.contains(e.target)) {
    document.getElementById('plDropBtn').classList.remove('open');
    document.getElementById('plDropMenu').classList.remove('open');
  }
});

// Restore saved mode
try {
  var saved = localStorage.getItem('print_mode');
  if (saved && modes.includes(saved)) setMode(saved);
} catch(e) {}
</script>
</body>
</html>
