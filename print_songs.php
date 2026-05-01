<?php
require '_helpers.php';

$activePl = getActivePlaylist();
$plId     = $activePl['id'] ?? 'principal';
$plParam  = '?pl=' . urlencode($plId);
$songs    = loadSongs($activePl);

$sortCol   = $_GET['sort']  ?? '';
$sortOrder = $_GET['order'] ?? 'asc';
if ($sortCol) $songs = sortSongs($songs, $sortCol, $sortOrder);
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
      --border: #222228;
      --text3:  #555568;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'DM Sans', sans-serif;
      background: #0a0a0c;
      color: #f0f0f4;
      padding: 24px;
    }
    .no-print {
      display: flex; gap: 12px; align-items: center; margin-bottom: 24px; flex-wrap: wrap;
    }
    .btn {
      display: inline-flex; align-items: center; gap: 7px;
      padding: 9px 18px; border-radius: 8px;
      font-family: 'DM Sans', sans-serif; font-size: 0.82rem; font-weight: 500;
      cursor: pointer; text-decoration: none; border: 1px solid transparent;
    }
    .btn-primary { background: var(--accent); color: #000; }
    .btn-outline  { background: transparent; color: #8888a0; border-color: #2e2e38; }
    .print-header {
      text-align: center; margin-bottom: 28px; padding-bottom: 20px;
      border-bottom: 1px solid #222228;
    }
    .print-header h1 {
      font-family: 'Playfair Display', serif;
      font-size: 2rem; font-weight: 900;
      letter-spacing: -0.02em;
    }
    .print-header h1 span { color: var(--accent); }
    .print-header .meta {
      font-family: 'DM Mono', monospace; font-size: 0.65rem;
      letter-spacing: 0.12em; text-transform: uppercase;
      color: var(--text3); margin-top: 6px;
    }
    table { width: 100%; border-collapse: collapse; }
    thead th {
      font-family: 'DM Mono', monospace;
      font-size: 0.6rem; letter-spacing: 0.15em; text-transform: uppercase;
      color: var(--text3); padding: 10px 14px;
      border-bottom: 1px solid #222228; text-align: left;
    }
    tbody tr { border-bottom: 1px solid #18181e; }
    tbody tr:last-child { border-bottom: none; }
    tbody td { padding: 11px 14px; font-size: 0.875rem; }
    .td-num { font-family: 'DM Mono', monospace; font-size: 0.7rem; color: var(--text3); width: 52px; }
    .td-artist { color: #8888a0; font-size: 0.82rem; }

    @media print {
      body { background: #fff; color: #000; padding: 10px; }
      .no-print { display: none; }
      .print-header h1 { color: #000; }
      .print-header h1 span { color: #1db954; }
      .print-header .meta { color: #888; }
      thead th { color: #888; border-bottom: 1px solid #ccc; }
      tbody tr { border-bottom: 1px solid #eee; }
      tbody td { color: #000; }
      .td-artist { color: #555; }
      .td-num { color: #999; }
    }
  </style>
</head>
<body>
  <div class="no-print">
    <button class="btn btn-primary" onclick="window.print()">
      <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Imprimir
    </button>
    <a href="index.php<?= $plParam ?>" class="btn btn-outline">← Voltar</a>
    <span style="font-family:'DM Mono',monospace;font-size:0.65rem;color:var(--text3);margin-left:4px">
      <?= count($songs) ?> músicas · <?= htmlspecialchars($activePl['name'] ?? '') ?>
    </span>
  </div>

  <div class="print-header">
    <h1>Set<span>.</span>List</h1>
    <div class="meta"><?= htmlspecialchars($activePl['name'] ?? '') ?> · <?= count($songs) ?> músicas</div>
  </div>

  <table>
    <thead>
      <tr>
        <th>#</th>
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
    // Auto-print after fonts load
    if (window.location.search.includes('autoprint')) {
      window.addEventListener('load', () => setTimeout(() => window.print(), 300));
    }
  </script>
</body>
</html>
