# SetList V2

Sistema web (PHP + JS, single-file) para organizar repertório musical: cadastro de músicas, listas/setlists e eventos com tags, transposição de tom, links de cifra, integração com o Spotify (busca, relacionar faixas, exportar listas como playlists) e impressão de setlists prontos para o palco.

Feito para bandas, músicos solo e ministérios de louvor que precisam manter um repertório organizado e compartilhável.

---

## ✨ Funcionalidades

- **Banco de músicas único** (título, artista, tom, BPM, ritmo, capotraste, notas, link de cifra, link/URI do Spotify), sem duplicar dados entre listas.
- **Listas e eventos como tags** — a mesma música pode pertencer a várias listas/eventos ao mesmo tempo.
- **Transposição de tom** (cromática, com suporte a acordes maiores/menores).
- **Importação**:
  - Colar texto simples (`Título - Artista`, um por linha, com deteção automática de duplicados);
  - Importar um `.json` exportado anteriormente do próprio sistema;
  - Importar a partir de arquivos originais (`playlists.json` + `songs*.json`), modo legado, somente leitura.
- **Integração com Spotify**:
  - Login do administrador via OAuth 2.0 + PKCE (não usa client secret no navegador);
  - Busca automática e manual de faixas para relacionar cada música do repertório à faixa correta no Spotify;
  - Exportar uma lista/tag inteira como playlist do Spotify.
- **Página pública de eventos** (somente leitura, sem necessidade de login) para compartilhar o repertório de um evento específico.
- **Impressão** de setlists formatadas para uso ao vivo.
- **Login único de administrador** com proteção contra força bruta (bloqueio temporário por IP após tentativas erradas).
- Banco de dados em **arquivo JSON local** — não precisa de MySQL/servidor de banco de dados.

---

## 🧱 Requisitos

- Servidor com **PHP 8.0+** (usa `fn()`, `match`, null-safe chaining etc.)
- Extensão **cURL** habilitada no PHP (necessária para a integração com o Spotify)
- Permissão de **escrita** na pasta do projeto (o sistema cria/atualiza arquivos `.json` locais)
- Um app criado no [Spotify Developer Dashboard](https://developer.spotify.com/dashboard) *(opcional — só necessário se quiser usar a integração com o Spotify)*

> ⚠️ **Hospedagem gratuita (ex: InfinityFree e similares):** algumas hospedagens gratuitas bloqueiam conexões cURL de saída para APIs externas. Se a busca do Spotify não retornar nada e o erro mostrar falha de conexão, o problema é da hospedagem, não do código — nesse caso é necessário migrar para uma hospedagem que permita conexões externas.

---

## 🚀 Instalação

1. **Baixe os arquivos** deste repositório para o seu servidor (via FTP, painel de hospedagem, git clone, etc.). Tudo roda a partir de um único `index.php`.

2. **Crie o arquivo `.env`** na mesma pasta do `index.php`, com o seguinte conteúdo mínimo:

   ```env
   # Senha de acesso ao painel administrativo (obrigatório)
   ADMIN_PWD=troque-por-uma-senha-forte

   # Credenciais do app Spotify (opcional — só para usar a integração)
   CLIENT_ID=seu_client_id_do_spotify
   CLIENT_SECRET=seu_client_secret_do_spotify

   # URL pública do site, sem barra final (opcional — se não definir, o sistema detecta automaticamente)
   SPOTIFY_REDIRECT_URI=https://seusite.com
   ```

   > O arquivo `.env` **nunca** deve ser commitado no repositório público (adicione-o ao `.gitignore`).

3. **Garanta permissão de escrita** na pasta onde está o `index.php` (o sistema cria automaticamente `setlist_v2_db.json` e `.login_attempts.json` na primeira execução).

4. **Acesse o site** pelo navegador (ex: `https://seusite.com/`) e faça login com a senha definida em `ADMIN_PWD`.

Pronto — o sistema está funcionando. A integração com o Spotify é opcional; sem ela, tudo funciona normalmente, só ficam indisponíveis a busca/exportação de faixas.

---

## 🎵 Configurando a integração com o Spotify (opcional)

1. Acesse o [Spotify Developer Dashboard](https://developer.spotify.com/dashboard) e crie um app.
2. Em **Settings**, adicione como **Redirect URI** exatamente:
   ```
   https://seusite.com/?spotify_callback=1
   ```
   (troque `https://seusite.com` pela URL real do seu site — tem que bater com o domínio configurado em `SPOTIFY_REDIRECT_URI` no `.env`, ou com o domínio detectado automaticamente).
3. Copie o **Client ID** e o **Client Secret** gerados e coloque no `.env` (`CLIENT_ID` e `CLIENT_SECRET`).
4. **Enquanto o app estiver em modo de desenvolvimento** (padrão do Spotify para apps novos), só contas explicitamente adicionadas funcionam:
   - No Dashboard do app, vá em **Settings → User Management**;
   - Adicione o nome e e-mail exato de cada conta Spotify que vai usar o sistema (até 25 contas no modo dev);
   - Contas fora dessa lista recebem o erro `403 - The user is not registered for this application` ao tentar buscar/exportar.
   - Para liberar para qualquer conta Spotify, é preciso solicitar o **modo de produção (Extended Quota Mode)** ao Spotify pelo próprio Dashboard.
5. No site, entre em **Exportar para Spotify** e clique em **Autorizar com Spotify** — você será redirecionado para logar com a conta Spotify e conceder permissão de gerenciar playlists.

---

## 📖 Como usar

- **Adicionar música**: botão "Adicionar Música", preenche título, artista, tom, cifra, etc.
- **Criar uma lista/setlist ou evento**: crie uma tag do tipo "Lista/Setlist" ou "Evento" e marque as músicas que pertencem a ela.
- **Importar em massa**: cole uma lista de músicas em texto simples (`Título - Artista`, uma por linha) na tela de importação.
- **Relacionar com o Spotify**: nas músicas sem faixa do Spotify vinculada, use "Buscar no Spotify" para localizar e escolher a faixa correta manualmente.
- **Exportar uma lista como playlist do Spotify**: dentro da lista, use a opção de exportar — cria (ou atualiza) uma playlist na sua conta Spotify com as músicas daquela lista.
- **Compartilhar um evento publicamente**: eventos (tags do tipo "Evento") ficam acessíveis via API pública (`?_action=public_events`), sem exigir login — útil para montar uma página pública ou app de consulta do repertório do dia.
- **Imprimir**: dentro de uma lista, use a opção de imprimir para gerar uma versão formatada para levar ao palco.

---

## 🔒 Segurança

- Login único por senha de administrador (`ADMIN_PWD`), com bloqueio temporário por IP após várias tentativas erradas.
- Cookies de sessão com `HttpOnly`, `SameSite=Lax` e `Secure` automático quando o site está em HTTPS.
- A integração com o Spotify usa **PKCE** (Proof Key for Code Exchange): o `CLIENT_SECRET` fica só no servidor e nunca é exposto ao navegador.
- **Nunca** exponha o arquivo `.env` publicamente — confirme que sua hospedagem não serve arquivos `.env` diretamente pela URL (a maioria dos servidores PHP já bloqueia isso por padrão, mas vale testar acessando `https://seusite.com/.env` — deve dar erro).

---

## 🗂️ Estrutura de dados

O sistema guarda tudo em `setlist_v2_db.json`, na mesma pasta do `index.php`, com esta estrutura geral:

```json
{
  "version": 2,
  "songs": {
    "uuid-da-musica": {
      "title": "...", "artist": "...", "key": "...", "bpm": "...",
      "rhythm": "...", "capo": "...", "notes": "...",
      "cifra_url": "...", "spotify_url": "...", "spotify_uri": "...",
      "tags": ["id-da-tag-1", "id-da-tag-2"]
    }
  },
  "tags": {
    "id-da-tag": { "id": "...", "name": "...", "type": "list|event", "..." : "..." }
  },
  "settings": { }
}
```

Esse arquivo pode ser feito backup manualmente (é só copiar) e reimportado depois via **Importar JSON**, no próprio sistema.

---

## 🛠️ Solução de problemas

| Sintoma | Causa provável |
|---|---|
| "ADMIN_PWD não configurada no .env" | Falta o arquivo `.env` ou a variável `ADMIN_PWD` nele |
| Busca do Spotify não retorna nada / erro de conexão | Hospedagem bloqueando cURL de saída, ou `CLIENT_ID`/`CLIENT_SECRET` ausentes/errados no `.env` |
| `403 - The user is not registered for this application` | A conta Spotify usada não está na lista de utilizadores permitidos do app (modo desenvolvimento) — veja a seção acima |
| Página em branco / erro 500 | Verifique se a pasta tem permissão de escrita e se a extensão cURL está habilitada no PHP |

---

## 📄 Licença

MIT
