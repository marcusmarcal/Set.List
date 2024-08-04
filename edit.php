<?php
// Lê o conteúdo do arquivo JSON
$jsonFile = 'songs.json';
$jsonData = file_get_contents($jsonFile);
$songs = json_decode($jsonData, true);

// Verifica se um índice foi passado na URL
if (isset($_GET['index'])) {
    $index = intval($_GET['index']);
    if (isset($songs[$index])) {
        $song = $songs[$index];
    } else {
        die("Índice inválido.");
    }
} else {
    die("Índice não fornecido.");
}

// Processa o formulário de edição
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = isset($_POST['title']) ? trim($_POST['title']) : '';
    $artist = isset($_POST['artist']) ? trim($_POST['artist']) : '';

    // Valida os dados
    if ($title === '' || $artist === '') {
        $error = "Título e artista são obrigatórios.";
    } else {
        // Atualiza a música no array
        $songs[$index]['title'] = $title;
        $songs[$index]['artist'] = $artist;

        // Grava as mudanças no arquivo JSON
        file_put_contents($jsonFile, json_encode($songs, JSON_PRETTY_PRINT));

        // Redireciona para a página principal
        header('Location: index.php');
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Música</title>
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
            background: #fff;
            border-radius: 5px;
            padding: 20px;
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
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        .button {
            padding: 10px 20px;
            color: white;
            background-color: #4caf50;
            border: none;
            border-radius: 5px;
            text-decoration: none;
            display: inline-block;
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
        <h1>Editar Música</h1>
    </header>

    <div class="container">
        <?php if (isset($error)): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form action="edit.php?index=<?php echo htmlspecialchars($index); ?>" method="POST">
            <label for="title">Título:</label>
            <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($song['title']); ?>" required>

            <label for="artist">Artista:</label>
            <input type="text" id="artist" name="artist" value="<?php echo htmlspecialchars($song['artist']); ?>" required>

            <button type="submit" class="button">Salvar</button>
        </form>
    </div>
</body>
</html>
