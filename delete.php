<?php
require '_helpers.php';
checkAuth();
requireAuthOrDie('index.php');

$activePl = getActivePlaylist();
$plId     = $activePl['id'] ?? 'principal';
$plParam  = '?pl=' . urlencode($plId);

if (!isset($_POST['index'])) { header('Location: index.php' . $plParam); exit; }

$songs = loadSongs($activePl);
$index = (int)$_POST['index'];
if ($index >= 0 && $index < count($songs)) {
    array_splice($songs, $index, 1);
    saveSongs($activePl, $songs);
}
header('Location: index.php' . $plParam);
exit;
