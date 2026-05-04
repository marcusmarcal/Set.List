<?php
require '_helpers.php';
checkAuth();

$activePl = getActivePlaylist();
$plId     = $activePl['id'] ?? 'principal';
$plParam  = '?pl=' . urlencode($plId);
$error    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['_action'])) {
    requireAuthOrDie('index.php');
    $title       = trim($_POST['title']        ?? '');
    $artist      = trim($_POST['artist']       ?? '');
    $cifraUrl    = trim($_POST['cifra_url']    ?? '');
    $cifraSource = trim($_POST['cifra_source'] ?? 'cifraclub');
    if ($title && $artist) {
        $songs = loadSongs($activePl);
        $songs[] = ['title' => $title, 'artist' => $artist,
                    'cifra_url' => $cifraUrl ?: 'N/A', 'cifra_source' => $cifraSource];
        saveSongs($activePl, $songs);
        header('Location: index.php' . $plParam);
        exit;
    } else {
        $error = 'Título e artista são obrigatórios.';
    }
}

// If not authed, show page but form submit will hit lock
pageHead('Adicionar Música');
renderSidebar('add');
?>
<div class="sidebar-backdrop" id="backdrop"></div>
<div class="main">
  <div class="topbar">
    <div style="display:flex;align-items:center;gap:10px">
      <button class="hamburger" id="menuBtn">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div>
        <div class="topbar-title">Adicionar Música</div>
        <div class="topbar-sub"><?= htmlspecialchars($activePl['name'] ?? '') ?></div>
      </div>
    </div>
    <a href="index.php<?= $plParam ?>" class="btn btn-outline">← Voltar</a>
  </div>
  <div class="content fade-up">
    <div class="form-card">
      <?php if ($error): ?><div class="alert alert-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
      <form id="addForm" method="POST">
        <div class="form-group">
          <label class="form-label">Título</label>
          <input class="form-input" type="text" name="title" placeholder="Ex: Resposta"
                 value="<?= htmlspecialchars($_POST['title'] ?? '') ?>" required autofocus>
        </div>
        <div class="form-group">
          <label class="form-label">Artista</label>
          <input class="form-input" type="text" name="artist" placeholder="Ex: Skank"
                 value="<?= htmlspecialchars($_POST['artist'] ?? '') ?>" required>
        </div>
        <?php renderCifraField($_POST['cifra_url'] ?? '', $_POST['cifra_source'] ?? 'cifraclub'); ?>
        <div style="display:flex;gap:10px;margin-top:4px">
          <button type="submit" class="btn btn-primary" id="submitBtn">Adicionar Música</button>
          <a href="index.php<?= $plParam ?>" class="btn btn-outline">Cancelar</a>
        </div>
      </form>
    </div>
  </div>
</div>

<?php renderLockModal(); ?>

<script>
document.getElementById('addForm').addEventListener('submit', function(e) {
  if (!_isLocked || _isAuthed) return; // let it submit normally
  e.preventDefault();
  openLockModal(function() {
    _isAuthed = true;
    document.getElementById('addForm').submit();
  });
});
document.getElementById('menuBtn').onclick = function() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('backdrop').classList.toggle('open');
};
document.getElementById('backdrop').onclick = function() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('backdrop').classList.remove('open');
};
</script>
</body></html>
