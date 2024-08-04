<?php
// Lê o conteúdo do arquivo JSON
$jsonFile = 'songs.json';
$jsonData = file_get_contents($jsonFile);
$songs = json_decode($jsonData, true);

// Verifica se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Captura os dados do formulário
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $artist = isset($_POST['artist']) ? trim($_POST['artist']) : '';
    $cifraUrl = isset($_POST['cifra_url']) ? trim($_POST['cifra_url']) : '';

    if ($title && $artist) {
        // Adiciona a nova música à lista
        $newSong = [
            'title' => $title,
            'artist' => $artist,
            'cifra_url' => $cifraUrl ?: 'N/A'  // Se cifra_url não for fornecida, define como 'N/A'
        ];
        $songs[] = $newSong;

        // Salva os dados atualizados no arquivo JSON
        if (file_put_contents($jsonFile, json_encode($songs, JSON_PRETTY_PRINT))) {
            header('Location: index.php');
            exit();
        } else {
            $error = "Erro ao salvar a música.";
        }
    } else {
        $error = "Título e artista são obrigatórios.";
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adicionar Música</title>
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
            width: 50%;
            margin: auto;
            overflow: hidden;
        }
        form {
            background: #fff;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }
        label {
            display: block;
            margin-bottom: 10px;
            font-weight: bold;
        }
        input[type="text"] {
            width: 100%;
            padding: 10px;
            margin-bottom: 20px;
            border: 1px solid #4caf50;
            border-radius: 5px;
        }
        button {
            padding: 10px 20px;
            color: white;
            background-color: #4caf50;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 16px;
        }
        button:hover {
            background-color: #388e3c;
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
        .error {
            color: #f44336;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <header>
        <h1>Adicionar Música</h1>
    </header>

    <div class="container">
        <a href="index.php" class="button">Home</a>    
        <a href="add.php" class="button">Adicionar Música</a>
        <a href="import.php" class="button">Importar Músicas</a>
        <a href="print_songs.php" class="button">Imprimir</a>
        <a href="song_list.php" class="button">Lista de Músicas</a>
    
    <form action="" method="POST">
            <?php if (isset($error)): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <label for="title">Título:</label>
            <input type="text" id="title" name="title" required>

            <label for="artist">Artista:</label>
            <input type="text" id="artist" name="artist" required>

            <label for="cifra_url">URL da Cifra:</label>
            <input type="text" id="cifra_url" name="cifra_url">

            <button type="submit">Adicionar Música</button>
            <a href="index.php" class="button">Voltar</a>
        </form>
    </div>

    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Exemplo de uso do jQuery para validação ou efeitos adicionais, se necessário
        });
    </script>
</body>
</html>
