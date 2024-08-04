<?php
// Autoload do Composer
require 'vendor/autoload.php';

// Carregar variáveis de ambiente do arquivo .env
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Função para obter um token de acesso
function getAccessToken($clientId, $clientSecret) {
    $url = 'https://accounts.spotify.com/api/token';
    $headers = [
        'Authorization: Basic ' . base64_encode($clientId . ':' . $clientSecret),
        'Content-Type: application/x-www-form-urlencoded'
    ];
    $body = 'grant_type=client_credentials';

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $response = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($response, true);
    return $result['access_token'];
}

// Função para obter as músicas de uma playlist
function getPlaylistTracks($accessToken, $playlistId) {
    $url = "https://api.spotify.com/v1/playlists/$playlistId/tracks";
    $headers = [
        'Authorization: Bearer ' . $accessToken
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

// IDs do cliente Spotify
$clientId = $_ENV['CLIENT_ID'];
$clientSecret = $_ENV['CLIENT_SECRET'];
$playlistId = $_ENV['PLAYLIST_ID'];

// Obtenha o token de acesso
$accessToken = getAccessToken($clientId, $clientSecret);

// Obtenha as faixas da playlist
$playlistTracks = getPlaylistTracks($accessToken, $playlistId);

// Prepare o array de músicas
$songs = [];
foreach ($playlistTracks['items'] as $item) {
    $track = $item['track'];
    $songs[] = [
        'title' => $track['name'],
        'artist' => $track['artists'][0]['name'],
        'key' => 'N/A' // Tonalidade não disponível diretamente na API
    ];
}

// Salva as músicas no arquivo JSON
$jsonFile = 'songs.json';
file_put_contents($jsonFile, json_encode($songs, JSON_PRETTY_PRINT));

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Importação Concluída</title>
    <!-- Redireciona para index.php após 2 segundos -->
    <meta http-equiv="refresh" content="2;url=index.php">
    <style>
        body {
            font-family: Arial, sans-serif;
            text-align: center;
            margin-top: 50px;
        }
        .message {
            background-color: #e8f5e9;
            color: #4caf50;
            padding: 20px;
            border-radius: 5px;
            display: inline-block;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
    </style>
</head>
<body>
    <div class="message">
        <h1>Importação Concluída!</h1>
        <p>Você será redirecionado para a página inicial em 2 segundos.</p>
    </div>
</body>
</html>