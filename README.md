# SetList — Sistema de Gestão de Músicas

Sistema PHP redesenhado com tema escuro editorial, suporte a múltiplas playlists do Spotify e gestão completa de repertório.

## Ficheiros

| Ficheiro | Função |
|---|---|
| `_helpers.php` | Funções partilhadas, renderização do sidebar, gestão de playlists |
| `style.css` | CSS global (dark theme) |
| `index.php` | Lista de músicas com drag-and-drop e pesquisa em tempo real |
| `add.php` | Adicionar música manualmente |
| `edit.php` | Editar música existente |
| `delete.php` | Eliminar música |
| `import.php` | Importar do Spotify (substituir ou merge) |
| `playlists.php` | Gerir listas do Spotify (criar, editar, definir padrão, eliminar) |
| `print_songs.php` | Versão para impressão |
| `playlists.json` | Config das listas registadas |
| `songs_<id>.json` | Músicas de cada lista (ex: `songs_principal.json`) |

## Múltiplas Playlists

Cada playlist tem:
- **Nome** — para exibição na sidebar
- **ID Spotify** — o ID de uma playlist pública no Spotify
- **Lista padrão** — a que abre por defeito

Para obter o ID de uma playlist:
1. Abrir no Spotify → ··· → Partilhar → Copiar link
2. O ID é a parte final: `open.spotify.com/playlist/**ID_AQUI**`

## Configuração Spotify (.env)

```
CLIENT_ID=seu_client_id
CLIENT_SECRET=seu_client_secret
```

Criar app em: https://developer.spotify.com/dashboard

## Deploy

1. Copiar todos os ficheiros para o servidor
2. Garantir que `playlists.json` e `songs_*.json` têm permissão de escrita
3. Instalar dependências: `composer install`
4. Criar `.env` com credenciais Spotify (opcional, só para importação)
5. Aceder a `index.php`
