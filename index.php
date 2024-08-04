<?php
// Função para obter um token de acesso
function getAccessToken() {
    $clientId = getenv('SPOTIPY_CLIENT_ID');
    $clientSecret = getenv('SPOTIPY_CLIENT_SECRET');
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

// Função para atualizar a ordem das faixas na playlist do Spotify
function updatePlaylistOrder($accessToken, $playlistId, $trackUris) {
    $url = "https://api.spotify.com/v1/playlists/$playlistId/tracks";
    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json'
    ];
    $body = json_encode([
        'uris' => $trackUris
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_PUT, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $response = curl_exec($ch);
    curl_close($ch);

    return json_decode($response, true);
}

// Lê o conteúdo do arquivo JSON
$jsonFile = 'songs.json';
$jsonData = file_get_contents($jsonFile);
$songs = json_decode($jsonData, true);

// Função para formatar URL para Cifra Club
function formatCifraUrl($url) {
    if (!empty($url) && !preg_match('/^https?:\/\/(www\.)?cifraclub\.com\.br/', $url)) {
        return 'https://www.cifraclub.com.br/' . $url;
    }
    return $url;
}

// Função para ordenar a lista
function sortSongs($songs, $column, $order) {
    usort($songs, function($a, $b) use ($column, $order) {
        if ($column === 'index') {
            $valA = intval($a[$column]);
            $valB = intval($b[$column]);
            return ($order === 'asc') ? $valA - $valB : $valB - $valA;
        } else {
            $valA = isset($a[$column]) ? $a[$column] : '';
            $valB = isset($b[$column]) ? $b[$column] : '';
            if ($order === 'asc') {
                return strcmp(strtoupper($valA), strtoupper($valB));
            } else {
                return strcmp(strtoupper($valB), strtoupper($valA));
            }
        }
    });
    return $songs;
}

// Ordena a lista com base nos parâmetros da URL
$sortColumn = isset($_GET['sort']) ? $_GET['sort'] : 'index';
$sortOrder = isset($_GET['order']) ? $_GET['order'] : 'asc';
$songs = sortSongs($songs, $sortColumn, $sortOrder);

// Salva a nova ordem das músicas no arquivo JSON se a requisição for POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['order'])) {
    $newOrder = json_decode($_POST['order'], true);
    if (is_array($newOrder)) {
        $sortedSongs = array();
        foreach ($newOrder as $index) {
            if (isset($songs[$index])) {
                $sortedSongs[] = $songs[$index];
            }
        }
        writeJson($jsonFile, $sortedSongs);
        $songs = $sortedSongs;

        // Atualiza a ordem no Spotify
        $playlistId = '4pcomesNQA6DPXj1HFpOjf'; // ID da playlist
        $accessToken = getAccessToken();
        $trackUris = array_map(function($song) {
            return $song['uri']; // Supondo que cada música tem um campo 'uri' com o URI da faixa no Spotify
        }, $sortedSongs);
        updatePlaylistOrder($accessToken, $playlistId, $trackUris);
    }
}

// Função para escrever no JSON
function writeJson($file, $data) {
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marcvs Marcal - Lista de Músicas</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #e8f5e9;
            margin: 0;
            padding: 0;
        }
        header {
            background: #4caf50;
            color: #fff;
            padding: 20px 0;
            text-align: center;
        }
        .container {
            width: 80%;
            margin: auto;
            overflow: hidden;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
            background: #fff;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #ddd;
            color: #111;
        }
        th {
            background-color: #388e3c;
            color: white;
            cursor: pointer;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        th:hover {
            background-color: #4caf50;
        }
        a {
            color: white;
            text-decoration: none;
        }
        a:hover {
            text-decoration: underline;
        }
        .button {
            padding: 10px 20px;
            color: white;
            background-color: #4caf50;
            border: none;
            border-radius: 5px;
            text-decoration: none;
            display: inline-block;
            margin: 5px;
        }
        .button:hover {
            background-color: #388e3c;
        }
        .delete-button {
            color: #f44336;
            background-color: transparent;
            border: none;
            cursor: pointer;
        }
        .delete-button:hover {
            text-decoration: underline;
        }
        tbody tr:nth-child(even) {
            background-color: #f2f2f2;
        }
        tbody tr:hover {
            background-color: #d9fbe1;
        }
        .sticky-header th {
            position: sticky;
            top: 0;
            background: #4caf50;
            color: white;
            z-index: 2;
        }
    </style>
</head>
<body>
    <header>
        <h1>Marcvs Marcal - Lista de Músicas</h1>
    </header>

    <div class="container">
        <a href="index.php" class="button">Home</a>    
        <a href="add.php" class="button">Adicionar Música</a>
        <a href="import.php" class="button">Importar Músicas</a>
        <a href="print_songs.php" class="button">Imprimir</a>
        <a href="song_list.php" class="button">Lista de Músicas</a>
        

        <table id="songsTable">
            <thead>
                <tr>
                    <th>
                        <a href="?sort=index&order=<?php echo $sortColumn === 'index' && $sortOrder === 'desc' ? 'asc' : 'asc'; ?>">#</a>
                    </th>
                    <th>
                        <a href="?sort=title&order=<?php echo $sortColumn === 'title' && $sortOrder === 'desc' ? 'asc' : 'asc'; ?>">Título</a>
                    </th>
                    <th>
                        <a href="?sort=artist&order=<?php echo $sortColumn === 'artist' && $sortOrder === 'desc' ? 'asc' : 'asc'; ?>">Artista</a>
                    </th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody id="sortable">
                <?php foreach ($songs as $index => $song): ?>
                <tr data-index="<?php echo htmlspecialchars($index); ?>">
                    <td><?php echo str_pad($index + 1, 2, '0', STR_PAD_LEFT); ?></td>
                    <td><?php echo htmlspecialchars($song['title']); ?></td>
                    <td><?php echo htmlspecialchars($song['artist']); ?></td>
                    <td>
                        <a href="edit.php?index=<?php echo urlencode($index); ?>" class="button">Editar</a>
                        <form action="delete.php" method="POST" style="display:inline;">
                            <input type="hidden" name="index" value="<?php echo htmlspecialchars($index); ?>">
                            <button type="submit" class="delete-button"><i class="fas fa-trash"></i> Excluir</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- jQuery e jQuery UI -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
    <script>
        $(document).ready(function() {
            // Tornar a tabela sortable
            $("#sortable").sortable({
                update: function(event, ui) {
                    var sortedIDs = $("#sortable").sortable("toArray", { attribute: 'data-index' });
                    $.post("index.php", { order: JSON.stringify(sortedIDs) });
                }
            });

            $("#sortable").disableSelection();
        });
    </script>
</body>
</html>
