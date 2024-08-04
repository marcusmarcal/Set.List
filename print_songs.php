<?php
// Lê o conteúdo do arquivo JSON
$jsonFile = 'songs.json';
$jsonData = file_get_contents($jsonFile);
$songs = json_decode($jsonData, true);

// Função para formatar URL para Cifra Club (opcional)
function formatCifraUrl($url) {
    if (!empty($url) && !preg_match('/^https?:\/\/(www\.)?cifraclub\.com\.br/', $url)) {
        return 'https://www.cifraclub.com.br/' . $url;
    }
    return $url;
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lista de Músicas</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
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
            color: #fff;
        }
        tbody tr:nth-child(even) {
            background-color: #f2f2f2;
        }
        tbody tr:hover {
            background-color: #d9fbe1;
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
                    <th>#</th>
                    <th>Título</th>
                    <th>Artista</th>
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
</body>
</html>
