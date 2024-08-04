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

echo "Músicas importadas com sucesso!";
?>
