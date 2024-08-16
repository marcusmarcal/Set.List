<?php
// Nome do arquivo JSON
$jsonFile = 'songs.json';

// Lê o conteúdo do arquivo JSON
$jsonData = file_get_contents($jsonFile);
$songs = json_decode($jsonData, true);

// Nome do arquivo CSV a ser gerado
$csvFile = 'songs.csv';

// Abre o arquivo CSV para escrita em modo 'w' com codificação UTF-8
$handle = fopen($csvFile, 'w');

// Escreve a assinatura UTF-8 no início do arquivo para garantir a codificação correta
fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

// Se o JSON não estiver vazio, escreva o cabeçalho do CSV
if (!empty($songs)) {
    // Obtém as chaves do primeiro item do array para criar o cabeçalho
    $header = array_keys($songs[0]);

    // Escreve o cabeçalho no CSV sem aspas
    fwrite($handle, implode(",", $header) . PHP_EOL);

    // Escreve os dados das músicas no CSV
    foreach ($songs as $song) {
        // Remove aspas de valores e implode para CSV
        $line = implode(",", array_map(function($value) {
            // Remove aspas duplas e converte caracteres especiais para UTF-8
            return str_replace('"', '', $value);
        }, $song));

        // Escreve a linha no arquivo CSV
        fwrite($handle, $line . PHP_EOL);
    }
}

// Fecha o arquivo CSV
fclose($handle);

echo "Arquivo CSV gerado com sucesso: <a href='$csvFile'>$csvFile</a>";
?>
