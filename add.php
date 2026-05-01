<?php
require '_helpers.php';

$activePl = getActivePlaylist();
$plId     = $activePl['id'] ?? 'principal';
$plParam  = '?pl=' . urlencode($plId);
$error    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title    = trim($_POST['title'] ?? '');
    $artist   = trim($_POST['artist'] ?? '');
    $cifraUrl = trim($_POST['cifra_url'] ?? '');

    if ($title && $artist) {
        $songs = loadSongs($activePl);
        $songs[] = [
            'title'     => $title,
            'artist'    => $artist,
            'cifra_url' => $cifraUrl ?: 'N/A'
        ];
        saveSongs($activePl, $songs);
        header('Location: index.php' . $plParam);
        exit;
    } else {
        $error = 'Título e artista são obrigatórios.';
    }
}

pageHead('Adicionar Música');
renderSidebar('add');
?>

<div class="main">
  <div class="topbar">
    <div>
      <div class="topbar-title">Adicionar Música</div>
      <div class="topbar-sub"><?= htmlspecialchars($activePl['name'] ?? '') ?></div>
    </div>
    <a href="index.php<?= $plParam ?>" class="btn btn-outline">← Voltar</a>
  </div>

  <div class="content fade-up">
    <div class="form-card">
      <?php if ($error): ?>
        <div class="alert alert-error">
          <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <form action="" method="POST">
        <div class="form-group">
          <label class="form-label" for="title">Título</label>
          <input class="form-input" type="text" id="title" name="title"
                 placeholder="Ex: Resposta" value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required autofocus>
        </div>

        <div class="form-group">
          <label class="form-label" for="artist">Artista</label>
          <input class="form-input" type="text" id="artist" name="artist"
                 placeholder="Ex: Skank" value="<?= htmlspecialchars($_POST['artist'] ?? '') ?>" required>
        </div>

        <div class="form-group">
          <label class="form-label" for="cifra_url">URL da Cifra <span style="color:var(--text3)">(opcional)</span></label>
          <input class="form-input" type="text" id="cifra_url" name="cifra_url"
                 placeholder="skank/resposta  ou  URL completa"
                 value="<?= htmlspecialchars($_POST['cifra_url'] ?? '') ?>">
          <div style="font-size:0.72rem;color:var(--text3);margin-top:6px">
            Pode ser o caminho relativo no Cifra Club ou a URL completa.
          </div>
        </div>

        <div style="display:flex;gap:10px;margin-top:8px">
          <button type="submit" class="btn btn-primary">Adicionar Música</button>
          <a href="index.php<?= $plParam ?>" class="btn btn-outline">Cancelar</a>
        </div>
      </form>
    </div>
  </div>
</div>

</body></html>
