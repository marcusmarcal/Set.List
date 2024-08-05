<?php
// Lê o conteúdo do arquivo JSON
$jsonFile = 'songs.json';
$jsonData = file_get_contents($jsonFile);
$songs = json_decode($jsonData, true);

// Remove a variável 'key' de cada música
foreach ($songs as &$song) {
    unset($song['key']);
}

// Salva o JSON atualizado de volta ao arquivo
file_put_contents($jsonFile, json_encode($songs, JSON_PRETTY_PRINT));

// Exibe o JSON atualizado
echo json_encode($songs, JSON_PRETTY_PRINT);
?>
