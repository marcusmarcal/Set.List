<?php
// Lê o conteúdo do arquivo JSON
$jsonFile = 'songs.json';
$jsonData = file_get_contents($jsonFile);
$songs = json_decode($jsonData, true);

// Função para formatar URL para Cifra Club (se necessário)
function formatCifraUrl($url) {
    if (!empty($url) && !preg_match('/^https?:\/\/(www\.)?cifraclub\.com\.br/', $url)) {
        return 'https://www.cifraclub.com.br/' . $url;
    }
    return $url;
}

// Ordena a lista com base na coluna e ordem (opcional)
$sortColumn = isset($_GET['sort']) ? $_GET['sort'] : 'index';
$sortOrder = isset($_GET['order']) ? $_GET['order'] : 'asc';
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
$songs = sortSongs($songs, $sortColumn, $sortOrder);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de Músicas</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #18242c; /* Cor de fundo da página */
            margin: 0;
            padding: 0;
            color: white; /* Cor do texto */
        }
        header {
            background: #404044; /* Cor de fundo do cabeçalho */
            color: white; /* Cor do texto no cabeçalho */
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
            background: #404044; /* Cor de fundo da tabela */
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #a1897b; /* Cor da borda das células */
            color: #a1897b; /* Cor do texto das células */
        }
        th {
            background-color: #18242c; /* Cor de fundo dos cabeçalhos da tabela */
            color: #a1897b; /* Cor do texto dos cabeçalhos */
            cursor: pointer;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        th:hover {
            background-color: #404044; /* Cor de fundo do cabeçalho da tabela ao passar o mouse */
        }
        tbody tr:nth-child(even) {
            background-color: #404044; /* Cor de fundo das linhas pares */
        }
        tbody tr:hover {
            background-color: #18242c; /* Cor de fundo das linhas ao passar o mouse */
        }
    </style>
</head>
<body>
    <header>
        <h1>Lista de Músicas</h1>
    </header>

    <div class="container">
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
                </tr>
            </thead>
            <tbody>
                <?php foreach ($songs as $index => $song): ?>
                <tr>
                    <td><?php echo str_pad($index + 1, 2, '0', STR_PAD_LEFT); ?></td>
                    <td><?php echo htmlspecialchars($song['title']); ?></td>
                    <td><?php echo htmlspecialchars($song['artist']); ?></td>
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
            // Adiciona funcionalidade se necessário (opcional)
        });
    </script>
</body>
</html>
