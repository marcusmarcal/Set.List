<?php
// Lê o conteúdo do arquivo JSON
$jsonFile = 'songs.json';
$jsonData = file_get_contents($jsonFile);
$songs = json_decode($jsonData, true);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Imprimir Lista de Músicas</title>
    <!-- Link para jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f0f9f4;
            color: #333;
            margin: 0;
            padding: 10px;
        }
        h1 {
            text-align: center;
            color: #2e7d32;
            margin-bottom: 10px;
            font-size: 1.5em;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
            background-color: #ffffff;
            font-size: 0.8em; /* Reduzindo o tamanho da fonte */
        }
        th, td {
            padding: 5px; /* Reduzindo o espaçamento */
            border: 1px solid #ddd;
            text-align: left;
        }
        th {
            background-color: #66bb6a;
            color: #fff;
        }
        tbody tr:nth-child(even) {
            background-color: #e8f5e9;
        }
        tbody tr:hover {
            background-color: #c8e6c9;
        }
        @media print {
            body {
                background-color: #ffffff;
                color: #000000;
                padding: 0;
                margin: 0;
            }
            th, td {
                color: #000000;
                border: 1px solid #333;
            }
            th {
                background-color: #a5d6a7;
            }
            table {
                page-break-inside: avoid;
                width: 100%;
            }
            h1 {
                font-size: 1.2em;
                margin: 0;
            }
        }
    </style>
</head>
<body>
    <h1>Lista de Músicas para Impressão</h1>
    <table>
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

    <!-- JavaScript para abrir a janela de impressão automaticamente -->
    <script>
        $(document).ready(function() {
            // Abre a janela de impressão quando a página estiver carregada
            window.print();
        });
    </script>
</body>
</html>
