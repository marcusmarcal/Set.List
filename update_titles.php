<?php
// Caminho para o arquivo JSON
$jsonFile = 'songs.json';

// Lê o conteúdo do arquivo JSON
$jsonData = file_get_contents($jsonFile);
$songs = json_decode($jsonData, true);

// Verifica se a leitura foi bem-sucedida
if (json_last_error() !== JSON_ERROR_NONE) {
    die('Erro ao decodificar o JSON: ' . json_last_error_msg());
}

// Atualiza os títulos das músicas removendo " - Ao Vivo"
foreach ($songs as &$song) {
    if (isset($song['title'])) {
        $song['title'] = str_replace(' - Ao Vivo', '', $song['title']);
    }
}

// Converte o array atualizado de volta para JSON
$updatedJsonData = json_encode($songs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

// Verifica se a codificação foi bem-sucedida
if (json_last_error() !== JSON_ERROR_NONE) {
    die('Erro ao codificar o JSON: ' . json_last_error_msg());
}

// Salva o conteúdo atualizado no arquivo JSON
file_put_contents($jsonFile, $updatedJsonData);

echo 'Arquivo JSON atualizado com sucesso.';
