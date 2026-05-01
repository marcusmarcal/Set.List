<?php
require '_helpers.php';

$activePl = getActivePlaylist();
$plId     = $activePl['id'] ?? 'principal';
$plParam  = '?pl=' . urlencode($plId);
$songs    = loadSongs($activePl);

if (!isset($_GET['index'])) { header('Location: index.php' . $plParam); exit; }
$index = (int)$_GET['index'];
if (!isset($songs[$index])) die('Índice inválido.');
$song  = $songs[$index];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title    = trim($_POST['title'] ?? '');
    $artist   = trim($_POST['artist'] ?? '');
    $cifraUrl = trim($_POST['cifra_url'] ?? '');

    if ($title && $artist) {
        $songs[$index]['title']     = $title;
        $songs[$index]['artist']    = $artist;
        $songs[$index]['cifra_url'] = $cifraUrl ?: 'N/A';
        saveSongs($activePl, $songs);
        header('Location: index.php' . $plParam);
        exit;
    } else {
        $error = 'Título e artista são obrigatórios.';
    }
}

pageHead('Editar Música');
renderSidebar('index');
?>

<div class="main">
  <div class="topbar">
    <div>
      <div class="topbar-title">Editar Música</div>
      <div class="topbar-sub">#<?= str_pad($index + 1, 2, '0', STR_PAD_LEFT) ?> · <?= htmlspecialchars($activePl['name'] ?? '') ?></div>
    </div>
    <a href="index.php<?= $plParam ?>" class="btn btn-outline">← Voltar</a>
  </div>

  <div class="content fade-up">
    <div class="form-card">
      <?php if ($error): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>

      <form action="edit.php?index=<?= $index ?><?= str_replace('?', '&', $plParam) ?>" method="POST">
        <div class="form-group">
          <label class="form-label" for="title">Título</label>
          <input class="form-input" type="text" id="title" name="title"
                 value="<?= htmlspecialchars($_POST['title'] ?? $song['title']) ?>" required autofocus>
        </div>

        <div class="form-group">
          <label class="form-label" for="artist">Artista</label>
          <input class="form-input" type="text" id="artist" name="artist"
                 value="<?= htmlspecialchars($_POST['artist'] ?? $song['artist']) ?>" required>
        </div>

        <div class="form-group">
          <label class="form-label" for="cifra_url">URL da Cifra <span style="color:var(--text3)">(opcional)</span></label>
          <input class="form-input" type="text" id="cifra_url" name="cifra_url"
                 placeholder="skank/resposta  ou  URL completa"
                 value="<?= htmlspecialchars($_POST['cifra_url'] ?? ($song['cifra_url'] === 'N/A' ? '' : $song['cifra_url'])) ?>">
        </div>

        <div style="display:flex;gap:10px;margin-top:8px">
          <button type="submit" class="btn btn-primary">Salvar Alterações</button>
          <a href="index.php<?= $plParam ?>" class="btn btn-outline">Cancelar</a>
        </div>
      </form>
    </div>
  </div>
</div>

</body></html>
