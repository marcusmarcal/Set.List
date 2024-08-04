<?php
// Arquivo JSON
$jsonFile = 'songs.json';

// Verifica se o arquivo JSON existe
if (!file_exists($jsonFile)) {
    die('Arquivo JSON não encontrado.');
}

// Lê o conteúdo do arquivo JSON
$jsonData = file_get_contents($jsonFile);
$songs = json_decode($jsonData, true);

// Verifica se o índice foi enviado
if (isset($_POST['index'])) {
    $index = intval($_POST['index']);

    // Verifica se o índice está dentro dos limites do array
    if ($index >= 0 && $index < count($songs)) {
        // Remove a música do array
        array_splice($songs, $index, 1);

        // Salva o novo conteúdo no arquivo JSON
        if (file_put_contents($jsonFile, json_encode($songs, JSON_PRETTY_PRINT)) === false) {
            die('Erro ao salvar o arquivo JSON.');
        }
    } else {
        die('Índice inválido.');
    }
} else {
    die('Índice não fornecido.');
}

// Redireciona de volta para a página principal
header('Location: index.php');
exit;
?>
