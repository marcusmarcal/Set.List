<?php
// ════════════════════════════════════════════════════════════════
//  SetList V2 — sistema baseado em tags
//  Músicas únicas com UUID, listas como tags, filtros avançados.
//  NÃO modifica os JSONs originais — importa na memória apenas.
// ════════════════════════════════════════════════════════════════

if (session_status() === PHP_SESSION_NONE) session_start();

// ── .env loader ─────────────────────────────────────────────────
function envVal($key) {
    static $c = null;
    if ($c === null) {
        $c = [];
        $f = __DIR__ . '/.env';
        if (file_exists($f)) {
            foreach (file($f) as $line) {
                $line = trim($line);
                if (!$line || $line[0] === '#') continue;
                [$k,$v] = array_pad(explode('=', $line, 2), 2, '');
                $c[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
            }
        }
    }
    return $c[$key] ?? null;
}

// ── Auth ─────────────────────────────────────────────────────────
function adminPwd()  {
    return envVal('APP_PASS') ?: envVal('ADMIN_PASSWORD') ?: envVal('ADMIN_PASS') ?: envVal('PASSWORD') ?: '';
}
function isLocked()  { return adminPwd() !== ''; }
function isAuthed()  {
    if(!isLocked()) return true;
    return ($_SESSION['authed'] ?? '') === adminPwd();
}

// ── Spotify redirect URI ─────────────────────────────────────────
function spotifyRedirectUri() {
    // Configurável via .env como SPOTIFY_REDIRECT_URI (recomendado)
    // Fallback: detecta automaticamente
    $env = envVal('SPOTIFY_REDIRECT_URI');
    if($env) return rtrim($env, '/') . '/?spotify_callback=1';
    $scheme = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'];
    // Usa sempre a raiz do domínio (sem index.php)
    return $scheme . '://' . $host . '/?spotify_callback=1';
}

function jsonOut($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function needAuth() {
    if(!isAuthed()) jsonOut(['ok'=>false,'error'=>'Não autorizado.']);
}

// ── UUID ─────────────────────────────────────────────────────────
function newUuid() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

// ── DB FILE (novo, separado dos originais) ───────────────────────
$DB_FILE = __DIR__ . '/setlist_v2_db.json';

function loadDb() {
    global $DB_FILE;
    if (!file_exists($DB_FILE)) return emptyDb();
    $raw = file_get_contents($DB_FILE);
    $d   = json_decode($raw, true);
    return is_array($d) ? $d : emptyDb();
}

function emptyDb() {
    return [
        'version'  => 2,
        'songs'    => [],   // uuid => song
        'tags'     => [],   // tag_id => {id, name, type, spotify_id?}
        'settings' => ['rhythms' => [], 'keys' => ['C','C#','D','D#','E','F','F#','G','G#','A','A#','B',
                                                     'Cm','C#m','Dm','D#m','Em','Fm','F#m','Gm','G#m','Am','A#m','Bm']],
    ];
}

function saveDb($db) {
    global $DB_FILE;
    file_put_contents($DB_FILE, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// ── Slug helper ──────────────────────────────────────────────────
function toSlug($s) {
    $s = mb_strtolower($s, 'UTF-8');
    $map = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','ê'=>'e','ë'=>'e','è'=>'e',
            'í'=>'i','î'=>'i','ï'=>'i','ì'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ò'=>'o',
            'ú'=>'u','û'=>'u','ü'=>'u','ù'=>'u','ç'=>'c','ñ'=>'n','ý'=>'y'];
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9\s-]/', '', $s);
    return trim(preg_replace('/[\s_-]+/', '-', $s), '-');
}

// ── Import dos JSONs originais (somente leitura) ─────────────────
function importOriginalJsons() {
    // Carrega playlists.json original (read-only)
    $plFile = __DIR__ . '/playlists.json';
    if (!file_exists($plFile)) return null;
    $playlists = json_decode(file_get_contents($plFile), true) ?: [];

    $db = emptyDb();
    $tagsByPlId = [];

    // Cria uma tag para cada playlist original
    foreach ($playlists as $pl) {
        $tagId = newUuid();
        $tag = [
            'id'         => $tagId,
            'name'       => $pl['name'] ?? 'Lista',
            'type'       => 'list',
            'spotify_id' => $pl['spotify_id'] ?? '',
            'orig_id'    => $pl['id'] ?? '',
            'color'      => '',
        ];
        $db['tags'][$tagId] = $tag;
        $tagsByPlId[$pl['id']] = $tagId;
    }

    // Para cada playlist, carrega as músicas e faz merge global (dedup por título+artista)
    $songKeyToUuid = []; // normalised key => uuid

    foreach ($playlists as $pl) {
        $plId = $pl['id'] ?? '';
        $tagId = $tagsByPlId[$plId] ?? null;

        // Descobre o arquivo de músicas correspondente
        $safeId = preg_match('/^[a-z0-9_\-]+$/i', $plId) ? $plId : preg_replace('/[^a-z0-9_\-]/i', '', $plId);
        $songFile = __DIR__ . "/songs_{$safeId}.json";
        if (!file_exists($songFile) && !empty($pl['is_default'])) {
            $songFile = __DIR__ . '/songs.json';
        }
        if (!file_exists($songFile)) continue;

        $songs = json_decode(file_get_contents($songFile), true) ?: [];
        foreach ($songs as $s) {
            $title  = trim($s['title'] ?? '');
            $artist = trim($s['artist'] ?? '');
            if (!$title) continue;

            $normKey = strtolower(preg_replace('/[^a-z0-9]/i', '', $title . '||' . $artist));

            if (isset($songKeyToUuid[$normKey])) {
                // Música já existe — só adiciona tag
                $uuid = $songKeyToUuid[$normKey];
                if ($tagId && !in_array($tagId, $db['songs'][$uuid]['tags'] ?? [])) {
                    $db['songs'][$uuid]['tags'][] = $tagId;
                }
            } else {
                // Nova música
                $uuid = $s['id'] ?? newUuid();
                // Garante que uuid seja realmente único
                while (isset($db['songs'][$uuid])) $uuid = newUuid();

                $newSong = [
                    'id'           => $uuid,
                    'title'        => $title,
                    'artist'       => $artist,
                    'cifra_url'    => $s['cifra_url'] ?? 'N/A',
                    'cifra_source' => $s['cifra_source'] ?? 'cifraclub',
                    'spotify_url'  => $s['spotify_url'] ?? '',
                    'spotify_uri'  => $s['spotify_uri'] ?? '',
                    'duration_ms'  => (int)($s['duration_ms'] ?? 0),
                    'tags'         => $tagId ? [$tagId] : [],
                    'key'          => '',
                    'bpm'          => '',
                    'rhythm'       => '',
                    'notes'        => '',
                ];
                $db['songs'][$uuid] = $newSong;
                $songKeyToUuid[$normKey] = $uuid;
            }
        }
    }

    return $db;
}

// ── Spotify helpers ──────────────────────────────────────────────
function spotCreds() {
    $id  = envVal('CLIENT_ID') ?: envVal('SPOTIPY_CLIENT_ID') ?: getenv('CLIENT_ID') ?: '';
    $sec = envVal('CLIENT_SECRET') ?: envVal('SPOTIPY_CLIENT_SECRET') ?: getenv('CLIENT_SECRET') ?: '';
    return [$id, $sec];
}
function hasSpotCreds() { [$i,$s]=spotCreds(); return $i&&$s; }
function spotToken() {
    [$id,$sec] = spotCreds();
    if (!$id||!$sec) return null;
    $ch = curl_init('https://accounts.spotify.com/api/token');
    curl_setopt_array($ch,[
        CURLOPT_HTTPHEADER     => ['Authorization: Basic '.base64_encode("$id:$sec"),'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_TIMEOUT => 15,
    ]);
    $r = json_decode(curl_exec($ch),true); curl_close($ch);
    return $r['access_token'] ?? null;
}
function fmtMs($ms){
    if(!$ms) return '';
    $totalSec=(int)round($ms/1000);
    $m=intdiv($totalSec,60); $s=$totalSec%60;
    return $m.':'.str_pad($s,2,'0',STR_PAD_LEFT);
}

// ── Login / Logout ───────────────────────────────────────────────
if(($_POST['_action']??'')==='_login'){
    $pwd = trim($_POST['password']??'');
    if($pwd === adminPwd()){ $_SESSION['authed'] = adminPwd(); jsonOut(['ok'=>true]); }
    else jsonOut(['ok'=>false,'error'=>'Senha incorrecta.']);
}
if(isset($_GET['logout'])){ unset($_SESSION['authed']); header('Location: '.$_SERVER['PHP_SELF']); exit; }

// ── Spotify OAuth PKCE callback (popup) ──────────────────────────
if(isset($_GET['spotify_callback'])){
    $code  = trim($_GET['code']  ?? '');
    $error = trim($_GET['error'] ?? '');
    [$clientId,] = spotCreds();
    $redirectUri = spotifyRedirectUri();
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Spotify Auth</title></head>'
        .'<body style="background:#111;color:#fff;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh;margin:0">'
        .'<div id="msg" style="text-align:center;font-size:.9rem;color:#aaa">A processar…</div><script>'
        .'(function(){'
        .'var code='.json_encode($code).';'
        .'var err='.json_encode($error).';'
        .'var clientId='.json_encode($clientId).';'
        .'var redirectUri='.json_encode($redirectUri).';'
        .'if(err||!code){'
        .'  document.getElementById("msg").textContent="Erro: "+(err||"código não recebido");'
        .'  if(window.opener)window.opener.spotifyAuthError(err||"no_code");'
        .'  return;'
        .'}'
        // Troca code por token usando PKCE (sem client_secret) directamente do JS
        .'var verifier=sessionStorage.getItem("spotify_pkce_verifier");'
        .'fetch("https://accounts.spotify.com/api/token",{'
        .'  method:"POST",'
        .'  headers:{"Content-Type":"application/x-www-form-urlencoded"},'
        .'  body:new URLSearchParams({grant_type:"authorization_code",code:code,redirect_uri:redirectUri,client_id:clientId,code_verifier:verifier})'
        .'}).then(r=>r.json()).then(data=>{'
        .'  if(data.access_token){'
        .'    sessionStorage.setItem("spotify_access_token",data.access_token);'
        .'    document.getElementById("msg").textContent="Autorizado! A fechar…";'
        .'    if(window.opener&&!window.opener.closed){'
        .'      window.opener.extractSpotifyToken(data.access_token);'
        .'      setTimeout(()=>window.close(),600);'
        .'    }'
        .'  } else {'
        .'    var e=data.error_description||data.error||"Falhou";'
        .'    document.getElementById("msg").textContent="Erro: "+e;'
        .'    if(window.opener)window.opener.spotifyAuthError(e);'
        .'  }'
        .'}).catch(e=>{'
        .'  document.getElementById("msg").textContent="Erro de rede: "+e;'
        .'  if(window.opener)window.opener.spotifyAuthError(String(e));'
        .'});'
        .'})();'
        .'</script></body></html>';
    exit;
}

// ── AJAX Actions ────────────────────────────────────────────────
if($_SERVER['REQUEST_METHOD']==='POST'){
    $act = $_POST['_action'] ?? '';

    // ── Import dos JSONs originais → novo DB ──
    if($act==='import_from_originals'){
        needAuth();
        $db = importOriginalJsons();
        if(!$db) jsonOut(['ok'=>false,'error'=>'playlists.json não encontrado.']);
        // Preserva ritmos/configurações existentes se já houver DB
        $existing = loadDb();
        if(!empty($existing['settings']['rhythms'])) $db['settings']['rhythms'] = $existing['settings']['rhythms'];
        saveDb($db);
        jsonOut(['ok'=>true,'songs'=>count($db['songs']),'tags'=>count($db['tags'])]);
    }

    // ── Buscar duração de track Spotify ──
    if($act==='fetch_track_duration'){
        if(!hasSpotCreds()) jsonOut(['ok'=>false,'error'=>'Sem credenciais Spotify.']);
        $url = trim($_POST['spotify_url']??'');
        // Extrair track ID
        if(preg_match('#track/([A-Za-z0-9]+)#', $url, $m)) $trackId = $m[1];
        elseif(preg_match('#spotify:track:([A-Za-z0-9]+)#', $url, $m)) $trackId = $m[1];
        else jsonOut(['ok'=>false,'error'=>'URL de track inválido.']);
        $token = spotToken();
        if(!$token) jsonOut(['ok'=>false,'error'=>'Token Spotify inválido.']);
        $ch = curl_init("https://api.spotify.com/v1/tracks/{$trackId}?fields=duration_ms,uri,external_urls");
        curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>["Authorization: Bearer $token"],'CURLOPT_RETURNTRANSFER'=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_TIMEOUT=>10]);
        $r = json_decode(curl_exec($ch),true); curl_close($ch);
        if(empty($r['duration_ms'])) jsonOut(['ok'=>false,'error'=>'Track não encontrada.']);
        jsonOut(['ok'=>true,'duration_ms'=>(int)$r['duration_ms'],'spotify_uri'=>$r['uri']??'']);
    }

    // ── Exportar tag como playlist Spotify ──
    if($act==='export_tag_to_spotify'){
        needAuth();
        if(!hasSpotCreds()) jsonOut(['ok'=>false,'error'=>'Credenciais Spotify não configuradas.']);
        $db    = loadDb();
        $tid   = trim($_POST['tag_id']??'');
        $token = trim($_POST['user_token']??''); // OAuth user token vindo do JS (PKCE)
        $tag   = $db['tags'][$tid] ?? null;
        if(!$tag) jsonOut(['ok'=>false,'error'=>'Tag não encontrada.']);
        if(!$token) jsonOut(['ok'=>false,'error'=>'Token de utilizador em falta.']);

        // Músicas da tag com spotify_uri
        $order = $tag['song_order'] ?? array_keys($db['songs']);
        $uris  = [];
        foreach($order as $sid){
            $s = $db['songs'][$sid] ?? null;
            if(!$s || !(in_array($tid,$s['tags']??[]))) continue;
            $uri = $s['spotify_uri'] ?? '';
            if(!$uri && ($s['spotify_url']??'') && preg_match('#track/([A-Za-z0-9]+)#',$s['spotify_url'],$m))
                $uri = 'spotify:track:'.$m[1];
            if($uri) $uris[] = $uri;
        }
        if(!$uris) jsonOut(['ok'=>false,'error'=>'Nenhuma música desta tag tem link Spotify.']);

        // Obter user ID
        $ch = curl_init('https://api.spotify.com/v1/me');
        curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>["Authorization: Bearer $token"],CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_TIMEOUT=>10]);
        $me = json_decode(curl_exec($ch),true); curl_close($ch);
        if(empty($me['id'])) jsonOut(['ok'=>false,'error'=>'Não foi possível obter o utilizador Spotify. Token expirado?']);
        $userId = $me['id'];

        $playlistId = $tag['spotify_id'] ?? '';
        $desc = 'Exportado por SetList V2 em '.date('d/m/Y');
        $name = $tag['name'];

        if($playlistId){
            // Substituir playlist existente:
            // PUT com primeiros 100 (limpa e define), depois POST para o resto
            $chunks = array_chunk($uris, 100);
            $first  = array_shift($chunks); // pode ser vazio se < 100 músicas
            $ch = curl_init("https://api.spotify.com/v1/playlists/{$playlistId}/tracks");
            curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>["Authorization: Bearer $token",'Content-Type: application/json'],
                CURLOPT_CUSTOMREQUEST=>'PUT', CURLOPT_POSTFIELDS=>json_encode(['uris'=>$first]),
                CURLOPT_RETURNTRANSFER=>true, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_TIMEOUT=>15]);
            curl_exec($ch); $code=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
            if($code>=400) jsonOut(['ok'=>false,'error'=>"Erro ao actualizar playlist ($code). Verifica se ainda tens acesso a ela."]);
            // Adicionar chunks restantes
            foreach($chunks as $chunk){
                $ch = curl_init("https://api.spotify.com/v1/playlists/{$playlistId}/tracks");
                curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>["Authorization: Bearer $token",'Content-Type: application/json'],
                    CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode(['uris'=>$chunk]),
                    CURLOPT_RETURNTRANSFER=>true, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_TIMEOUT=>15]);
                curl_exec($ch); curl_close($ch);
            }
        } else {
            // Criar nova playlist vazia, depois adicionar tudo
            $ch = curl_init("https://api.spotify.com/v1/users/{$userId}/playlists");
            curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>["Authorization: Bearer $token",'Content-Type: application/json'],
                CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode(['name'=>$name,'description'=>$desc,'public'=>false]),
                CURLOPT_RETURNTRANSFER=>true, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_TIMEOUT=>15]);
            $pl = json_decode(curl_exec($ch),true); curl_close($ch);
            if(empty($pl['id'])) jsonOut(['ok'=>false,'error'=>'Não foi possível criar a playlist.']);
            $playlistId = $pl['id'];
            foreach(array_chunk($uris, 100) as $chunk){
                $ch = curl_init("https://api.spotify.com/v1/playlists/{$playlistId}/tracks");
                curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>["Authorization: Bearer $token",'Content-Type: application/json'],
                    CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>json_encode(['uris'=>$chunk]),
                    CURLOPT_RETURNTRANSFER=>true, CURLOPT_SSL_VERIFYPEER=>false, CURLOPT_TIMEOUT=>15]);
                curl_exec($ch); curl_close($ch);
            }
        }

        // Guardar spotify_id na tag
        $db['tags'][$tid]['spotify_id'] = $playlistId;
        saveDb($db);
        $playlistUrl = "https://open.spotify.com/playlist/{$playlistId}";
        jsonOut(['ok'=>true,'playlist_id'=>$playlistId,'playlist_url'=>$playlistUrl,'tracks'=>count($uris)]);
    }

    // ── Import de JSON enviado pelo cliente (upload) ──
    if($act==='import_from_json'){
        needAuth();
        $raw = $_POST['json_data'] ?? '';
        if(!$raw) jsonOut(['ok'=>false,'error'=>'Nenhum dado recebido.']);
        $data = json_decode($raw, true);
        if(!is_array($data)||!isset($data['songs'])||!isset($data['tags'])) jsonOut(['ok'=>false,'error'=>'JSON inválido ou estrutura incompatível.']);
        if(!isset($data['version']))  $data['version']  = 2;
        if(!isset($data['settings'])) $data['settings'] = emptyDb()['settings'];
        saveDb($data);
        jsonOut(['ok'=>true,'songs'=>count($data['songs']),'tags'=>count($data['tags'])]);
    }

    // ── CRUD de músicas ──
    if($act==='add_song'){
        needAuth();
        $db = loadDb();
        $title  = trim($_POST['title']??'');
        $artist = trim($_POST['artist']??'');
        if(!$title||!$artist) jsonOut(['ok'=>false,'error'=>'Título e artista obrigatórios.']);
        // Dedup
        $normKey = strtolower(preg_replace('/[^a-z0-9]/i','',$title.'||'.$artist));
        foreach($db['songs'] as $s){
            $k = strtolower(preg_replace('/[^a-z0-9]/i','',$s['title'].'||'.$s['artist']));
            if($k===$normKey) jsonOut(['ok'=>false,'error'=>'Música já existe no catálogo.']);
        }
        $uuid = newUuid();
        $tags = json_decode($_POST['tags']??'[]',true) ?: [];
        $db['songs'][$uuid] = [
            'id'=>$uuid,'title'=>$title,'artist'=>$artist,
            'cifra_url'=>trim($_POST['cifra_url']??'N/A'),
            'cifra_source'=>trim($_POST['cifra_source']??'cifraclub'),
            'spotify_url'=>trim($_POST['spotify_url']??''),
            'spotify_uri'=>trim($_POST['spotify_uri']??''),
            'duration_ms'=>(int)($_POST['duration_ms']??0),
            'tags'=>$tags,'key'=>trim($_POST['key']??''),
            'bpm'=>trim($_POST['bpm']??''),'rhythm'=>trim($_POST['rhythm']??''),
            'notes'=>trim($_POST['notes']??''),
        ];
        saveDb($db);
        jsonOut(['ok'=>true,'id'=>$uuid]);
    }

    if($act==='edit_song'){
        needAuth();
        $db = loadDb();
        $uuid = trim($_POST['song_id']??'');
        if(!isset($db['songs'][$uuid])) jsonOut(['ok'=>false,'error'=>'Música não encontrada.']);
        $s = &$db['songs'][$uuid];
        $fields = ['title','artist','cifra_url','cifra_source','spotify_url','spotify_uri','key','bpm','rhythm','notes'];
        foreach($fields as $f){ if(isset($_POST[$f])) $s[$f] = trim($_POST[$f]); }
        if(isset($_POST['duration_ms'])) $s['duration_ms'] = (int)$_POST['duration_ms'];
        if(isset($_POST['tags'])){
            $tags = json_decode($_POST['tags'],true);
            if(is_array($tags)) $s['tags'] = $tags;
        }
        unset($s);
        saveDb($db);
        jsonOut(['ok'=>true]);
    }

    if($act==='delete_song'){
        needAuth();
        $db = loadDb();
        $uuid = trim($_POST['song_id']??'');
        if(!isset($db['songs'][$uuid])) jsonOut(['ok'=>false,'error'=>'Música não encontrada.']);
        unset($db['songs'][$uuid]);
        saveDb($db);
        jsonOut(['ok'=>true]);
    }

    // ── Toggle tag numa música ──
    if($act==='toggle_tag'){
        needAuth();
        $db   = loadDb();
        $uuid = trim($_POST['song_id']??'');
        $tid  = trim($_POST['tag_id']??'');
        if(!isset($db['songs'][$uuid])||!isset($db['tags'][$tid])) jsonOut(['ok'=>false,'error'=>'Inválido.']);
        $tags = $db['songs'][$uuid]['tags'] ?? [];
        if(in_array($tid,$tags)) $tags = array_values(array_filter($tags,fn($t)=>$t!==$tid));
        else $tags[] = $tid;
        $db['songs'][$uuid]['tags'] = $tags;
        saveDb($db);
        jsonOut(['ok'=>true,'has_tag'=>in_array($tid,$db['songs'][$uuid]['tags'])]);
    }

    // ── Aplica/remove tags em várias músicas de uma vez ──
    if($act==='bulk_tag'){
        needAuth();
        $db       = loadDb();
        $songIds  = json_decode($_POST['song_ids']??'[]', true) ?: [];
        $addIds   = json_decode($_POST['add_tags']??'[]', true) ?: [];
        $removeIds= json_decode($_POST['remove_tags']??'[]', true) ?: [];
        if(!$songIds) jsonOut(['ok'=>false,'error'=>'Nenhuma música selecionada.']);
        $changed = 0;
        foreach($songIds as $sid){
            if(!isset($db['songs'][$sid])) continue;
            $tags = $db['songs'][$sid]['tags'] ?? [];
            foreach($addIds as $tid){
                if(isset($db['tags'][$tid]) && !in_array($tid,$tags)) $tags[] = $tid;
            }
            if($removeIds){
                $tags = array_values(array_filter($tags, fn($t)=>!in_array($t,$removeIds)));
            }
            $db['songs'][$sid]['tags'] = $tags;
            $changed++;
        }
        saveDb($db);
        jsonOut(['ok'=>true,'changed'=>$changed]);
    }

    // ── Junta várias músicas selecionadas numa única entrada ──
    // A primeira da lista (ordem de seleção) fica como base (título/artista/
    // tom/bpm/ritmo/links); as tags de TODAS as selecionadas são unidas.
    if($act==='merge_songs'){
        needAuth();
        $db      = loadDb();
        $songIds = json_decode($_POST['song_ids']??'[]', true) ?: [];
        $songIds = array_values(array_filter($songIds, fn($sid)=>isset($db['songs'][$sid])));
        if(count($songIds) < 2) jsonOut(['ok'=>false,'error'=>'Seleciona pelo menos 2 músicas para juntar.']);

        $baseId = $songIds[0];
        $base   = $db['songs'][$baseId];

        // Junta tags de todas as músicas selecionadas (sem duplicar)
        $mergedTags = $base['tags'] ?? [];
        foreach($songIds as $sid){
            foreach(($db['songs'][$sid]['tags'] ?? []) as $tid){
                if(!in_array($tid,$mergedTags)) $mergedTags[] = $tid;
            }
        }
        $base['tags'] = $mergedTags;
        $db['songs'][$baseId] = $base;

        // Remove as restantes (mantém só a base)
        $removed = 0;
        foreach($songIds as $sid){
            if($sid===$baseId) continue;
            unset($db['songs'][$sid]);
            $removed++;
        }
        saveDb($db);
        jsonOut(['ok'=>true,'base_id'=>$baseId,'removed'=>$removed,'tags_count'=>count($mergedTags)]);
    }

    // ── CRUD de tags ──
    if($act==='add_tag'){
        needAuth();
        $db   = loadDb();
        $name = trim($_POST['name']??'');
        $type = trim($_POST['type']??'custom');
        $spot = trim($_POST['spotify_id']??'');
        if(!$name) jsonOut(['ok'=>false,'error'=>'Nome obrigatório.']);
        $tid = newUuid();
        $db['tags'][$tid] = [
            'id'=>$tid,'name'=>$name,'type'=>$type,'spotify_id'=>$spot,'color'=>trim($_POST['color']??''),
            'event_date'     => trim($_POST['event_date']??''),
            'event_time'     => trim($_POST['event_time']??''),
            'event_location' => trim($_POST['event_location']??''),
        ];
        saveDb($db);
        jsonOut(['ok'=>true,'id'=>$tid,'name'=>$name,'type'=>$type]);
    }

    if($act==='edit_tag'){
        needAuth();
        $db  = loadDb();
        $tid = trim($_POST['tag_id']??'');
        if(!isset($db['tags'][$tid])) jsonOut(['ok'=>false,'error'=>'Tag não encontrada.']);
        foreach(['name','type','spotify_id','color','event_date','event_time','event_location'] as $f){
            if(isset($_POST[$f])) $db['tags'][$tid][$f]=trim($_POST[$f]);
        }
        saveDb($db);
        jsonOut(['ok'=>true]);
    }

    if($act==='delete_tag'){
        needAuth();
        $db  = loadDb();
        $tid = trim($_POST['tag_id']??'');
        if(!isset($db['tags'][$tid])) jsonOut(['ok'=>false,'error'=>'Tag não encontrada.']);
        unset($db['tags'][$tid]);
        // Remove tag de todas as músicas
        foreach($db['songs'] as &$s){
            $s['tags'] = array_values(array_filter($s['tags']??[],fn($t)=>$t!==$tid));
        } unset($s);
        saveDb($db);
        jsonOut(['ok'=>true]);
    }

    // ── Arquivar/desarquivar uma tag ──
    if($act==='toggle_archive_tag'){
        needAuth();
        $db  = loadDb();
        $tid = trim($_POST['tag_id']??'');
        if(!isset($db['tags'][$tid])) jsonOut(['ok'=>false,'error'=>'Tag não encontrada.']);
        $db['tags'][$tid]['archived'] = empty($db['tags'][$tid]['archived']);
        saveDb($db);
        jsonOut(['ok'=>true,'archived'=>$db['tags'][$tid]['archived']]);
    }

    // ── Ritmos personalizados ──
    if($act==='add_rhythm'){
        needAuth();
        $db = loadDb();
        $r  = trim($_POST['rhythm']??'');
        if(!$r) jsonOut(['ok'=>false,'error'=>'Nome obrigatório.']);
        if(!in_array($r,$db['settings']['rhythms']??[])) $db['settings']['rhythms'][] = $r;
        saveDb($db);
        jsonOut(['ok'=>true,'rhythms'=>$db['settings']['rhythms']]);
    }

    // ── Export setlist (impressão/PDF) ──
    if($act==='get_songs_for_print'){
        $db       = loadDb();
        $filterTag = trim($_POST['tag_id']??'');
        $withTags  = !empty($_POST['with_tags']);
        $songs = array_values($db['songs']);
        if($filterTag){
            $songs = array_values(array_filter($songs, fn($s) => in_array($filterTag, $s['tags']??[])));
        }
        // Sort: title
        usort($songs, fn($a,$b) => strcmp($a['title']??'',$b['title']??''));
        // Enrich tags info
        $tags = $db['tags'];
        $out = array_map(function($s) use($tags,$withTags){
            $r = ['id'=>$s['id'],'title'=>$s['title'],'artist'=>$s['artist'],
                  'key'=>$s['key']??'','bpm'=>$s['bpm']??'','rhythm'=>$s['rhythm']??'',
                  'spotify_url'=>$s['spotify_url']??'','duration_ms'=>$s['duration_ms']??0,
                  'notes'=>$s['notes']??''];
            if($withTags){
                $r['tag_names'] = array_values(array_filter(array_map(fn($tid)=>$tags[$tid]['name']??null, $s['tags']??[]),fn($n)=>$n!==null));
            }
            return $r;
        }, $songs);
        jsonOut(['ok'=>true,'songs'=>$out,'total'=>count($out),'tag_name'=>$filterTag?($db['tags'][$filterTag]['name']??''):'Todas as Músicas']);
    }

    // ── Get full DB (para o frontend) ──
    if($act==='get_db'){
        $db = loadDb();
        // Força songs/tags a serem objetos JSON (não arrays), mesmo vazios
        // ou com chaves que o PHP possa reindexar.
        $db['songs'] = (object)($db['songs'] ?: []);
        $db['tags']  = (object)($db['tags'] ?: []);
        jsonOut(['ok'=>true,'db'=>$db]);
    }

    // ── Marcadores de bloco numa tag ─────────────────────────────
    if($act==='save_blocks'){
        needAuth();
        $db     = loadDb();
        $tid    = trim($_POST['tag_id']??'');
        $blocks = json_decode($_POST['blocks']??'[]', true) ?: [];
        if(!isset($db['tags'][$tid])) jsonOut(['ok'=>false,'error'=>'Tag não encontrada.']);
        // Sanitize
        $db['tags'][$tid]['blocks'] = array_values(array_map(fn($b)=>[
            'id'          => preg_replace('/[^a-z0-9\-]/', '', $b['id']??newUuid()),
            'pos_after'   => max(-1, (int)($b['pos_after']??-1)),
            'title'       => trim($b['title']??''),
            'description' => trim($b['description']??''),
        ], $blocks));
        saveDb($db);
        jsonOut(['ok'=>true]);
    }

    // ── Guardar ordem de músicas numa tag ────────────────────────
    if($act==='save_tag_order'){
        needAuth();
        $db    = loadDb();
        $tid   = trim($_POST['tag_id']??'');
        $order = json_decode($_POST['song_order']??'[]', true) ?: [];
        if(!isset($db['tags'][$tid])) jsonOut(['ok'=>false,'error'=>'Tag não encontrada.']);
        $db['tags'][$tid]['song_order'] = array_values(array_filter($order, fn($id)=>isset($db['songs'][$id])));
        saveDb($db);
        jsonOut(['ok'=>true,'count'=>count($db['tags'][$tid]['song_order'])]);
    }

    // ── Importar playlist do Spotify ──────────────────────────────
    if($act==='import_from_spotify'){
        needAuth();
        if(!hasSpotCreds()) jsonOut(['ok'=>false,'error'=>'Credenciais Spotify não configuradas no .env (CLIENT_ID / CLIENT_SECRET).']);

        $input   = trim($_POST['spotify_input']??'');
        $tagName = trim($_POST['tag_name']??'');
        $tagType = trim($_POST['tag_type']??'list'); // 'list' ou 'event'

        // Extrai playlist ID de URL ou ID direto
        if(preg_match('/playlist\/([A-Za-z0-9]+)/', $input, $m)){
            $playlistId = $m[1];
        } elseif(preg_match('/^[A-Za-z0-9]{22}$/', $input)){
            $playlistId = $input;
        } else {
            jsonOut(['ok'=>false,'error'=>'URL ou ID de playlist inválido. Ex: https://open.spotify.com/playlist/4pcomesNQA6DPXj1HFpOjf']);
        }

        $token = spotToken();
        if(!$token) jsonOut(['ok'=>false,'error'=>'Não foi possível obter token do Spotify. Verifica as credenciais.']);

        // Busca metadados da playlist
        $ch = curl_init("https://api.spotify.com/v1/playlists/{$playlistId}?fields=name,description,tracks.total");
        curl_setopt_array($ch,[
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $meta = json_decode(curl_exec($ch), true);
        curl_close($ch);

        if(empty($meta['name'])) jsonOut(['ok'=>false,'error'=>'Playlist não encontrada ou privada (apenas playlists públicas são suportadas).']);

        if(!$tagName) $tagName = $meta['name'];

        // Pagina todas as faixas (máx 100 por pedido)
        $allTracks = [];
        $offset = 0;
        $limit  = 100;
        do {
            $ch = curl_init("https://api.spotify.com/v1/playlists/{$playlistId}/tracks?limit={$limit}&offset={$offset}&fields=items(track(id,name,artists,duration_ms,external_urls)),next");
            curl_setopt_array($ch,[
                CURLOPT_HTTPHEADER     => ["Authorization: Bearer $token"],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT        => 20,
            ]);
            $page = json_decode(curl_exec($ch), true);
            curl_close($ch);
            $items = $page['items'] ?? [];
            foreach($items as $item){
                $t = $item['track'] ?? null;
                if(!$t || empty($t['name'])) continue; // episódios/podcast ignorados
                $allTracks[] = $t;
            }
            $offset += $limit;
        } while(!empty($page['next']) && $offset < 2000);

        if(!$allTracks) jsonOut(['ok'=>false,'error'=>'Playlist vazia ou sem faixas de música.']);

        $db = loadDb();

        // Cria (ou reutiliza) tag para esta playlist
        $existingTagId = null;
        foreach($db['tags'] as $tid => $tag){
            if(($tag['spotify_id']??'') === $playlistId){
                $existingTagId = $tid;
                break;
            }
        }

        if($existingTagId){
            $tagId = $existingTagId;
            // Atualiza nome se mudou
            $db['tags'][$tagId]['name'] = $tagName;
        } else {
            $tagId = newUuid();
            $db['tags'][$tagId] = [
                'id'             => $tagId,
                'name'           => $tagName,
                'type'           => $tagType,
                'spotify_id'     => $playlistId,
                'color'          => '',
                'event_date'     => '',
                'event_time'     => '',
                'event_location' => '',
            ];
        }

        // Merge músicas (dedup por título+artista)
        $songKeyToUuid = [];
        foreach($db['songs'] as $uuid => $s){
            $k = strtolower(preg_replace('/[^a-z0-9]/i', '', ($s['title']??'').'||'.($s['artist']??'')));
            $songKeyToUuid[$k] = $uuid;
        }

        $added   = 0;
        $updated = 0;
        foreach($allTracks as $track){
            $title  = trim($track['name'] ?? '');
            $artist = trim(implode(', ', array_column($track['artists']??[], 'name')));
            if(!$title) continue;

            $normKey = strtolower(preg_replace('/[^a-z0-9]/i', '', $title.'||'.$artist));
            $spotUrl = $track['external_urls']['spotify'] ?? '';
            $spotUri = 'spotify:track:'.($track['id']??'');
            $durMs   = (int)($track['duration_ms'] ?? 0);

            if(isset($songKeyToUuid[$normKey])){
                $uuid = $songKeyToUuid[$normKey];
                if(!in_array($tagId, $db['songs'][$uuid]['tags']??[])){
                    $db['songs'][$uuid]['tags'][] = $tagId;
                }
                // Actualiza links Spotify se ainda não tinham
                if(empty($db['songs'][$uuid]['spotify_url']) && $spotUrl) $db['songs'][$uuid]['spotify_url'] = $spotUrl;
                if(empty($db['songs'][$uuid]['spotify_uri'])) $db['songs'][$uuid]['spotify_uri'] = $spotUri;
                if(!$db['songs'][$uuid]['duration_ms'] && $durMs)  $db['songs'][$uuid]['duration_ms'] = $durMs;
                $updated++;
            } else {
                $uuid = newUuid();
                while(isset($db['songs'][$uuid])) $uuid = newUuid();
                $db['songs'][$uuid] = [
                    'id'           => $uuid,
                    'title'        => $title,
                    'artist'       => $artist,
                    'cifra_url'    => 'N/A',
                    'cifra_source' => 'cifraclub',
                    'spotify_url'  => $spotUrl,
                    'spotify_uri'  => $spotUri,
                    'duration_ms'  => $durMs,
                    'tags'         => [$tagId],
                    'key'          => '',
                    'bpm'          => '',
                    'rhythm'       => '',
                    'notes'        => '',
                ];
                $songKeyToUuid[$normKey] = $uuid;
                $added++;
            }
        }

        saveDb($db);
        jsonOut([
            'ok'         => true,
            'added'      => $added,
            'updated'    => $updated,
            'total'      => count($allTracks),
            'tag_id'     => $tagId,
            'tag_name'   => $tagName,
            'playlist_name' => $meta['name'],
        ]);
    }
}

// ── Verificação de DB ─────────────────────────────────────────────
$db         = loadDb();
$dbExists   = file_exists($DB_FILE) && count($db['songs']) > 0;
$tags       = $db['tags'] ?? [];

// Uma música fica "invisível" quando todas as suas tags estão arquivadas
function songHasOnlyArchivedTags($song, $tags){
    $songTagIds = $song['tags'] ?? [];
    if(!$songTagIds) return false; // sem tags => sempre visível
    foreach($songTagIds as $tid){
        if(isset($tags[$tid]) && empty($tags[$tid]['archived'])) return false; // tem 1 ativa
    }
    return true; // todas arquivadas
}
$visibleSongs = array_filter($db['songs'], fn($s) => !songHasOnlyArchivedTags($s, $tags));
$activeTags   = array_filter($tags, fn($t) => empty($t['archived']));

$totalSongs = count($visibleSongs);
$totalTags  = count($activeTags);
$rhythms    = $db['settings']['rhythms'] ?? [];
$allKeys    = $db['settings']['keys'] ?? [];
$listTags   = array_values(array_filter($activeTags, fn($t) => ($t['type']??'custom')==='list'));
$eventTags  = array_values(array_filter($activeTags, fn($t) => ($t['type']??'custom')==='event'));
$customTags = array_values(array_filter($activeTags, fn($t) => !in_array($t['type']??'custom', ['list','event'])));

// Verificar se existe DB original para import
$canImport = file_exists(__DIR__ . '/playlists.json');
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SetList V2</title>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Mono:wght@300;400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#0a0a0c;--bg2:#111115;--bg3:#18181e;--bg4:#1e1e26;
  --border:#222228;--border2:#2e2e38;
  --accent:#1db954;--accent2:#1ed760;
  --accent-dim:rgba(29,185,84,.12);--accent-glow:rgba(29,185,84,.25);
  --text:#f0f0f4;--text2:#8888a0;--text3:#555568;
  --danger:#e05252;--danger-dim:rgba(224,82,82,.12);
  --purple:#a78bfa;--purple-dim:rgba(167,139,250,.12);
  --blue:#60a5fa;--blue-dim:rgba(96,165,250,.12);
  --orange:#fb923c;--orange-dim:rgba(251,146,60,.12);
  --gold:#f0c419;--gold-dim:rgba(240,196,25,.12);
  --r:8px;--r2:14px;--tr:.18s cubic-bezier(.4,0,.2,1);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased}

/* ── LAYOUT ── */
.app{display:flex;min-height:100vh}
.sidebar{width:280px;min-width:280px;background:var(--bg2);border-right:1px solid var(--border);display:flex;flex-direction:column;position:sticky;top:0;height:100vh;overflow-y:auto;flex-shrink:0}
.main{flex:1;min-width:0;display:flex;flex-direction:column}

/* ── SIDEBAR ── */
.sb-logo{padding:22px 18px 16px;border-bottom:1px solid var(--border)}
.sb-logo .wm{font-family:'Playfair Display',serif;font-size:1.15rem;font-weight:900;letter-spacing:-.02em}
.sb-logo .wm span{color:var(--accent)}
.sb-logo .v2badge{display:inline-block;font-family:'DM Mono',monospace;font-size:.45rem;letter-spacing:.15em;text-transform:uppercase;background:var(--purple-dim);color:var(--purple);border:1px solid rgba(167,139,250,.3);padding:2px 6px;border-radius:4px;margin-left:6px;vertical-align:middle}
.sb-logo .tg{font-family:'DM Mono',monospace;font-size:.55rem;color:var(--text3);letter-spacing:.12em;text-transform:uppercase;margin-top:3px}

.sb-section{padding:14px 10px 6px;font-family:'DM Mono',monospace;font-size:.5rem;letter-spacing:.18em;text-transform:uppercase;color:var(--text3)}
.sb-tags{padding:4px 8px;flex:1}
.tag-item{display:flex;align-items:center;gap:5px;padding:5px 8px;border-radius:var(--r);cursor:pointer;color:var(--text2);font-size:.78rem;transition:all var(--tr);position:relative}
.tag-item:hover{background:var(--bg3);color:var(--text)}
.tag-item.active{background:var(--accent-dim);color:var(--accent)}
.tag-item.active::before{content:'';position:absolute;left:0;top:20%;bottom:20%;width:3px;background:var(--accent);border-radius:0 3px 3px 0}
.tag-dot{width:7px;height:7px;border-radius:50%;background:var(--text3);flex-shrink:0}
.tag-item.active .tag-dot{background:var(--accent)}
.tag-dot.type-list{background:var(--accent)}
.tag-dot.type-event{background:var(--gold)}
.tag-dot.type-custom{background:var(--purple)}
.tag-dot.type-musician{background:var(--orange)}
.tag-count{margin-left:auto;font-family:'DM Mono',monospace;font-size:.58rem;color:var(--text3)}
.tag-item.active .tag-count{color:var(--accent)}

.sb-bottom{padding:10px 8px 16px;border-top:1px solid var(--border);margin-top:auto;display:flex;flex-direction:column;gap:6px}
.sb-btn{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:var(--r);border:1px dashed var(--border2);background:none;color:var(--text3);font-family:'DM Sans',sans-serif;font-size:.76rem;cursor:pointer;transition:all var(--tr);width:100%;text-align:left}
.sb-btn:hover{border-color:var(--accent);color:var(--accent);background:var(--accent-dim)}
.sb-btn svg{width:13px;height:13px;flex-shrink:0}

/* ── TOPBAR ── */
.topbar{border-bottom:1px solid var(--border);padding:12px 24px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;background:var(--bg2);position:sticky;top:0;z-index:50}
.topbar-title{font-family:'Playfair Display',serif;font-size:1.1rem;font-weight:700;flex:1}
.topbar-sub{font-family:'DM Mono',monospace;font-size:.55rem;color:var(--text3);letter-spacing:.1em;text-transform:uppercase}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:var(--r);border:1px solid var(--border2);background:transparent;color:var(--text2);font-family:'DM Sans',sans-serif;font-size:.8rem;cursor:pointer;transition:all var(--tr);white-space:nowrap}
.btn svg{width:13px;height:13px;flex-shrink:0}
.btn-primary{background:var(--accent);color:#000;border-color:var(--accent)}
.btn-primary:hover{background:var(--accent2);box-shadow:0 0 16px var(--accent-glow)}
.btn-outline{background:transparent;color:var(--text2);border-color:var(--border2)}
.btn-outline:hover{border-color:var(--accent);color:var(--accent);background:var(--accent-dim)}
.btn-ghost{background:transparent;color:var(--text3);border-color:transparent;padding:6px 10px}
.btn-ghost:hover{color:var(--text);background:var(--bg3)}
.btn-danger{background:transparent;color:var(--danger);border-color:transparent;padding:6px 10px}
.btn-danger:hover{background:var(--danger-dim);border-color:var(--danger)}
.btn-purple{background:var(--purple-dim);color:var(--purple);border-color:rgba(167,139,250,.3)}
.btn-purple:hover{background:rgba(167,139,250,.22);border-color:var(--purple)}

/* ── CONTENT ── */
.content{padding:20px 24px;flex:1}

/* ── STATS ── */
.stats-row{display:flex;gap:10px;margin-bottom:20px;flex-wrap:wrap}
.stat-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r2);padding:12px 16px;flex:1;min-width:90px}
.stat-num{font-family:'Playfair Display',serif;font-size:1.5rem;font-weight:700;line-height:1}
.stat-label{font-family:'DM Mono',monospace;font-size:.52rem;letter-spacing:.12em;text-transform:uppercase;color:var(--text3);margin-top:4px}

/* ── FILTERS BAR ── */
.filters-bar{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;align-items:center}
.search-wrap{position:relative;flex:1;min-width:180px}
.search-wrap svg{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--text3);width:13px;height:13px;pointer-events:none}
.search-input{width:100%;background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);padding:8px 13px 8px 34px;font-family:'DM Sans',sans-serif;font-size:.82rem;color:var(--text);outline:none;transition:border-color var(--tr)}
.search-input:focus{border-color:var(--border2)}
.search-input::placeholder{color:var(--text3)}
.filter-select{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);padding:8px 12px;font-family:'DM Sans',sans-serif;font-size:.8rem;color:var(--text2);cursor:pointer;outline:none}
.filter-select:focus{border-color:var(--border2)}

/* ── TABLE ── */
.table-wrap{border:1px solid var(--border);border-radius:var(--r2);overflow:hidden;background:var(--bg2)}
table{width:100%;border-collapse:collapse}
thead th{font-family:'DM Mono',monospace;font-size:.52rem;letter-spacing:.14em;text-transform:uppercase;color:var(--text3);padding:9px 14px;border-bottom:1px solid var(--border);text-align:left;background:var(--bg2);font-weight:400;cursor:pointer;user-select:none;white-space:nowrap}
thead th:hover{color:var(--text2)}
thead th.sorted{color:var(--accent)}
tbody tr{border-bottom:1px solid var(--border);transition:background var(--tr)}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:var(--bg3)}
tbody td{padding:7px 14px;font-size:.82rem;color:var(--text);vertical-align:middle}
.td-num{font-family:'DM Mono',monospace;font-size:.6rem;color:var(--text3);width:38px;text-align:center}
.td-title{font-weight:500}
.td-artist{color:var(--text2);font-size:.75rem;margin-top:1px}
.td-meta{display:flex;gap:5px;flex-wrap:wrap;margin-top:4px}
.td-actions{width:80px;text-align:right}
.td-actions-inner{display:inline-flex;align-items:center;gap:2px;opacity:0;transition:opacity var(--tr)}
tr:hover .td-actions-inner{opacity:1}

/* ── TAGS chips ── */
.chip{display:inline-flex;align-items:center;padding:2px 8px;border-radius:20px;font-family:'DM Mono',monospace;font-size:.5rem;letter-spacing:.08em;text-transform:uppercase;white-space:nowrap;border:1px solid}
.chip-list{background:var(--accent-dim);color:var(--accent);border-color:var(--accent-glow)}
.chip-event{background:var(--gold-dim);color:var(--gold);border-color:rgba(240,196,25,.3)}
.chip-custom{background:var(--purple-dim);color:var(--purple);border-color:rgba(167,139,250,.3)}
.chip-musician{background:var(--orange-dim);color:var(--orange);border-color:rgba(251,146,60,.3)}
.chip-key{background:var(--blue-dim);color:var(--blue);border-color:rgba(96,165,250,.3)}
.chip-bpm{background:var(--bg3);color:var(--text3);border-color:var(--border)}
.chip-rhythm{background:var(--bg3);color:var(--text2);border-color:var(--border2)}

/* ── MODAL ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.78);backdrop-filter:blur(4px);z-index:200;display:flex;align-items:center;justify-content:center;padding:16px;opacity:0;pointer-events:none;transition:opacity var(--tr)}
.modal-overlay.open{opacity:1;pointer-events:all}
.modal{background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r2);width:100%;max-width:500px;transform:translateY(10px);transition:transform var(--tr);display:flex;flex-direction:column;max-height:calc(100vh - 48px);overflow:hidden}
.modal-overlay.open .modal{transform:translateY(0)}
.modal-title{font-family:'Playfair Display',serif;font-size:1.05rem;font-weight:700;padding:24px 24px 0}
.modal-sub{font-size:.75rem;color:var(--text3);padding:6px 24px 14px}
.modal-body{overflow-y:auto;flex:1;min-height:0;padding:0 24px 8px}
.modal-footer{display:flex;gap:8px;justify-content:flex-end;padding:14px 24px 20px;flex-shrink:0;border-top:1px solid var(--border)}

/* ── FORMS ── */
.fg{margin-bottom:14px}
.fl{display:block;font-family:'DM Mono',monospace;font-size:.57rem;letter-spacing:.12em;text-transform:uppercase;color:var(--text3);margin-bottom:6px}
.fi{width:100%;background:var(--bg3);border:1px solid var(--border2);border-radius:var(--r);padding:9px 12px;font-family:'DM Sans',sans-serif;font-size:.875rem;color:var(--text);outline:none;transition:border-color var(--tr)}
.fi:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-dim)}
.fi::placeholder{color:var(--text3)}
select.fi{cursor:pointer;-webkit-appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23555568' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;padding-right:32px}
.fi-row{display:flex;gap:10px}
.fi-row .fi{flex:1}

/* tags picker */
.tags-picker{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px}
.tp-chip{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;font-family:'DM Mono',monospace;font-size:.55rem;letter-spacing:.08em;text-transform:uppercase;border:1px solid var(--border2);background:var(--bg3);color:var(--text3);cursor:pointer;transition:all var(--tr)}
.tp-chip:hover{border-color:var(--text2);color:var(--text2)}
.tp-chip.sel-list{background:var(--accent-dim);color:var(--accent);border-color:var(--accent-glow)}
.tp-chip.sel-custom{background:var(--purple-dim);color:var(--purple);border-color:rgba(167,139,250,.3)}
.tp-chip.sel-musician{background:var(--orange-dim);color:var(--orange);border-color:rgba(251,146,60,.3)}

/* alerts */
.alert{padding:10px 14px;border-radius:var(--r);font-size:.8rem;margin-bottom:14px;display:flex;align-items:center;gap:8px}
.alert-err{background:var(--danger-dim);border:1px solid rgba(224,82,82,.3);color:#f88}
.alert-ok{background:var(--accent-dim);border:1px solid var(--accent-glow);color:var(--accent)}
.alert-info{background:var(--blue-dim);border:1px solid rgba(96,165,250,.3);color:var(--blue)}

/* empty state */
.empty-state{text-align:center;padding:60px 20px;color:var(--text3)}
.empty-state .em-icon{font-size:2.5rem;margin-bottom:12px}
.empty-state h3{font-family:'Playfair Display',serif;font-size:1.1rem;color:var(--text2);margin-bottom:6px}
.empty-state p{font-size:.8rem;max-width:320px;margin:0 auto 18px}

/* saving indicator */
.saving{font-family:'DM Mono',monospace;font-size:.58rem;color:var(--accent);letter-spacing:.08em;opacity:0;transition:opacity .3s;margin-left:8px}
.saving.show{opacity:1}

/* ── PRINT ── */
@media print {
  .sidebar,.topbar,.filters-bar,.td-actions,.no-print{display:none!important}
  .main{margin:0}
  .content{padding:10px}
  body{background:#fff;color:#000}
  .table-wrap{border:1px solid #ccc;border-radius:0}
  thead th{background:#f5f5f5;color:#333}
  tbody td{color:#000}
  .chip{border-color:#999;color:#333;background:#f5f5f5}
  .td-artist{color:#555}
  h1.print-title{font-family:'Playfair Display',serif;font-size:1.4rem;margin-bottom:4px}
  .print-meta{font-size:.7rem;color:#888;margin-bottom:16px}
}

/* ── IMPORT BANNER ── */
.import-banner{background:linear-gradient(135deg,rgba(167,139,250,.08),rgba(29,185,84,.06));border:1px solid rgba(167,139,250,.25);border-radius:var(--r2);padding:20px 24px;margin-bottom:20px;display:flex;align-items:center;gap:16px}
.import-banner .ib-icon{font-size:2rem;flex-shrink:0}
.import-banner h3{font-family:'Playfair Display',serif;font-size:1rem;margin-bottom:4px}
.import-banner p{font-size:.78rem;color:var(--text2)}
.import-banner .ib-actions{margin-left:auto;display:flex;gap:8px;flex-shrink:0}

/* ── BLOCK MARKERS ── */
.block-row td{background:var(--bg2)!important;border-top:2px solid var(--accent-glow)!important;border-bottom:2px solid var(--accent-glow)!important;padding:6px 12px!important}
.block-row-inner{display:flex;align-items:center;gap:10px}
.block-pill{background:var(--accent-dim);border:1px solid var(--accent-glow);border-radius:20px;padding:3px 10px;font-size:.66rem;font-family:'DM Mono',monospace;color:var(--accent);letter-spacing:.04em;white-space:nowrap}
.block-title{font-size:.82rem;font-weight:600;color:var(--text1)}
.block-desc{font-size:.72rem;color:var(--text3);margin-top:1px}
.block-edit-btn{margin-left:auto;opacity:0;transition:opacity var(--tr)}
.block-row:hover .block-edit-btn{opacity:1}
.add-block-btn{display:inline-flex;align-items:center;gap:4px;font-size:.68rem;color:var(--text3);background:none;border:1px dashed var(--border2);border-radius:6px;padding:3px 8px;cursor:pointer;transition:all var(--tr)}
.add-block-btn:hover{color:var(--accent);border-color:var(--accent)}

/* ── PRINT TAG SELECTOR ── */
.print-tag-sel-wrap{display:none;flex-wrap:wrap;gap:6px;max-height:180px;overflow-y:auto;padding:4px;border:1px solid var(--border2);border-radius:var(--r);background:var(--bg2)}
.print-tag-sel-wrap.show{display:flex}
.print-tag-chip{display:inline-flex;align-items:center;gap:5px;padding:4px 9px;border-radius:20px;border:1px solid var(--border2);background:var(--bg3);font-size:.72rem;cursor:pointer;transition:all var(--tr);user-select:none}
.print-tag-chip input{margin:0;width:12px;height:12px;accent-color:var(--accent);cursor:pointer}
.print-tag-chip:hover{border-color:var(--accent);background:var(--accent-dim)}
.print-tag-chip.checked{border-color:var(--accent);background:var(--accent-dim);color:var(--accent)}

.td-dur{font-family:'DM Mono',monospace;font-size:.72rem;color:var(--text2);width:58px;white-space:nowrap}
.td-dur-total{font-family:'DM Mono',monospace;font-size:.72rem;color:var(--accent);white-space:nowrap}

/* ── DRAG & DROP ORDER ── */
.drag-handle{cursor:grab;color:var(--text3);padding:0 6px 0 2px;opacity:.4;transition:opacity var(--tr);user-select:none;flex-shrink:0}
.drag-handle:hover,.song-row:hover .drag-handle{opacity:1}
.drag-handle:active{cursor:grabbing}
.song-row.drag-over td{background:var(--accent-dim)!important;border-top:2px solid var(--accent)}
.song-row.dragging{opacity:.35}
.pos-select{width:44px;padding:2px 2px 2px 4px;border:1px solid var(--border2);border-radius:4px;background:var(--bg2);color:var(--text1);font-size:.72rem;font-family:'DM Mono',monospace;cursor:pointer;text-align:center}
.pos-select:focus{outline:none;border-color:var(--accent)}
.order-mode-bar{display:none;align-items:center;gap:8px;padding:6px 12px;background:var(--accent-dim);border:1px solid var(--accent-glow);border-radius:var(--r);margin-bottom:10px;font-size:.76rem;color:var(--accent)}
.order-mode-bar.show{display:flex}
.order-mode-bar svg{width:13px;height:13px;flex-shrink:0}
td.td-drag{width:24px;padding:0 0 0 8px}
.spot-link{display:inline-flex;align-items:center;gap:3px;color:#1db954;font-size:.58rem;font-family:'DM Mono',monospace;text-decoration:none;padding:2px 5px;border-radius:4px;border:1px solid rgba(29,185,84,.3);transition:all var(--tr)}
.spot-link:hover{background:rgba(29,185,84,.12)}
.spot-link svg{width:8px;height:8px}

/* ── SPOTIFY IMPORT ── */
.spot-import-result{border-radius:var(--r);padding:12px 14px;font-size:.8rem;margin-top:14px}
.spot-import-result.ok{background:var(--accent-dim);border:1px solid var(--accent-glow);color:var(--accent)}
.spot-import-result.err{background:var(--danger-dim);border:1px solid rgba(224,82,82,.3);color:#f88}
.spot-progress{display:flex;align-items:center;gap:8px;font-family:'DM Mono',monospace;font-size:.66rem;color:var(--text3);margin-top:10px}
.spot-spinner{width:14px;height:14px;border:2px solid var(--border2);border-top-color:var(--accent);border-radius:50%;animation:spin .7s linear infinite;flex-shrink:0}
@keyframes spin{to{transform:rotate(360deg)}}

/* scrollbar */
::-webkit-scrollbar{width:5px;height:5px}
::-webkit-scrollbar-track{background:var(--bg)}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:4px}

/* toast */
.toast{position:fixed;bottom:24px;right:24px;background:var(--accent);color:#000;font-family:'DM Mono',monospace;font-size:.66rem;letter-spacing:.08em;padding:10px 18px;border-radius:8px;transform:translateY(60px);opacity:0;transition:all .25s;z-index:400;pointer-events:none}
.toast.show{transform:translateY(0);opacity:1}
.toast.err{background:var(--danger);color:#fff}

/* selectable rows */
table tbody tr.song-row{cursor:pointer;user-select:none}
table tbody tr.song-row.selected{background:rgba(110,231,183,.08)}
table tbody tr.song-row.selected td:first-child{border-left:3px solid var(--accent)}

/* bulk action bar */
.bulk-bar{
  position:fixed;left:0;right:0;bottom:0;z-index:350;
  background:var(--bg2);border-top:1px solid var(--border2);
  padding:10px 16px;display:flex;align-items:center;gap:10px;
  transform:translateY(100%);transition:transform .25s ease;
  box-shadow:0 -6px 24px rgba(0,0,0,.35);
}
.bulk-bar.show{transform:translateY(0)}
.bulk-count{font-family:'DM Mono',monospace;font-size:.7rem;color:var(--accent);white-space:nowrap}
.bulk-actions{display:flex;gap:8px;flex:1;flex-wrap:wrap}
.bulk-actions .btn{font-size:.7rem;padding:7px 12px}
.bulk-clear{margin-left:auto;background:none;border:none;color:var(--text3);font-size:.85rem;cursor:pointer;padding:4px 8px}

/* ── MOBILE ── */
@media (max-width: 768px) {
  /* Layout: sidebar vira menu deslizante no topo */
  .app{flex-direction:column}
  .sidebar{
    width:100%;min-width:0;height:auto;
    position:relative;top:auto;
    border-right:none;border-bottom:1px solid var(--border);
    overflow:hidden;max-height:52px;
    transition:max-height .3s ease;
  }
  .sidebar.mob-open{max-height:80vh;overflow-y:auto}
  .sb-logo{
    display:flex;align-items:center;justify-content:space-between;
    padding:14px 16px;cursor:pointer;
  }
  .sb-logo::after{
    content:'☰';font-size:1.1rem;color:var(--text2);
  }
  .sidebar.mob-open .sb-logo::after{content:'✕'}
  .sb-section{padding:8px 16px 4px}
  .sb-tags{padding:0 8px 4px}
  .sb-bottom{padding:8px;display:flex;flex-wrap:wrap;gap:6px;border-top:1px solid var(--border)}
  .sb-btn{flex:1;min-width:130px;padding:9px 10px;font-size:.72rem}

  /* Main */
  .main{min-width:0}
  .topbar{padding:12px 14px;flex-wrap:wrap;gap:8px}
  .topbar > div:first-child{flex:1 1 100%}
  .topbar > div:last-child{width:100%;justify-content:flex-start}
  .topbar-title{font-size:.95rem}
  .btn{font-size:.72rem;padding:7px 11px}

  /* Stats */
  .stats-row{grid-template-columns:repeat(2,1fr);gap:8px;padding:0 12px;margin-bottom:12px}
  .stat-card{padding:10px 12px}
  .stat-num{font-size:1.4rem}

  /* Filters */
  .filters-bar{padding:10px 12px;gap:8px}
  .search-wrap{width:100%}
  .filter-select{flex:1;min-width:120px;font-size:.72rem;padding:7px 8px}

  /* Table: oculta colunas menos importantes */
  .content{padding:0 0 80px}
  .table-wrap{border-radius:0;border-left:none;border-right:none}
  /* Colunas visíveis no mobile: drag(1, já oculto), #(2), Título(3), Duração(4) */
  /* Ocultar: Tags(5), Tom(6), Ritmo(7), BPM(8), Acções(9) — mas NÃO em block-row (colspan) */
  table thead th:nth-child(1),
  table tbody tr:not(.block-row) td:nth-child(1){display:none}
  table thead th:nth-child(2),
  table tbody tr:not(.block-row) td:nth-child(2){display:none}
  table thead th:nth-child(5),
  table thead th:nth-child(6),
  table thead th:nth-child(7),
  table thead th:nth-child(8),
  table tbody tr:not(.block-row) td:nth-child(5),
  table tbody tr:not(.block-row) td:nth-child(6),
  table tbody tr:not(.block-row) td:nth-child(7),
  table tbody tr:not(.block-row) td:nth-child(8){display:none}
  th,td{padding:9px 8px;font-size:.78rem}
  .td-title{font-size:.82rem}
  .td-artist{font-size:.7rem}
  .chip{font-size:.55rem;padding:2px 5px}

  /* Modals */
  .modal{max-width:100%!important;margin:0;border-radius:var(--r2) var(--r2) 0 0;max-height:92vh;overflow-y:auto}
  .modal-overlay{align-items:flex-end;padding:0}
  .modal-body{padding:12px 16px}
  .fi-row{flex-direction:column}
  .fi-row > div{flex:none!important}
  .modal-footer{padding:12px 16px;gap:8px}
  .modal-footer .btn{flex:1}

  /* Toast */
  .toast{right:12px;bottom:16px;left:12px;text-align:center}

  /* Bulk bar */
  .bulk-bar{padding:8px 10px;flex-wrap:wrap}
  .bulk-count{width:100%;text-align:center}
  .bulk-actions{width:100%;justify-content:center}
  .bulk-actions .btn{flex:1;min-width:100px}
  .bulk-clear{position:absolute;top:6px;right:8px}
}
</style>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body>
<div class="app">

<!-- ── SIDEBAR ── -->
<aside class="sidebar" id="sidebar">
  <div class="sb-logo">
    <div class="wm">Set<span>List</span><span class="v2badge">V2</span></div>
    <div class="tg">Sistema de Tags</div>
  </div>

  <div class="sb-section">Visão Geral</div>
  <div class="sb-tags" id="tagList">
    <div class="tag-item active" data-tag="" onclick="setTagFilter('')">
      <div class="tag-dot" style="background:var(--text3)"></div>
      <span>Todas as Músicas</span>
      <span class="tag-count" id="totalCount"><?= $totalSongs ?></span>
    </div>
  </div>

  <div class="sb-bottom">
    <button class="sb-btn" onclick="openModal('addTagModal')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
      Nova Tag / Lista
    </button>
    <button class="sb-btn" onclick="openCalendarModal()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
      Calendário de Eventos
    </button>
    <button class="sb-btn" onclick="openPrintModal()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Imprimir Lista
    </button>
    <?php if(isAuthed()): ?>
    <button class="sb-btn" onclick="openModal('spotifyImportModal')" style="color:#1db954;border-color:rgba(29,185,84,.3)">
      <svg viewBox="0 0 24 24" fill="currentColor" width="13" height="13"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.586 14.424a.623.623 0 01-.857.207c-2.348-1.435-5.304-1.76-8.785-.964a.623.623 0 01-.277-1.215c3.809-.87 7.077-.496 9.713 1.115a.623.623 0 01.206.857zm1.223-2.722a.779.779 0 01-1.072.257c-2.687-1.652-6.785-2.131-9.965-1.166a.779.779 0 01-.973-.52.779.779 0 01.52-.972c3.632-1.102 8.147-.568 11.233 1.329a.779.779 0 01.257 1.072zm.105-2.835C14.692 8.95 9.375 8.775 6.297 9.71a.935.935 0 11-.543-1.79c3.533-1.072 9.404-.865 13.115 1.338a.935.935 0 11-.954 1.609z"/></svg>
      Importar do Spotify
    </button>
    <?php endif; ?>
    <?php if(isAuthed()): ?>
    <button class="sb-btn" onclick="openModal('tagManagerModal')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 010 14.14M4.93 4.93a10 10 0 000 14.14"/></svg>
      Gerir Tags
    </button>
    <?php endif; ?>
    <button class="sb-btn" onclick="exportDb()" title="Exportar toda a base de dados como JSON">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Exportar JSON
    </button>
    <?php if(isLocked() && isAuthed()): ?>
    <a href="?logout=1" class="sb-btn" style="text-decoration:none;color:var(--text3)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
      Sair
    </a>
    <?php endif; ?>
  </div>
</aside>

<!-- ── MAIN ── -->
<main class="main">
  <div class="topbar">
    <div>
      <div class="topbar-title" id="currentViewTitle">Todas as Músicas</div>
      <div class="topbar-sub" id="currentViewSub"><?= $totalSongs ?> músicas no catálogo</div>
    </div>
    <span class="saving" id="savingInd">Salvando…</span>
    <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
      <button class="btn no-print" id="exportSpotifyBtn" onclick="openExportSpotify()" style="display:none;background:#1db954;border-color:#1db954;color:#000;font-weight:600">
        <svg viewBox="0 0 24 24" fill="currentColor" width="13" height="13"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.586 14.424a.623.623 0 01-.857.207c-2.348-1.435-5.304-1.76-8.785-.964a.623.623 0 01-.277-1.215c3.809-.87 7.077-.496 9.713 1.115a.623.623 0 01.206.857zm1.223-2.722a.779.779 0 01-1.072.257c-2.687-1.652-6.785-2.131-9.965-1.166a.779.779 0 01-.973-.52.779.779 0 01.52-.972c3.632-1.102 8.147-.568 11.233 1.329a.779.779 0 01.257 1.072zm.105-2.835C14.692 8.95 9.375 8.775 6.297 9.71a.935.935 0 11-.543-1.79c3.533-1.072 9.404-.865 13.115 1.338a.935.935 0 11-.954 1.609z"/></svg>
        Exportar para Spotify
      </button>
      <?php if(isAuthed()): ?>
      <button class="btn btn-primary no-print" onclick="openModal('addSongModal')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Adicionar Música
      </button>
      <?php endif; ?>
      <button class="btn btn-purple no-print" onclick="doImportOriginals()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        Importar JSON
      </button>
      <button class="btn btn-outline no-print" onclick="copyListToClipboard()" title="Copiar lista visível para o clipboard em texto plano">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>
        Copiar Lista
      </button>
    </div>
  </div>

  <div class="content" id="mainContent">

    <?php if(!$dbExists): ?>
    <!-- EMPTY STATE / ONBOARDING -->
    <div class="import-banner">
      <div class="ib-icon">🎵</div>
      <div>
        <h3>Bem-vindo ao SetList V2!</h3>
        <p>O novo sistema usa tags para organizar músicas. <?php if($canImport): ?>Começa importando as tuas listas existentes, ou adiciona músicas manualmente.<?php else: ?>Adiciona músicas manualmente e cria tags para organizá-las.<?php endif; ?></p>
      </div>
      <div class="ib-actions">
        <button class="btn btn-primary" onclick="doImportOriginals()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          Importar JSON
        </button>
        <button class="btn btn-outline" onclick="openModal('addSongModal')">Adicionar Manualmente</button>
      </div>
    </div>
    <?php endif; ?>

    <!-- STATS -->
    <div class="stats-row" id="statsRow">
      <div class="stat-card">
        <div class="stat-num" id="statSongs"><?= $totalSongs ?></div>
        <div class="stat-label">Músicas</div>
      </div>
      <div class="stat-card">
        <div class="stat-num" id="statTags"><?= count($listTags) ?></div>
        <div class="stat-label">Listas</div>
      </div>
      <div class="stat-card">
        <div class="stat-num" id="statEvents"><?= count($eventTags) ?></div>
        <div class="stat-label">Eventos</div>
      </div>
      <div class="stat-card">
        <div class="stat-num" id="statCustomTags"><?= count($customTags) ?></div>
        <div class="stat-label">Tags</div>
      </div>
      <div class="stat-card">
        <div class="stat-num" id="statVisible">—</div>
        <div class="stat-label">Filtradas</div>
      </div>
      <div class="stat-card" id="statDurCard" style="display:none">
        <div class="stat-num" id="statDuration" style="font-size:1.1rem">—</div>
        <div class="stat-label">Duração total</div>
      </div>
    </div>

    <!-- FILTERS -->
    <div class="filters-bar no-print">
      <div class="search-wrap">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" class="search-input" id="searchInput" placeholder="Buscar por título ou artista…" oninput="applyFilters()">
      </div>
      <select class="filter-select" id="filterTag" onchange="applyFilters()">
        <option value="">Todas as tags</option>
      </select>
      <select class="filter-select" id="filterRhythm" onchange="applyFilters()">
        <option value="">Todos os ritmos</option>
      </select>
      <select class="filter-select" id="filterKey" onchange="applyFilters()">
        <option value="">Todas as tonalidades</option>
      </select>
      <select class="filter-select" id="sortBy" onchange="applyFilters()">
        <option value="title">A→Z Título</option>
        <option value="artist">A→Z Artista</option>
        <option value="key">Tonalidade</option>
        <option value="rhythm">Ritmo</option>
      </select>
    </div>

    <!-- ORDER MODE BAR -->
    <div class="order-mode-bar" id="orderModeBar">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
      Modo de ordenação — arrasta as linhas ou usa o número para reposicionar.
      <button class="btn btn-ghost" id="addBlockBtn" style="padding:2px 9px;font-size:.7rem;border-color:var(--accent);color:var(--accent)" onclick="openAddBlock(-1)">+ Bloco</button>
      <button class="btn btn-ghost" style="margin-left:4px;padding:2px 8px;font-size:.7rem" onclick="clearTagOrder()">Resetar ordem</button>
    </div>

    <!-- TABLE -->
    <div class="table-wrap">
      <table id="songTable">
        <thead>
          <tr>
            <th class="td-drag no-print" id="thDrag" style="display:none"></th>
            <th class="td-num">#</th>
            <th>Título / Artista</th>
            <th id="thDur" class="td-dur">Duração</th>
            <th>Tags</th>
            <th>Tom</th>
            <th>Ritmo</th>
            <th>BPM</th>
            <th class="td-actions no-print"></th>
          </tr>
        </thead>
        <tbody id="songBody">
          <tr><td colspan="7" style="text-align:center;color:var(--text3);padding:24px;font-size:.8rem">Carregando…</td></tr>
        </tbody>
      </table>
    </div>

  </div><!-- /content -->
</main>
</div><!-- /app -->

<!-- ════ MODALS ════ -->

<!-- Add Song -->
<div class="modal-overlay" id="addSongModal">
  <div class="modal">
    <div class="modal-title">Adicionar Música</div>
    <div class="modal-sub">A música será única no catálogo e pode ter várias tags.</div>
    <div class="modal-body">
      <div id="addSongErr"></div>
      <div class="fg"><label class="fl">Título *</label><input class="fi" id="asTitle" placeholder="Ex: Garota de Ipanema"></div>
      <div class="fg"><label class="fl">Artista *</label><input class="fi" id="asArtist" placeholder="Ex: Tom Jobim"></div>
      <div class="fi-row fg">
        <div style="flex:1"><label class="fl">Tonalidade</label>
          <select class="fi" id="asKey">
            <option value="">—</option>
            <?php foreach($allKeys as $k): ?><option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($k) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div style="flex:1"><label class="fl">BPM / Andamento</label><input class="fi" id="asBpm" placeholder="Ex: 120" type="number"></div>
        <div style="flex:1"><label class="fl">Ritmo</label>
          <select class="fi" id="asRhythm">
            <option value="">—</option>
            <?php foreach($rhythms as $r): ?><option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars($r) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="fg"><label class="fl">Tags</label>
        <div class="tags-picker" id="asTagsPicker"></div>
      </div>
      <div class="fg"><label class="fl">URL Spotify</label><div style="display:flex;gap:6px"><input class="fi" id="asSpotify" placeholder="https://open.spotify.com/track/…" style="flex:1"><button type="button" class="btn btn-ghost" style="padding:6px 10px;font-size:.7rem;white-space:nowrap;flex-shrink:0" onclick="fetchSpotifyDuration(document.getElementById('asSpotify'),'asDuration')">🎵 Buscar duração</button></div></div>
      <div class="fg"><label class="fl">Duração manual <span style="color:var(--text3);font-weight:400">(mm:ss — só se não tiver Spotify)</span></label><input class="fi" id="asDuration" placeholder="Ex: 3:42" maxlength="7" style="font-family:'DM Mono',monospace"></div>
      <div class="fg"><label class="fl">Notas / Observações</label><textarea class="fi" id="asNotes" rows="2" placeholder="Observações sobre a música…"></textarea></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('addSongModal')">Cancelar</button>
      <button class="btn btn-primary" onclick="submitAddSong()">Adicionar</button>
    </div>
  </div>
</div>

<!-- Edit Song -->
<div class="modal-overlay" id="editSongModal">
  <div class="modal">
    <div class="modal-title">Editar Música</div>
    <div class="modal-sub" id="editSongSub"></div>
    <div class="modal-body">
      <div id="editSongErr"></div>
      <input type="hidden" id="esId">
      <input type="hidden" id="esSpotifyUri">
      <div class="fg"><label class="fl">Título *</label><input class="fi" id="esTitle"></div>
      <div class="fg"><label class="fl">Artista *</label><input class="fi" id="esArtist"></div>
      <div class="fi-row fg">
        <div style="flex:1"><label class="fl">Tonalidade</label>
          <select class="fi" id="esKey">
            <option value="">—</option>
            <?php foreach($allKeys as $k): ?><option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($k) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div style="flex:1"><label class="fl">BPM</label><input class="fi" id="esBpm" type="number"></div>
        <div style="flex:1"><label class="fl">Ritmo</label>
          <select class="fi" id="esRhythm">
            <option value="">—</option>
            <?php foreach($rhythms as $r): ?><option value="<?= htmlspecialchars($r) ?>"><?= htmlspecialchars($r) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="fg"><label class="fl">Tags</label>
        <div class="tags-picker" id="esTagsPicker"></div>
      </div>
      <div class="fg"><label class="fl">URL Spotify</label><div style="display:flex;gap:6px"><input class="fi" id="esSpotify" style="flex:1"><button type="button" class="btn btn-ghost" style="padding:6px 10px;font-size:.7rem;white-space:nowrap;flex-shrink:0" onclick="fetchSpotifyDuration(document.getElementById('esSpotify'),'esDuration')">🎵 Buscar duração</button></div></div>
      <div class="fg"><label class="fl">Duração manual <span style="color:var(--text3);font-weight:400">(mm:ss — sobreposta pelo Spotify se existir)</span></label><input class="fi" id="esDuration" placeholder="Ex: 3:42" maxlength="7" style="font-family:'DM Mono',monospace"></div>
      <div class="fg"><label class="fl">Notas</label><textarea class="fi" id="esNotes" rows="2"></textarea></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-danger" onclick="doDeleteSong()">Apagar</button>
      <button class="btn btn-ghost" onclick="closeModal('editSongModal')">Cancelar</button>
      <button class="btn btn-primary" onclick="submitEditSong()">Salvar</button>
    </div>
  </div>
</div>

<!-- Add Tag -->
<div class="modal-overlay" id="addTagModal">
  <div class="modal">
    <div class="modal-title">Nova Tag / Lista</div>
    <div class="modal-sub">Tags organizam as músicas. Tipo "lista" representa uma setlist; "evento" representa um show com data/hora/local.</div>
    <div class="modal-body">
      <div id="addTagErr"></div>
      <div class="fg"><label class="fl">Nome *</label><input class="fi" id="atName" placeholder="Ex: Renato Guitarra"></div>
      <div class="fg"><label class="fl">Tipo</label>
        <select class="fi" id="atType" onchange="toggleEventFields('at')">
          <option value="list">📋 Lista / Setlist</option>
          <option value="event">🎤 Evento (com data/hora/local)</option>
          <option value="musician">🎸 Músico / Instrumento</option>
          <option value="status">📌 Status (ex: aprendendo)</option>
          <option value="custom">🏷️ Personalizada</option>
        </select>
      </div>
      <div id="atEventFields" style="display:none">
        <div class="fg"><label class="fl">Data do Evento</label><input class="fi" type="date" id="atEventDate"></div>
        <div class="fg"><label class="fl">Hora</label><input class="fi" type="time" id="atEventTime"></div>
        <div class="fg"><label class="fl">Local</label><input class="fi" id="atEventLocation" placeholder="Ex: Casa de Shows X, Lisboa"></div>
      </div>
      <div class="fg" id="atSpotifyFg"><label class="fl">ID Spotify da Playlist (opcional)</label><input class="fi" id="atSpotify" placeholder="4pcomesNQA6DPXj1HFpOjf"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('addTagModal')">Cancelar</button>
      <button class="btn btn-primary" onclick="submitAddTag()">Criar Tag</button>
    </div>
  </div>
</div>

<!-- Tag Manager -->
<div class="modal-overlay" id="tagManagerModal">
  <div class="modal" style="max-width:580px">
    <div class="modal-title">Gerir Tags</div>
    <div class="modal-sub">Clica numa tag para editar ou apagar.</div>
    <div class="modal-body" id="tagManagerBody" style="padding-bottom:16px"></div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('tagManagerModal')">Fechar</button>
      <button class="btn btn-primary" onclick="closeModal('tagManagerModal');openModal('addTagModal')">+ Nova Tag</button>
    </div>
  </div>
</div>

<!-- Edit Tag -->
<div class="modal-overlay" id="editTagModal">
  <div class="modal">
    <div class="modal-title">Editar Tag</div>
    <div class="modal-body">
      <div id="editTagErr"></div>
      <input type="hidden" id="etId">
      <div class="fg"><label class="fl">Nome</label><input class="fi" id="etName"></div>
      <div class="fg"><label class="fl">Tipo</label>
        <select class="fi" id="etType" onchange="toggleEventFields('et')">
          <option value="list">📋 Lista / Setlist</option>
          <option value="event">🎤 Evento (com data/hora/local)</option>
          <option value="musician">🎸 Músico / Instrumento</option>
          <option value="status">📌 Status</option>
          <option value="custom">🏷️ Personalizada</option>
        </select>
      </div>
      <div id="etEventFields" style="display:none">
        <div class="fg"><label class="fl">Data do Evento</label><input class="fi" type="date" id="etEventDate"></div>
        <div class="fg"><label class="fl">Hora</label><input class="fi" type="time" id="etEventTime"></div>
        <div class="fg"><label class="fl">Local</label><input class="fi" id="etEventLocation" placeholder="Ex: Casa de Shows X, Lisboa"></div>
      </div>
      <div class="fg"><label class="fl">ID Spotify (opcional)</label><input class="fi" id="etSpotify"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-danger" onclick="doDeleteTag()">Apagar Tag</button>
      <button class="btn btn-ghost" onclick="closeModal('editTagModal')">Cancelar</button>
      <button class="btn btn-primary" onclick="submitEditTag()">Salvar</button>
    </div>
  </div>
</div>

<!-- Add Rhythm -->
<div class="modal-overlay" id="addRhythmModal">
  <div class="modal" style="max-width:360px">
    <div class="modal-title">Novo Ritmo</div>
    <div class="modal-body">
      <div class="fg"><label class="fl">Nome do Ritmo</label><input class="fi" id="arName" placeholder="Ex: Samba, Bossa Nova, Baião…"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('addRhythmModal')">Cancelar</button>
      <button class="btn btn-primary" onclick="submitAddRhythm()">Criar</button>
    </div>
  </div>
</div>

<!-- Print Modal -->
<div class="modal-overlay" id="printModal">
  <div class="modal" style="max-width:500px">
    <div class="modal-title">Imprimir Lista</div>
    <div class="modal-sub">Escolhe a lista e o que queres mostrar em cada coluna.</div>
    <div class="modal-body">
      <div class="fg"><label class="fl">Lista / Tag para imprimir</label>
        <select class="fi" id="printTagSel">
          <option value="">Todas as Músicas</option>
        </select>
      </div>

      <div class="fg">
        <label class="fl" style="display:flex;align-items:center;justify-content:space-between">
          <span>Tags a mostrar <span style="color:var(--text3);font-weight:400">(nenhuma = coluna oculta)</span></span>
          <button class="btn btn-ghost" style="padding:1px 7px;font-size:.65rem" onclick="toggleAllPrintChips('printTagChips')">Todas/Nenhuma</button>
        </label>
        <div id="printTagChips" class="print-tag-sel-wrap"></div>
        <div id="printTagChipsEmpty" style="font-size:.7rem;color:var(--text3);display:none;padding:3px 2px">Nenhuma tag nas músicas desta lista.</div>
      </div>

      <div class="fg">
        <label class="fl" style="display:flex;align-items:center;justify-content:space-between">
          <span>Tonalidade a mostrar <span style="color:var(--text3);font-weight:400">(nenhuma = coluna oculta)</span></span>
          <button class="btn btn-ghost" style="padding:1px 7px;font-size:.65rem" onclick="toggleAllPrintChips('printKeyChips')">Todas/Nenhuma</button>
        </label>
        <div id="printKeyChips" class="print-tag-sel-wrap"></div>
        <div id="printKeyChipsEmpty" style="font-size:.7rem;color:var(--text3);display:none;padding:3px 2px">Nenhuma tonalidade definida nas músicas desta lista.</div>
      </div>

      <div class="fg">
        <label class="fl" style="display:flex;align-items:center;justify-content:space-between">
          <span>Ritmo a mostrar <span style="color:var(--text3);font-weight:400">(nenhuma = coluna oculta)</span></span>
          <button class="btn btn-ghost" style="padding:1px 7px;font-size:.65rem" onclick="toggleAllPrintChips('printRhythmChips')">Todas/Nenhuma</button>
        </label>
        <div id="printRhythmChips" class="print-tag-sel-wrap"></div>
        <div id="printRhythmChipsEmpty" style="font-size:.7rem;color:var(--text3);display:none;padding:3px 2px">Nenhum ritmo definido nas músicas desta lista.</div>
      </div>

      <div class="fg">
        <label class="fl" style="display:flex;align-items:center;justify-content:space-between">
          <span>BPM a mostrar <span style="color:var(--text3);font-weight:400">(nenhuma = coluna oculta)</span></span>
          <button class="btn btn-ghost" style="padding:1px 7px;font-size:.65rem" onclick="toggleAllPrintChips('printBpmChips')">Todas/Nenhuma</button>
        </label>
        <div id="printBpmChips" class="print-tag-sel-wrap"></div>
        <div id="printBpmChipsEmpty" style="font-size:.7rem;color:var(--text3);display:none;padding:3px 2px">Nenhum BPM definido nas músicas desta lista.</div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('printModal')">Cancelar</button>
      <button class="btn btn-primary" onclick="doPrint()">Imprimir / PDF</button>
    </div>
  </div>
</div>

<!-- Spotify Export Modal -->
<div class="modal-overlay" id="spotifyExportModal">
  <div class="modal" style="max-width:480px">
    <div class="modal-title" style="display:flex;align-items:center;gap:10px">
      <svg viewBox="0 0 24 24" fill="#1db954" width="20" height="20"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.586 14.424a.623.623 0 01-.857.207c-2.348-1.435-5.304-1.76-8.785-.964a.623.623 0 01-.277-1.215c3.809-.87 7.077-.496 9.713 1.115a.623.623 0 01.206.857zm1.223-2.722a.779.779 0 01-1.072.257c-2.687-1.652-6.785-2.131-9.965-1.166a.779.779 0 01-.973-.52.779.779 0 01.52-.972c3.632-1.102 8.147-.568 11.233 1.329a.779.779 0 01.257 1.072zm.105-2.835C14.692 8.95 9.375 8.775 6.297 9.71a.935.935 0 11-.543-1.79c3.533-1.072 9.404-.865 13.115 1.338a.935.935 0 11-.954 1.609z"/></svg>
      Exportar para Spotify
    </div>
    <div class="modal-body">
      <div id="spotifyExportErr"></div>
      <div id="spotifyExportInfo" style="font-size:.8rem;color:var(--text2);margin-bottom:14px"></div>
      <!-- Passo 1: autorizar -->
      <div id="seStep1">
        <div style="font-size:.8rem;color:var(--text2);margin-bottom:12px">Para criar ou actualizar a playlist na tua conta Spotify, precisas autorizar o acesso uma vez. O token é usado apenas nesta sessão.</div>
        <button class="btn" style="background:#1db954;border-color:#1db954;color:#000;width:100%;font-weight:600" onclick="startSpotifyAuth()">
          <svg viewBox="0 0 24 24" fill="currentColor" width="13" height="13"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.586 14.424a.623.623 0 01-.857.207c-2.348-1.435-5.304-1.76-8.785-.964a.623.623 0 01-.277-1.215c3.809-.87 7.077-.496 9.713 1.115a.623.623 0 01.206.857zm1.223-2.722a.779.779 0 01-1.072.257c-2.687-1.652-6.785-2.131-9.965-1.166a.779.779 0 01-.973-.52.779.779 0 01.52-.972c3.632-1.102 8.147-.568 11.233 1.329a.779.779 0 01.257 1.072zm.105-2.835C14.692 8.95 9.375 8.775 6.297 9.71a.935.935 0 11-.543-1.79c3.533-1.072 9.404-.865 13.115 1.338a.935.935 0 11-.954 1.609z"/></svg>
          Autorizar com Spotify
        </button>
        <div id="seAuthWaiting" style="display:none;text-align:center;padding:14px 0;font-size:.8rem;color:var(--text3)">
          A aguardar autorização na janela que abriu…<br>
          <button class="btn btn-ghost" style="margin-top:10px;font-size:.72rem" onclick="checkSpotifyAuthManual()">Já autorizei — continuar</button>
        </div>
      </div>
      <!-- Passo 2: confirmar exportação -->
      <div id="seStep2" style="display:none">
        <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:rgba(29,185,84,.1);border:1px solid rgba(29,185,84,.3);border-radius:8px;margin-bottom:14px">
          <svg viewBox="0 0 24 24" fill="#1db954" width="14" height="14"><polyline points="20 6 9 17 4 12"/></svg>
          <span style="font-size:.78rem;color:#1db954" id="seAuthedAs">Conta autorizada</span>
        </div>
        <div id="seExistingPlaylist" style="display:none;font-size:.78rem;color:var(--text2);margin-bottom:10px"></div>
        <div id="seTrackWarning" style="display:none;font-size:.75rem;color:var(--gold);padding:6px 10px;background:rgba(240,196,25,.08);border:1px solid rgba(240,196,25,.25);border-radius:6px;margin-bottom:10px"></div>
        <div id="seExportResult" style="display:none"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('spotifyExportModal')">Fechar</button>
      <button class="btn" id="seExportBtn" style="display:none;background:#1db954;border-color:#1db954;color:#000;font-weight:600" onclick="doExportToSpotify()">
        <svg viewBox="0 0 24 24" fill="currentColor" width="13" height="13"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.586 14.424a.623.623 0 01-.857.207c-2.348-1.435-5.304-1.76-8.785-.964a.623.623 0 01-.277-1.215c3.809-.87 7.077-.496 9.713 1.115a.623.623 0 01.206.857zm1.223-2.722a.779.779 0 01-1.072.257c-2.687-1.652-6.785-2.131-9.965-1.166a.779.779 0 01-.973-.52.779.779 0 01.52-.972c3.632-1.102 8.147-.568 11.233 1.329a.779.779 0 01.257 1.072zm.105-2.835C14.692 8.95 9.375 8.775 6.297 9.71a.935.935 0 11-.543-1.79c3.533-1.102 9.404-.865 13.115 1.338a.935.935 0 11-.954 1.609z"/></svg>
        <span id="seExportBtnLabel">Criar Playlist</span>
      </button>
    </div>
  </div>
</div>

<!-- Calendar Modal -->
<div class="modal-overlay" id="calendarModal">
  <div class="modal" style="max-width:560px">
    <div class="modal-title">📅 Calendário de Eventos</div>
    <div class="modal-body" id="calendarModalBody" style="max-height:65vh;overflow-y:auto;padding:0"></div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('calendarModal')">Fechar</button>
    </div>
  </div>
</div>

<!-- Login Modal -->
<?php if(isLocked() && !isAuthed()): ?>
<div class="modal-overlay open" id="loginModal">
  <div class="modal" style="max-width:360px">
    <div class="modal-title">SetList V2</div>
    <div class="modal-sub">Acesso protegido por senha.</div>
    <div class="modal-body">
      <div id="loginErr" style="min-height:0"></div>
      <div class="fg"><label class="fl">Senha</label><input class="fi" type="password" id="loginPwd" onkeydown="if(event.key==='Enter')doLogin()"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-primary" onclick="doLogin()">Entrar</button>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Toast -->
<!-- Block Marker Modal -->
<div class="modal-overlay" id="blockModal">
  <div class="modal" style="max-width:420px">
    <div class="modal-title" id="blockModalTitle">Adicionar Marcador de Bloco</div>
    <div class="modal-sub">O bloco aparece como separador na lista e na impressão.</div>
    <div class="modal-body">
      <input type="hidden" id="blockId">
      <input type="hidden" id="blockPosAfter">
      <div class="fg">
        <label class="fl">Título do Bloco *</label>
        <input class="fi" id="blockTitle" placeholder="Ex: 1º Bloco, Abertura, Bis…">
      </div>
      <div class="fg">
        <label class="fl">Descrição (opcional)</label>
        <input class="fi" id="blockDesc" placeholder="Ex: músicas mais animadas, ~20min">
      </div>
      <div class="fg">
        <label class="fl">Posição — após qual música?</label>
        <select class="fi" id="blockPosSelect">
          <option value="-1">⬆️ Antes da primeira música</option>
        </select>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-danger" id="blockDeleteBtn" style="margin-right:auto;display:none" onclick="deleteBlock()">Apagar</button>
      <button class="btn btn-ghost" onclick="closeModal('blockModal')">Cancelar</button>
      <button class="btn btn-primary" onclick="submitBlock()">Guardar Bloco</button>
    </div>
  </div>
</div>

<!-- Spotify Import Modal -->
<div class="modal-overlay" id="spotifyImportModal">
  <div class="modal" style="max-width:480px">
    <div class="modal-title" style="display:flex;align-items:center;gap:10px">
      <svg viewBox="0 0 24 24" fill="#1db954" width="20" height="20"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.586 14.424a.623.623 0 01-.857.207c-2.348-1.435-5.304-1.76-8.785-.964a.623.623 0 01-.277-1.215c3.809-.87 7.077-.496 9.713 1.115a.623.623 0 01.206.857zm1.223-2.722a.779.779 0 01-1.072.257c-2.687-1.652-6.785-2.131-9.965-1.166a.779.779 0 01-.973-.52.779.779 0 01.52-.972c3.632-1.102 8.147-.568 11.233 1.329a.779.779 0 01.257 1.072zm.105-2.835C14.692 8.95 9.375 8.775 6.297 9.71a.935.935 0 11-.543-1.79c3.533-1.072 9.404-.865 13.115 1.338a.935.935 0 11-.954 1.609z"/></svg>
      Importar Playlist do Spotify
    </div>
    <div class="modal-sub">Cola o link ou ID de uma playlist pública do Spotify. As músicas serão adicionadas ao catálogo e associadas a uma tag/lista.</div>
    <div class="modal-body">
      <div id="spotifyImportResult"></div>
      <div class="fg">
        <label class="fl">URL ou ID da Playlist *</label>
        <input class="fi" id="siInput" placeholder="https://open.spotify.com/playlist/… ou ID direto">
        <div style="font-size:.65rem;color:var(--text3);margin-top:5px">Exemplo: <code style="background:var(--bg3);padding:1px 5px;border-radius:3px">https://open.spotify.com/playlist/4pcomesNQA6DPXj1HFpOjf</code></div>
      </div>
      <div class="fg">
        <label class="fl">Nome da Tag/Lista (opcional — usa o nome da playlist se vazio)</label>
        <input class="fi" id="siTagName" placeholder="Ex: Setlist Verão 2026">
      </div>
      <div class="fg">
        <label class="fl">Tipo de tag</label>
        <select class="fi" id="siTagType">
          <option value="list">📋 Lista / Setlist</option>
          <option value="event">🎤 Evento</option>
          <option value="custom">🏷️ Personalizada</option>
        </select>
      </div>
      <div id="siProgress" style="display:none" class="spot-progress">
        <div class="spot-spinner"></div>
        <span>A importar do Spotify…</span>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('spotifyImportModal')">Cancelar</button>
      <button class="btn btn-primary" id="siBtnImport" onclick="submitSpotifyImport()" style="background:#1db954;border-color:#1db954;color:#000">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        Importar
      </button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<!-- Bulk action bar -->
<div class="bulk-bar" id="bulkBar">
  <span class="bulk-count" id="bulkCount">0 selecionadas</span>
  <div class="bulk-actions">
    <button class="btn btn-primary" onclick="openBulkTagModal()">+ Adicionar Tag/Lista</button>
    <button class="btn btn-ghost" onclick="openBulkRemoveModal()">− Remover Tag/Lista</button>
    <button class="btn btn-ghost" id="mergeBtn" style="display:none" onclick="openMergeModal()">⇄ Juntar Duplicadas</button>
  </div>
  <button class="bulk-clear" onclick="clearSelection()">✕ Limpar</button>
</div>

<!-- Merge Songs Modal -->
<div class="modal-overlay" id="mergeModal">
  <div class="modal" style="max-width:460px">
    <div class="modal-title">Juntar músicas selecionadas</div>
    <div class="modal-body">
      <div id="mergeErr" class="form-err"></div>
      <div id="mergePreview" style="font-size:.8rem;color:var(--text2);line-height:1.5"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('mergeModal')">Cancelar</button>
      <button class="btn btn-primary" onclick="submitMerge()">Juntar</button>
    </div>
  </div>
</div>

<!-- Bulk Add Tags Modal -->
<div class="modal-overlay" id="bulkAddModal">
  <div class="modal" style="max-width:420px">
    <div class="modal-title">Adicionar Tag/Lista às músicas selecionadas</div>
    <div class="modal-body">
      <div id="bulkAddErr" class="form-err"></div>
      <div class="fg">
        <label class="fl">Escolhe uma ou mais tags para adicionar</label>
        <div id="bulkAddTagsPicker" style="display:flex;flex-wrap:wrap;gap:6px;max-height:240px;overflow-y:auto;padding:4px"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('bulkAddModal')">Cancelar</button>
      <button class="btn btn-primary" onclick="submitBulkAdd()">Adicionar</button>
    </div>
  </div>
</div>

<!-- Bulk Remove Tags Modal -->
<div class="modal-overlay" id="bulkRemoveModal">
  <div class="modal" style="max-width:420px">
    <div class="modal-title">Remover Tag/Lista das músicas selecionadas</div>
    <div class="modal-body">
      <div id="bulkRemoveErr" class="form-err"></div>
      <div class="fg">
        <label class="fl">Escolhe uma ou mais tags para remover</label>
        <div id="bulkRemoveTagsPicker" style="display:flex;flex-wrap:wrap;gap:6px;max-height:240px;overflow-y:auto;padding:4px"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('bulkRemoveModal')">Cancelar</button>
      <button class="btn btn-danger" onclick="submitBulkRemove()">Remover</button>
    </div>
  </div>
</div>

<!-- Print area (hidden, filled before print) -->
<div id="printArea" style="display:none"></div>

<script>
// ════════════════════════════════════════════════════════════════
//  SetList V2 — Frontend
// ════════════════════════════════════════════════════════════════

// ── State ────────────────────────────────────────────────────────
let DB = { songs: {}, tags: {}, settings: { rhythms: [], keys: [] } };
let activeTagFilter = '';
let authed = <?= json_encode(isAuthed()) ?>;
let selectedSongIds = new Set();
let canEdit = authed;

// ── Init ─────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function(){
  loadDbFromServer();
});

function loadDbFromServer(){
  post({ _action: 'get_db' }, function(r){
    if(r.ok){ DB = normalizeDb(r.db); renderAll(); }
    else showToast('Erro ao carregar dados: ' + (r.error||''), true);
  });
}

// Garante que songs/tags são sempre objetos {id: item}, mesmo que o PHP
// devolva arrays (json_encode transforma array assoc. com chaves numéricas em array JS)
function normalizeDb(db){
  db = db || {};
  function toIdMap(val){
    const out = {};
    if(Array.isArray(val)){
      val.forEach(item => { if(item && item.id) out[item.id] = item; });
    } else if(val && typeof val === 'object'){
      Object.assign(out, val);
    }
    return out;
  }
  return {
    version: db.version || 2,
    songs: toIdMap(db.songs),
    tags: toIdMap(db.tags),
    settings: db.settings || { rhythms: [], keys: [] }
  };
}

// ── Render ───────────────────────────────────────────────────────
function renderAll(){
  renderSidebar();
  renderFilterDropdowns();
  applyFilters();
  updateStats();
}

// Uma música fica escondida da lista geral quando TODAS as suas tags
// estão arquivadas. Músicas sem tags nenhumas continuam visíveis.
function isSongVisible(s){
  const songTagIds = s.tags || [];
  if(!songTagIds.length) return true;
  return songTagIds.some(tid => {
    const t = DB.tags[tid];
    return t && !t.archived;
  });
}

function renderSidebar(){
  const tags = DB.tags || {};
  const songs = Object.values(DB.songs || {}).filter(isSongVisible);
  let html = `
    <div class="tag-item ${activeTagFilter===''?'active':''}" data-tag="" onclick="setTagFilter('')">
      <div class="tag-dot"></div>
      <span>Todas as Músicas</span>
      <span class="tag-count" id="totalCount">${songs.length}</span>
    </div>`;

  // Separate by type (tags arquivadas não aparecem na sidebar)
  const activeTags = Object.values(tags).filter(t=>!t.archived);
  const today = new Date().toISOString().slice(0,10);
  const allEvents = activeTags.filter(t=>t.type==='event');
  const futureEvents = allEvents.filter(t=>!t.event_date || t.event_date >= today)
    .sort((a,b)=>(a.event_date||'9999').localeCompare(b.event_date||'9999'));
  const pastEvents = allEvents.filter(t=>t.event_date && t.event_date < today)
    .sort((a,b)=>b.event_date.localeCompare(a.event_date)); // mais recente primeiro
  const lists = activeTags.filter(t=>t.type==='list');
  const musicians = activeTags.filter(t=>t.type==='musician');
  const statuses = activeTags.filter(t=>t.type==='status');
  const customs = activeTags.filter(t=>!['list','event','musician','status'].includes(t.type));

  function tagSection(label, tagArr, dotClass, isEvent){
    if(!tagArr.length) return '';
    let h = `<div style="font-family:'DM Mono',monospace;font-size:.48rem;letter-spacing:.15em;text-transform:uppercase;color:var(--text3);padding:10px 8px 4px">${label}</div>`;
    const arr = isEvent ? [...tagArr].sort((a,b)=>(a.event_date||'').localeCompare(b.event_date||'')) : tagArr;
    arr.forEach(t => {
      const cnt = songs.filter(s=>(s.tags||[]).includes(t.id)).length;
      const isAct = activeTagFilter===t.id;
      const sub = isEvent ? `<div style="font-size:.58rem;color:var(--text3);margin-top:1px">${escH(formatEventDate(t.event_date))}${t.event_location?' · '+escH(t.event_location):''}</div>` : '';
      h += `<div class="tag-item ${isAct?'active':''}" data-tag="${t.id}" onclick="setTagFilter('${t.id}')" style="${isEvent?'align-items:flex-start;padding:7px 8px':''}">
        <div class="tag-dot ${dotClass}" style="${isEvent?'margin-top:4px':''}"></div>
        <div style="flex:1;min-width:0">
          <span>${escH(t.name)}</span>
          ${sub}
        </div>
        <span class="tag-count">${cnt}</span>
      </div>`;
    });
    return h;
  }

  html += tagSection('Próximos Eventos', futureEvents, 'type-event', true);
  html += tagSection('Eventos Passados', pastEvents, 'type-event', true);
  html += tagSection('Listas', lists, 'type-list');
  html += tagSection('Músicos', musicians, 'type-musician');
  html += tagSection('Status', statuses, 'type-custom');
  html += tagSection('Tags', customs, 'type-custom');

  document.getElementById('tagList').innerHTML = html;
  const totalEl = document.getElementById('totalCount');
  if(totalEl) totalEl.textContent = songs.length;
}

function renderFilterDropdowns(){
  const tags = DB.tags || {};
  const rhythmsInUse = [...new Set(Object.values(DB.songs||{}).map(s=>s.rhythm).filter(Boolean))];
  const keysInUse    = [...new Set(Object.values(DB.songs||{}).map(s=>s.key).filter(Boolean))];

  // Tag filter (tags arquivadas não aparecem)
  let tHtml = '<option value="">Todas as tags</option>';
  Object.values(tags).filter(t=>!t.archived).forEach(t => {
    tHtml += `<option value="${t.id}">${escH(t.name)}</option>`;
  });
  document.getElementById('filterTag').innerHTML = tHtml;

  // Rhythm filter
  let rHtml = '<option value="">Todos os ritmos</option>';
  rhythmsInUse.sort().forEach(r => { rHtml += `<option value="${r}">${escH(r)}</option>`; });
  document.getElementById('filterRhythm').innerHTML = rHtml;

  // Key filter
  let kHtml = '<option value="">Todas as tonalidades</option>';
  keysInUse.sort().forEach(k => { kHtml += `<option value="${k}">${escH(k)}</option>`; });
  document.getElementById('filterKey').innerHTML = kHtml;

  // Print tag select (tags arquivadas não aparecem)
  let pHtml = '<option value="">Todas as Músicas</option>';
  Object.values(tags).filter(t=>!t.archived).forEach(t => { pHtml += `<option value="${t.id}">${escH(t.name)}</option>`; });
  document.getElementById('printTagSel').innerHTML = pHtml;
}

function setTagFilter(tid){
  activeTagFilter = tid;
  renderSidebar();
  applyFilters();
  // Update view title
  const title = tid ? (DB.tags[tid]?.name || 'Filtro') : 'Todas as Músicas';
  document.getElementById('currentViewTitle').textContent = title;
}

function applyFilters(){
  const q      = document.getElementById('searchInput').value.toLowerCase().trim();
  const fTag   = document.getElementById('filterTag').value;
  const fRhythm= document.getElementById('filterRhythm').value;
  const fKey   = document.getElementById('filterKey').value;
  const sortBy = document.getElementById('sortBy').value;

  let songs = Object.values(DB.songs || {}).filter(isSongVisible);

  // Tag filter (sidebar)
  if(activeTagFilter) songs = songs.filter(s=>(s.tags||[]).includes(activeTagFilter));

  // Additional filters
  if(fTag)    songs = songs.filter(s=>(s.tags||[]).includes(fTag));
  if(fRhythm) songs = songs.filter(s=>s.rhythm===fRhythm);
  if(fKey)    songs = songs.filter(s=>s.key===fKey);
  if(q)       songs = songs.filter(s=>(s.title||'').toLowerCase().includes(q)||(s.artist||'').toLowerCase().includes(q));

  // Se estamos numa tag com ordem personalizada e não há filtros adicionais, usa essa ordem
  // O sortBy do dropdown é IGNORADO quando há ordem personalizada — nunca destrói a ordem guardada
  const activeTag = activeTagFilter ? DB.tags[activeTagFilter] : null;
  const hasCustomOrder = activeTag && (activeTag.song_order||[]).length > 0;
  const hasExtraFilters = !!(fTag || fRhythm || fKey || q);
  const isOrderMode = hasCustomOrder && !hasExtraFilters;

  if(isOrderMode){
    const orderMap = {};
    (activeTag.song_order||[]).forEach((id, i) => { orderMap[id] = i; });
    songs.sort((a, b) => {
      const ia = orderMap[a.id] !== undefined ? orderMap[a.id] : 99999;
      const ib = orderMap[b.id] !== undefined ? orderMap[b.id] : 99999;
      if(ia !== ib) return ia - ib;
      return (a.title||'').localeCompare(b.title||'', 'pt');
    });
  } else {
    // sortBy só actua quando não há ordem personalizada (ou há filtros activos)
    songs.sort((a,b)=>{
      const va=(a[sortBy]||a.title||'').toLowerCase();
      const vb=(b[sortBy]||b.title||'').toLowerCase();
      return va.localeCompare(vb,'pt');
    });
  }

  // Aviso visual quando o sortBy está activo mas há ordem personalizada ignorada
  const sortByEl = document.getElementById('sortBy');
  if(hasCustomOrder && !hasExtraFilters && sortByEl.value !== 'title'){
    sortByEl.style.opacity = '0.45';
    sortByEl.title = 'Ordenação ignorada — esta lista tem ordem personalizada activa';
  } else {
    sortByEl.style.opacity = '';
    sortByEl.title = '';
  }

  // Mostra/esconde barra e coluna de drag
  const canOrder = !!activeTag && canEdit && !hasExtraFilters;
  const isEventTag = activeTag && activeTag.type === 'event';
  document.getElementById('orderModeBar').classList.toggle('show', canOrder && isOrderMode);
  document.getElementById('thDrag').style.display = canOrder ? '' : 'none';
  // Botão "+ Bloco" só em eventos
  const addBlockBtn = document.getElementById('addBlockBtn');
  if(addBlockBtn) addBlockBtn.style.display = (canOrder && isEventTag) ? '' : 'none';

  renderTable(songs, canOrder);
  document.getElementById('statVisible').textContent = songs.length;
  document.getElementById('currentViewSub').textContent = songs.length + ' músicas' + (activeTagFilter ? ' nesta lista' : ' no catálogo');

  // ── Duração total ──
  const totalMs = songs.reduce((acc, s) => acc + (parseInt(s.duration_ms)||0), 0);
  const durCard = document.getElementById('statDurCard');
  if(totalMs > 0){
    document.getElementById('statDuration').textContent = fmtDuration(totalMs);
    durCard.style.display = '';
  } else {
    durCard.style.display = 'none';
  }

  updateExportSpotifyBtn();
}

// ── Formatação de duração ─────────────────────────────────────────
function fmtDuration(ms, long){
  if(!ms) return '';
  const totalSec = Math.round(ms / 1000);
  const h = Math.floor(totalSec / 3600);
  const m = Math.floor((totalSec % 3600) / 60);
  const s = totalSec % 60;
  if(long){
    // "1h 23min" ou "45min 10s"
    if(h) return h+'h '+String(m).padStart(2,'0')+'min';
    if(m) return m+'min '+String(s).padStart(2,'0')+'s';
    return s+'s';
  }
  // "3:42" ou "1:03:42"
  if(h) return h+':'+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0');
  return m+':'+String(s).padStart(2,'0');
}

function renderTable(songs, canOrder){
  const tags = DB.tags || {};
  const tbody = document.getElementById('songBody');
  if(!songs.length){
    tbody.innerHTML = `<tr><td colspan="8"><div class="empty-state"><div class="em-icon">🎵</div><h3>Nenhuma música encontrada</h3><p>Ajusta os filtros ou adiciona novas músicas ao catálogo.</p></div></td></tr>`;
    return;
  }

  // Blocos só aparecem em tags de evento
  const activeTag = activeTagFilter ? DB.tags[activeTagFilter] : null;
  const isEventTag = activeTag && activeTag.type === 'event';
  const blocks    = (isEventTag && activeTag.blocks) ? activeTag.blocks : [];
  const blocksByPos = {}; // pos_after → [block,…]
  blocks.forEach(b => {
    const k = b.pos_after ?? -1;
    if(!blocksByPos[k]) blocksByPos[k] = [];
    blocksByPos[k].push(b);
  });

  const total = songs.length;
  const colSpan = 8;

  function blockRowHtml(b){
    const editBtn = canOrder
      ? `<button class="btn btn-ghost block-edit-btn" style="padding:2px 7px;font-size:.65rem" onclick="event.stopPropagation();openEditBlock('${b.id}')">editar</button>`
      : '';
    return `<tr class="block-row no-print-hide" data-block-id="${b.id}">
      <td colspan="${colSpan}">
        <div class="block-row-inner">
          <span class="block-pill">BLOCO</span>
          <div>
            <div class="block-title">${escH(b.title)}</div>
            ${b.description?`<div class="block-desc">${escH(b.description)}</div>`:''}
          </div>
          ${editBtn}
        </div>
      </td>
    </tr>`;
  }

  function addBlockBtnHtml(posAfter){
    if(!canOrder || !isEventTag) return '';
    return `<tr class="no-print">
      <td colspan="${colSpan}" style="padding:0;text-align:center">
        <button class="add-block-btn" onclick="event.stopPropagation();openAddBlock(${posAfter})">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="11" height="11"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          bloco
        </button>
      </td>
    </tr>`;
  }

  let html = '';

  // Blocos antes da primeira música (pos_after = -1)
  (blocksByPos[-1]||[]).forEach(b => { html += blockRowHtml(b); });
  if(canOrder) html += addBlockBtnHtml(-1);

  songs.forEach((s, i) => {
    const songTags = (s.tags||[]).map(tid => tags[tid]).filter(Boolean);
    const listTagsArr  = songTags.filter(t=>t.type==='list');
    const eventTagsArr = songTags.filter(t=>t.type==='event');
    const otherTags    = songTags.filter(t=>!['list','event'].includes(t.type));

    let tagsHtml = '';
    eventTagsArr.forEach(t => { tagsHtml += `<span class="chip chip-event">🎤 ${escH(t.name)}</span>`; });
    listTagsArr.forEach(t  => { tagsHtml += `<span class="chip chip-list">${escH(t.name)}</span>`; });
    otherTags.forEach(t => {
      const cls = t.type==='musician'?'chip-musician':'chip-custom';
      tagsHtml += `<span class="chip ${cls}">${escH(t.name)}</span>`;
    });

    const keyHtml  = s.key    ? `<span class="chip chip-key">${escH(s.key)}</span>` : '';
    const bpmHtml  = s.bpm    ? `<span class="chip chip-bpm">${escH(s.bpm)} bpm</span>` : '';
    const rHtml    = s.rhythm ? `<span class="chip chip-rhythm">${escH(s.rhythm)}</span>` : '';
    const spotHtml = s.spotify_url ? `<a href="${s.spotify_url}" target="_blank" class="spot-link"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.586 14.424a.623.623 0 01-.857.207c-2.348-1.435-5.304-1.76-8.785-.964a.623.623 0 01-.277-1.215c3.809-.87 7.077-.496 9.713 1.115a.623.623 0 01.206.857zm1.223-2.722a.779.779 0 01-1.072.257c-2.687-1.652-6.785-2.131-9.965-1.166a.779.779 0 01-.973-.52.779.779 0 01.52-.972c3.632-1.102 8.147-.568 11.233 1.329a.779.779 0 01.257 1.072zm.105-2.835C14.692 8.95 9.375 8.775 6.297 9.71a.935.935 0 11-.543-1.79c3.533-1.072 9.404-.865 13.115 1.338a.935.935 0 11-.954 1.609z"/></svg>Spotify</a>` : '';
    const editBtn  = canEdit ? `<button class="btn btn-ghost" style="padding:4px 7px" onclick="event.stopPropagation();openEditSong('${s.id}')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>` : '';

    let dragTd = '<td class="td-drag no-print" style="display:none"></td>';
    let numCell = `<td class="td-num">${i+1}</td>`;
    if(canOrder){
      let opts = '';
      for(let p=1;p<=total;p++) opts+=`<option value="${p}"${p===i+1?' selected':''}>${p}</option>`;
      dragTd = `<td class="td-drag no-print"><div style="display:flex;align-items:center;gap:3px">
        <span class="drag-handle" title="Arrastar para reordenar">
          <svg viewBox="0 0 24 24" fill="currentColor" width="12" height="12"><path d="M8 6a2 2 0 100-4 2 2 0 000 4zm0 8a2 2 0 100-4 2 2 0 000 4zm0 8a2 2 0 100-4 2 2 0 000 4zm8-16a2 2 0 100-4 2 2 0 000 4zm0 8a2 2 0 100-4 2 2 0 000 4zm0 8a2 2 0 100-4 2 2 0 000 4z"/></svg>
        </span>
        <select class="pos-select" onchange="event.stopPropagation();moveToPosition('${s.id}',this.value,this)">${opts}</select>
      </div></td>`;
      numCell = `<td class="td-num" style="color:var(--text3)">${i+1}</td>`;
    }

    const isSel = selectedSongIds.has(s.id);
    const durTd = `<td class="td-dur">${fmtDuration(s.duration_ms)}</td>`;
    html += `<tr class="song-row${isSel?' selected':''}" data-id="${s.id}" onclick="toggleSongSelection('${s.id}')">
      ${dragTd}${numCell}
      <td>
        <div class="td-title">${escH(s.title)}</div>
        <div class="td-artist">${escH(s.artist)}</div>
        ${spotHtml?'<div class="td-meta">'+spotHtml+'</div>':''}
      </td>
      ${durTd}
      <td><div style="display:flex;flex-wrap:wrap;gap:4px">${tagsHtml}</div></td>
      <td>${keyHtml}</td><td>${rHtml}</td><td>${bpmHtml}</td>
      <td class="td-actions no-print"><div class="td-actions-inner">${editBtn}</div></td>
    </tr>`;

    // Blocos após música i + botão "add bloco"
    (blocksByPos[i]||[]).forEach(b => { html += blockRowHtml(b); });
    if(canOrder) html += addBlockBtnHtml(i);
  });

  tbody.innerHTML = html;
  if(canOrder) initDragDrop();
}

// ── Ordenação por drag-and-drop e dropdown ────────────────────────
let _dragSrc = null;
let _saveOrderTimer = null;

function initDragDrop(){
  const rows = document.querySelectorAll('#songBody tr.song-row');
  rows.forEach(row => {
    const handle = row.querySelector('.drag-handle');
    if(!handle) return;

    handle.addEventListener('mousedown', () => { row.setAttribute('draggable', 'true'); });
    handle.addEventListener('mouseup',   () => { row.setAttribute('draggable', 'false'); });

    row.addEventListener('dragstart', e => {
      _dragSrc = row;
      row.classList.add('dragging');
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', row.dataset.id);
    });
    row.addEventListener('dragend', () => {
      row.classList.remove('dragging');
      document.querySelectorAll('#songBody tr.drag-over').forEach(r => r.classList.remove('drag-over'));
      row.setAttribute('draggable', 'false');
    });
    row.addEventListener('dragover', e => {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      if(row !== _dragSrc){
        document.querySelectorAll('#songBody tr.drag-over').forEach(r => r.classList.remove('drag-over'));
        row.classList.add('drag-over');
      }
    });
    row.addEventListener('dragleave', () => { row.classList.remove('drag-over'); });
    row.addEventListener('drop', e => {
      e.preventDefault();
      row.classList.remove('drag-over');
      if(!_dragSrc || _dragSrc === row) return;
      const tbody = document.getElementById('songBody');
      const rows  = [...tbody.querySelectorAll('tr.song-row')];
      const srcIdx = rows.indexOf(_dragSrc);
      const dstIdx = rows.indexOf(row);
      if(srcIdx < dstIdx) tbody.insertBefore(_dragSrc, row.nextSibling);
      else                tbody.insertBefore(_dragSrc, row);
      _dragSrc = null;
      _commitOrderFromDOM();
    });
  });
}

function moveToPosition(songId, newPos, selectEl){
  event.stopPropagation();
  const pos   = parseInt(newPos) - 1;
  const tbody = document.getElementById('songBody');
  const rows  = [...tbody.querySelectorAll('tr.song-row')];
  const srcRow = rows.find(r => r.dataset.id === songId);
  if(!srcRow) return;
  const target = rows[pos];
  if(target === srcRow) return;
  const srcIdx = rows.indexOf(srcRow);
  if(pos > srcIdx) tbody.insertBefore(srcRow, target ? target.nextSibling : null);
  else             tbody.insertBefore(srcRow, target);
  _commitOrderFromDOM();
}

function _commitOrderFromDOM(){
  // Actualiza números visíveis e dropdowns
  const tbody = document.getElementById('songBody');
  const rows  = [...tbody.querySelectorAll('tr.song-row')];
  const total = rows.length;
  const newOrder = rows.map((r, i) => {
    // Atualiza número
    const numTd = r.querySelector('td.td-num');
    if(numTd) numTd.textContent = i + 1;
    // Atualiza dropdown
    const sel = r.querySelector('select.pos-select');
    if(sel){
      let opts = '';
      for(let p = 1; p <= total; p++) opts += `<option value="${p}"${p===i+1?' selected':''}>${p}</option>`;
      sel.innerHTML = opts;
    }
    return r.dataset.id;
  });

  // Actualiza DB local imediatamente
  if(activeTagFilter && DB.tags[activeTagFilter]){
    DB.tags[activeTagFilter].song_order = newOrder;
    document.getElementById('orderModeBar').classList.add('show');
  }

  // Debounce: guarda no servidor 600ms após último movimento
  clearTimeout(_saveOrderTimer);
  _saveOrderTimer = setTimeout(() => saveTagOrder(newOrder), 600);
}

function saveTagOrder(order){
  if(!activeTagFilter) return;
  showSaving();
  post({
    _action: 'save_tag_order',
    tag_id: activeTagFilter,
    song_order: JSON.stringify(order)
  }, function(r){
    hideSaving();
    if(!r.ok) showToast('Erro ao guardar ordem.', true);
  });
}

function clearTagOrder(){
  if(!activeTagFilter) return;
  if(!confirm('Resetar a ordem personalizada desta lista? As músicas voltarão à ordem alfabética.')) return;
  DB.tags[activeTagFilter].song_order = [];
  post({
    _action: 'save_tag_order',
    tag_id: activeTagFilter,
    song_order: '[]'
  }, function(r){
    if(r.ok){ showToast('Ordem resetada.'); applyFilters(); }
    else showToast('Erro ao resetar.', true);
  });
}

// ── Marcadores de Bloco ───────────────────────────────────────────
function openAddBlock(posAfter){
  if(!activeTagFilter) return;
  const tag = DB.tags[activeTagFilter];
  if(!tag || tag.type !== 'event') return;
  const songs = [...document.querySelectorAll('#songBody tr.song-row')];
  // Popula o select de posição
  let opts = `<option value="-1"${posAfter===-1?' selected':''}>⬆️ Antes da primeira música</option>`;
  songs.forEach((row, i) => {
    const sid  = row.dataset.id;
    const song = DB.songs[sid];
    if(!song) return;
    opts += `<option value="${i}"${posAfter===i?' selected':''}>${i+1}. ${escH(song.title)}</option>`;
  });
  document.getElementById('blockPosSelect').innerHTML = opts;
  document.getElementById('blockId').value    = '';
  document.getElementById('blockTitle').value = '';
  document.getElementById('blockDesc').value  = '';
  document.getElementById('blockModalTitle').textContent = 'Adicionar Marcador de Bloco';
  document.getElementById('blockDeleteBtn').style.display = 'none';
  openModal('blockModal');
}

function openEditBlock(blockId){
  if(!activeTagFilter) return;
  const tag   = DB.tags[activeTagFilter];
  const block = (tag.blocks||[]).find(b=>b.id===blockId);
  if(!block) return;

  const songs = [...document.querySelectorAll('#songBody tr.song-row')];
  let opts = `<option value="-1"${block.pos_after===-1?' selected':''}>⬆️ Antes da primeira música</option>`;
  songs.forEach((row, i) => {
    const sid  = row.dataset.id;
    const song = DB.songs[sid];
    if(!song) return;
    opts += `<option value="${i}"${block.pos_after===i?' selected':''}>${i+1}. ${escH(song.title)}</option>`;
  });
  document.getElementById('blockPosSelect').innerHTML = opts;
  document.getElementById('blockId').value    = block.id;
  document.getElementById('blockTitle').value = block.title;
  document.getElementById('blockDesc').value  = block.description || '';
  document.getElementById('blockModalTitle').textContent = 'Editar Marcador de Bloco';
  document.getElementById('blockDeleteBtn').style.display = '';
  openModal('blockModal');
}

function submitBlock(){
  if(!activeTagFilter) return;
  const title = document.getElementById('blockTitle').value.trim();
  if(!title){ document.getElementById('blockTitle').focus(); return; }

  const tag      = DB.tags[activeTagFilter];
  const blocks   = [...(tag.blocks||[])];
  const id       = document.getElementById('blockId').value || newClientUuid();
  const posAfter = parseInt(document.getElementById('blockPosSelect').value);
  const desc     = document.getElementById('blockDesc').value.trim();

  const idx = blocks.findIndex(b=>b.id===id);
  const block = { id, pos_after: posAfter, title, description: desc };
  if(idx>=0) blocks[idx] = block;
  else blocks.push(block);

  _saveBlocks(blocks);
}

function deleteBlock(){
  if(!activeTagFilter) return;
  const id  = document.getElementById('blockId').value;
  if(!id) return;
  const tag    = DB.tags[activeTagFilter];
  const blocks = (tag.blocks||[]).filter(b=>b.id!==id);
  _saveBlocks(blocks);
}

function _saveBlocks(blocks){
  if(!activeTagFilter) return;
  DB.tags[activeTagFilter].blocks = blocks;
  closeModal('blockModal');
  showSaving();
  post({
    _action: 'save_blocks',
    tag_id:  activeTagFilter,
    blocks:  JSON.stringify(blocks)
  }, function(r){
    hideSaving();
    if(r.ok){ applyFilters(); }
    else showToast('Erro ao guardar bloco.', true);
  });
}

function newClientUuid(){
  return 'b-' + Math.random().toString(36).slice(2,10) + Date.now().toString(36);
}

// ── Seleção múltipla & ações em massa ──────────────────────────
function toggleSongSelection(sid){
  if(selectedSongIds.has(sid)) selectedSongIds.delete(sid);
  else selectedSongIds.add(sid);
  // Atualiza só a linha clicada (evita re-renderizar tudo)
  const row = document.querySelector(`#songList tr.song-row[data-id="${sid}"]`) || document.querySelector(`tr.song-row[data-id="${sid}"]`);
  if(row) row.classList.toggle('selected', selectedSongIds.has(sid));
  updateBulkBar();
}

function clearSelection(){
  selectedSongIds.clear();
  document.querySelectorAll('tr.song-row.selected').forEach(r => r.classList.remove('selected'));
  updateBulkBar();
}

function updateBulkBar(){
  const bar = document.getElementById('bulkBar');
  const count = selectedSongIds.size;
  document.getElementById('bulkCount').textContent = count + (count===1 ? ' selecionada' : ' selecionadas');
  bar.classList.toggle('show', count > 0);
  document.getElementById('mergeBtn').style.display = count >= 2 ? '' : 'none';
}

function openBulkTagModal(){
  if(!selectedSongIds.size) return;
  renderTagsPicker('bulkAddTagsPicker', []);
  document.getElementById('bulkAddErr').textContent = '';
  openModal('bulkAddModal');
}

function openBulkRemoveModal(){
  if(!selectedSongIds.size) return;
  renderTagsPicker('bulkRemoveTagsPicker', []);
  document.getElementById('bulkRemoveErr').textContent = '';
  openModal('bulkRemoveModal');
}

function submitBulkAdd(){
  const addIds = getSelectedTags('bulkAddTagsPicker');
  if(!addIds.length){ showErr('bulkAddErr','Escolhe pelo menos uma tag.'); return; }
  post({
    _action: 'bulk_tag',
    song_ids: JSON.stringify([...selectedSongIds]),
    add_tags: JSON.stringify(addIds),
    remove_tags: '[]'
  }, function(r){
    if(r.ok){
      closeModal('bulkAddModal');
      showToast(r.changed + ' música(s) atualizada(s)!');
      loadDbFromServer();
    } else showErr('bulkAddErr', r.error || 'Erro.');
  });
}

function submitBulkRemove(){
  const removeIds = getSelectedTags('bulkRemoveTagsPicker');
  if(!removeIds.length){ showErr('bulkRemoveErr','Escolhe pelo menos uma tag.'); return; }
  post({
    _action: 'bulk_tag',
    song_ids: JSON.stringify([...selectedSongIds]),
    add_tags: '[]',
    remove_tags: JSON.stringify(removeIds)
  }, function(r){
    if(r.ok){
      closeModal('bulkRemoveModal');
      showToast(r.changed + ' música(s) atualizada(s)!');
      loadDbFromServer();
    } else showErr('bulkRemoveErr', r.error || 'Erro.');
  });
}

function openMergeModal(){
  const ids = [...selectedSongIds];
  if(ids.length < 2) return;
  const baseId = ids[0];
  const base = DB.songs[baseId];
  if(!base) return;

  let html = `<p style="margin-bottom:10px"><strong>${escH(base.title)}</strong> — ${escH(base.artist)} ficará como a entrada principal (mantém título, tom, bpm, ritmo e links).</p>`;
  html += `<p style="margin-bottom:6px;color:var(--text3);font-size:.75rem">As outras ${ids.length-1} música(s) serão removidas e as suas tags/listas serão adicionadas a esta entrada:</p>`;
  html += '<ul style="margin:0 0 10px 18px;padding:0">';
  ids.slice(1).forEach(sid => {
    const s = DB.songs[sid];
    if(s) html += `<li>${escH(s.title)} — ${escH(s.artist)}</li>`;
  });
  html += '</ul>';
  document.getElementById('mergePreview').innerHTML = html;
  document.getElementById('mergeErr').textContent = '';
  openModal('mergeModal');
}

function submitMerge(){
  const ids = [...selectedSongIds];
  if(ids.length < 2){ showErr('mergeErr','Seleciona pelo menos 2 músicas.'); return; }
  post({
    _action: 'merge_songs',
    song_ids: JSON.stringify(ids)
  }, function(r){
    if(r.ok){
      closeModal('mergeModal');
      showToast((r.removed) + ' música(s) duplicada(s) removida(s)!');
      clearSelection();
      loadDbFromServer();
    } else showErr('mergeErr', r.error || 'Erro.');
  });
}

function updateStats(){
  const songs = Object.values(DB.songs||{}).filter(isSongVisible);
  const tags  = Object.values(DB.tags||{}).filter(t=>!t.archived);
  document.getElementById('statSongs').textContent = songs.length;
  document.getElementById('statTags').textContent  = tags.filter(t=>t.type==='list').length;
  document.getElementById('statEvents').textContent = tags.filter(t=>t.type==='event').length;
  document.getElementById('statCustomTags').textContent = tags.filter(t=>!['list','event'].includes(t.type)).length;
}

// ── Tags Picker ───────────────────────────────────────────────────
function renderTagsPicker(containerId, selectedTags){
  const tags = DB.tags || {};
  const el = document.getElementById(containerId);
  let html = '';
  Object.values(tags).forEach(t => {
    const sel = selectedTags.includes(t.id);
    const selCls = t.type==='list'?'sel-list':t.type==='musician'?'sel-musician':'sel-custom';
    const archStyle = t.archived ? 'opacity:.45' : '';
    const archLabel = t.archived ? ' 🗄' : '';
    html += `<span class="tp-chip ${sel?selCls:''}" style="${archStyle}" data-tid="${t.id}" onclick="toggleTagChip(this,'${containerId}','${t.type}')">
      ${escH(t.name)}${archLabel}
    </span>`;
  });
  if(!Object.keys(tags).length) html = '<span style="font-size:.75rem;color:var(--text3)">Nenhuma tag criada ainda. <button class="btn btn-ghost" style="padding:2px 6px;font-size:.72rem" onclick="closeModal(\'addSongModal\');openModal(\'addTagModal\')">Criar Tag</button></span>';
  el.innerHTML = html;
}

function toggleTagChip(el, containerId, type){
  const selCls = type==='list'?'sel-list':type==='musician'?'sel-musician':'sel-custom';
  el.classList.toggle(selCls);
}

function getSelectedTags(containerId){
  const chips = document.querySelectorAll(`#${containerId} .tp-chip`);
  const sel = [];
  chips.forEach(c => {
    if(c.className.includes('sel-')) sel.push(c.dataset.tid);
  });
  return sel;
}

// ── Add Song ──────────────────────────────────────────────────────
function openModal(id){
  // Populate rhythm selects dynamically
  if(id==='addSongModal'){ renderTagsPicker('asTagsPicker',[]); populateRhythmSelects(); }
  // Reset spotify import modal
  if(id==='spotifyImportModal'){
    document.getElementById('siInput').value = '';
    document.getElementById('siTagName').value = '';
    document.getElementById('siTagType').value = 'list';
    document.getElementById('spotifyImportResult').innerHTML = '';
    document.getElementById('siProgress').style.display = 'none';
    document.getElementById('siBtnImport').disabled = false;
  }
  document.getElementById(id).classList.add('open');
  setTimeout(()=>{ const f=document.querySelector(`#${id} input.fi`); if(f) f.focus(); },150);
}
function closeModal(id){ document.getElementById(id).classList.remove('open'); }

function populateRhythmSelects(){
  const rhythms = DB.settings?.rhythms || [];
  ['asRhythm','esRhythm'].forEach(sid => {
    const sel = document.getElementById(sid);
    if(!sel) return;
    const cur = sel.value;
    sel.innerHTML = '<option value="">—</option>';
    rhythms.forEach(r => { sel.innerHTML += `<option value="${r}">${escH(r)}</option>`; });
    sel.innerHTML += `<option value="__new__">+ Novo ritmo…</option>`;
    if(cur) sel.value = cur;
    sel.onchange = function(){ if(this.value==='__new__'){ this.value=''; openModal('addRhythmModal'); } };
  });
}

function submitAddSong(){
  const title  = document.getElementById('asTitle').value.trim();
  const artist = document.getElementById('asArtist').value.trim();
  if(!title||!artist){ showErr('addSongErr','Título e artista são obrigatórios.'); return; }
  const tags       = getSelectedTags('asTagsPicker');
  const spotifyUrl = document.getElementById('asSpotify').value;
  const durMs      = !spotifyUrl ? parseDurationInput(document.getElementById('asDuration').value) : 0;
  post({
    _action:'add_song', title, artist,
    key: document.getElementById('asKey').value,
    bpm: document.getElementById('asBpm').value,
    rhythm: document.getElementById('asRhythm').value,
    spotify_url: spotifyUrl,
    duration_ms: durMs,
    notes: document.getElementById('asNotes').value,
    tags: JSON.stringify(tags)
  }, function(r){
    if(r.ok){
      closeModal('addSongModal');
      showToast('Música adicionada!');
      loadDbFromServer();
    } else { showErr('addSongErr', r.error||'Erro.'); }
  });
}

// ── Edit Song ─────────────────────────────────────────────────────
// Converte "mm:ss" ou "m:ss" para ms. Retorna 0 se inválido.
function parseDurationInput(str){
  str = (str||'').trim();
  if(!str) return 0;
  const parts = str.split(':');
  if(parts.length===2){
    const m = parseInt(parts[0]), s = parseInt(parts[1]);
    if(!isNaN(m)&&!isNaN(s)&&s<60) return (m*60+s)*1000;
  }
  if(parts.length===3){
    const h=parseInt(parts[0]),m=parseInt(parts[1]),s=parseInt(parts[2]);
    if(!isNaN(h)&&!isNaN(m)&&!isNaN(s)) return (h*3600+m*60+s)*1000;
  }
  return 0;
}

function openEditSong(uuid){
  const s = DB.songs[uuid];
  if(!s) return;
  document.getElementById('esId').value = uuid;
  document.getElementById('esSpotifyUri').value = s.spotify_uri||'';
  document.getElementById('esTitle').value  = s.title||'';
  document.getElementById('esArtist').value = s.artist||'';
  document.getElementById('esBpm').value    = s.bpm||'';
  document.getElementById('esSpotify').value= s.spotify_url||'';
  document.getElementById('esNotes').value  = s.notes||'';
  document.getElementById('esDuration').value = s.duration_ms ? fmtDuration(s.duration_ms) : '';
  document.getElementById('editSongSub').textContent = s.title;
  renderTagsPicker('esTagsPicker', s.tags||[]);
  populateRhythmSelects();
  setTimeout(()=>{
    document.getElementById('esKey').value    = s.key||'';
    document.getElementById('esRhythm').value = s.rhythm||'';
  },50);
  clearErr('editSongErr');
  document.getElementById('editSongModal').classList.add('open');
}

function submitEditSong(){
  const uuid = document.getElementById('esId').value;
  const title  = document.getElementById('esTitle').value.trim();
  const artist = document.getElementById('esArtist').value.trim();
  if(!title||!artist){ showErr('editSongErr','Título e artista obrigatórios.'); return; }
  const tags = getSelectedTags('esTagsPicker');
  const spotifyUrl = document.getElementById('esSpotify').value.trim();
  const spotifyUri = document.getElementById('esSpotifyUri').value.trim();
  // Duração: usa o campo (pode ter sido preenchido pelo fetch Spotify ou manualmente)
  const existingSong = DB.songs[uuid]||{};
  const manualDur = parseDurationInput(document.getElementById('esDuration').value);
  const durMs = manualDur || existingSong.duration_ms || 0;
  post({
    _action:'edit_song', song_id: uuid, title, artist,
    key: document.getElementById('esKey').value,
    bpm: document.getElementById('esBpm').value,
    rhythm: document.getElementById('esRhythm').value,
    spotify_url: spotifyUrl,
    spotify_uri: spotifyUri,
    duration_ms: durMs,
    notes: document.getElementById('esNotes').value,
    tags: JSON.stringify(tags)
  }, function(r){
    if(r.ok){ closeModal('editSongModal'); showToast('Música atualizada!'); loadDbFromServer(); }
    else showErr('editSongErr', r.error||'Erro.');
  });
}

function doDeleteSong(){
  const uuid = document.getElementById('esId').value;
  const s = DB.songs[uuid];
  if(!s || !confirm(`Apagar "${s.title}"? Esta ação não pode ser desfeita.`)) return;
  post({ _action:'delete_song', song_id: uuid }, function(r){
    if(r.ok){ closeModal('editSongModal'); showToast('Música apagada.'); loadDbFromServer(); }
    else showToast('Erro ao apagar.', true);
  });
}

// ── Tags ──────────────────────────────────────────────────────────
function toggleEventFields(prefix){
  const type = document.getElementById(prefix+'Type').value;
  document.getElementById(prefix+'EventFields').style.display = (type==='event') ? '' : 'none';
}

function submitAddTag(){
  const name = document.getElementById('atName').value.trim();
  const type = document.getElementById('atType').value;
  const spot = document.getElementById('atSpotify').value.trim();
  if(!name){ showErr('addTagErr','Nome obrigatório.'); return; }
  post({
    _action:'add_tag', name, type, spotify_id: spot,
    event_date: document.getElementById('atEventDate').value,
    event_time: document.getElementById('atEventTime').value,
    event_location: document.getElementById('atEventLocation').value
  }, function(r){
    if(r.ok){ closeModal('addTagModal'); showToast('Tag criada!'); loadDbFromServer(); }
    else showErr('addTagErr', r.error||'Erro.');
  });
}

function openTagManager(){
  renderTagManager();
  document.getElementById('tagManagerModal').classList.add('open');
}

function openModal_tm(){ openTagManager(); }

function renderTagManager(){
  const tags = DB.tags || {};
  const songs = Object.values(DB.songs||{});
  const el = document.getElementById('tagManagerBody');
  if(!Object.keys(tags).length){
    el.innerHTML = '<div class="empty-state" style="padding:30px 0"><p>Nenhuma tag ainda.</p></div>';
    return;
  }
  const typeLabel = { list:'Lista', event:'Evento', musician:'Músico', status:'Status', custom:'Tag' };
  const typeCls   = { list:'chip-list', event:'chip-event', musician:'chip-musician', status:'chip-custom', custom:'chip-custom' };
  let html = '<div style="display:flex;flex-direction:column;gap:4px">';
  // Tags ativas primeiro, depois arquivadas
  const sorted = Object.values(tags).sort((a,b)=> (a.archived?1:0) - (b.archived?1:0));
  sorted.forEach(t => {
    const cnt = songs.filter(s=>(s.tags||[]).includes(t.id)).length;
    const archived = !!t.archived;
    const archIcon = archived
      ? '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M3 7l1.5-3h15L21 7"/><path d="M5 7v12a1 1 0 001 1h12a1 1 0 001-1V7"/><path d="M10 12h4"/></svg>'
      : '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M3 7l1.5-3h15L21 7"/><path d="M5 7v12a1 1 0 001 1h12a1 1 0 001-1V7"/><path d="M9 12h6"/></svg>';
    const eventInfo = (t.type==='event' && (t.event_date||t.event_location))
      ? `<div style="font-size:.62rem;color:var(--text3);margin-top:1px">${escH(formatEventDate(t.event_date))}${t.event_time?' · '+escH(t.event_time):''}${t.event_location?' · '+escH(t.event_location):''}</div>`
      : '';
    html += `<div style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:var(--r);border:1px solid var(--border);background:var(--bg3);cursor:pointer;${archived?'opacity:.5':''}" onclick="openEditTag('${t.id}')">
      <span class="chip ${typeCls[t.type]||'chip-custom'}">${typeLabel[t.type]||t.type}</span>
      <div style="flex:1">
        <div style="font-size:.83rem">${escH(t.name)}${archived?' <span style=\"font-size:.6rem;color:var(--text3)\">(arquivada)</span>':''}</div>
        ${eventInfo}
      </div>
      <span style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--text3)">${cnt} músicas</span>
      <button class="btn btn-ghost" style="padding:4px 7px" title="${archived?'Desarquivar':'Arquivar'}" onclick="event.stopPropagation();toggleArchiveTag('${t.id}')">${archIcon}</button>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12" style="color:var(--text3)"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
    </div>`;
  });
  html += '</div>';
  el.innerHTML = html;
}

function formatEventDate(dateStr){
  if(!dateStr) return '';
  const d = new Date(dateStr + 'T00:00:00');
  if(isNaN(d)) return dateStr;
  return d.toLocaleDateString('pt-PT', { day:'2-digit', month:'long', year:'numeric' });
}

function toggleArchiveTag(tid){
  post({ _action:'toggle_archive_tag', tag_id: tid }, function(r){
    if(r.ok){
      showToast(r.archived ? 'Tag arquivada.' : 'Tag desarquivada.');
      loadDbFromServer();
      setTimeout(renderTagManager, 300);
    } else showToast(r.error || 'Erro.', true);
  });
}

function openEditTag(tid){
  const t = (DB.tags || {})[tid];
  if(!t) return;
  document.getElementById('etId').value = tid;
  document.getElementById('etName').value = t.name || '';
  document.getElementById('etType').value = t.type || 'custom';
  document.getElementById('etSpotify').value = t.spotify_id || '';
  document.getElementById('etEventDate').value = t.event_date || '';
  document.getElementById('etEventTime').value = t.event_time || '';
  document.getElementById('etEventLocation').value = t.event_location || '';
  toggleEventFields('et');
  closeModal('tagManagerModal');
  document.getElementById('editTagModal').classList.add('open');
}

function submitEditTag(){
  const tid  = document.getElementById('etId').value;
  const name = document.getElementById('etName').value.trim();
  if(!name){ showErr('editTagErr','Nome obrigatório.'); return; }
  post({
    _action:'edit_tag', tag_id:tid, name, type: document.getElementById('etType').value,
    spotify_id: document.getElementById('etSpotify').value,
    event_date: document.getElementById('etEventDate').value,
    event_time: document.getElementById('etEventTime').value,
    event_location: document.getElementById('etEventLocation').value
  }, function(r){
    if(r.ok){ closeModal('editTagModal'); showToast('Tag atualizada!'); loadDbFromServer(); setTimeout(renderTagManager, 300); }
    else showErr('editTagErr', r.error||'Erro.');
  });
}

function doDeleteTag(){
  const tid = document.getElementById('etId').value;
  const t   = DB.tags[tid];
  if(!t || !confirm(`Apagar tag "${t.name}"? Será removida de todas as músicas.`)) return;
  post({ _action:'delete_tag', tag_id:tid }, function(r){
    if(r.ok){ closeModal('editTagModal'); showToast('Tag apagada.'); loadDbFromServer(); setTimeout(renderTagManager, 300); }
    else showToast('Erro ao apagar.', true);
  });
}

// Wire up tag manager button correctly
document.querySelectorAll('[onclick*="tagManagerModal"]').forEach(el => {
  el.addEventListener('click', function(){ setTimeout(renderTagManager, 80); });
});

// ── Add Rhythm ────────────────────────────────────────────────────
function submitAddRhythm(){
  const r = document.getElementById('arName').value.trim();
  if(!r) return;
  post({ _action:'add_rhythm', rhythm: r }, function(res){
    if(res.ok){
      DB.settings.rhythms = res.rhythms;
      closeModal('addRhythmModal');
      document.getElementById('arName').value = '';
      showToast('Ritmo adicionado!');
      populateRhythmSelects();
      renderFilterDropdowns();
    }
  });
}

// ── Copiar Lista para Clipboard ───────────────────────────────────
function copyListToClipboard(){
  const tags  = DB.tags || {};
  const q       = document.getElementById('searchInput').value.toLowerCase().trim();
  const fTag    = document.getElementById('filterTag').value;
  const fRhythm = document.getElementById('filterRhythm').value;
  const fKey    = document.getElementById('filterKey').value;
  const sortBy  = document.getElementById('sortBy').value;

  let visible = Object.values(DB.songs || {}).filter(isSongVisible);
  if(activeTagFilter) visible = visible.filter(s=>(s.tags||[]).includes(activeTagFilter));
  if(fTag)    visible = visible.filter(s=>(s.tags||[]).includes(fTag));
  if(fRhythm) visible = visible.filter(s=>s.rhythm===fRhythm);
  if(fKey)    visible = visible.filter(s=>s.key===fKey);
  if(q)       visible = visible.filter(s=>(s.title||'').toLowerCase().includes(q)||(s.artist||'').toLowerCase().includes(q));

  // Respeita ordem personalizada (mesma lógica do applyFilters)
  const activeTag = activeTagFilter ? tags[activeTagFilter] : null;
  const hasCustomOrder = activeTag && (activeTag.song_order||[]).length > 0;
  const hasExtraFilters = !!(fTag || fRhythm || fKey || q);
  if(hasCustomOrder && !hasExtraFilters){
    const orderMap = {};
    (activeTag.song_order||[]).forEach((id, i) => { orderMap[id] = i; });
    visible.sort((a, b) => {
      const ia = orderMap[a.id] !== undefined ? orderMap[a.id] : 99999;
      const ib = orderMap[b.id] !== undefined ? orderMap[b.id] : 99999;
      if(ia !== ib) return ia - ib;
      return (a.title||'').localeCompare(b.title||'', 'pt');
    });
  } else {
    visible.sort((a,b)=>{
      const va=(a[sortBy]||a.title||'').toLowerCase();
      const vb=(b[sortBy]||b.title||'').toLowerCase();
      return va.localeCompare(vb,'pt');
    });
  }

  if(!visible.length){ showToast('Nenhuma música para copiar.', true); return; }

  // Cabeçalho com metadados do evento/lista activa
  const lines = [];

  if(activeTag){
    const isEvent = activeTag.type === 'event';
    lines.push(isEvent ? '🎤 SETLIST DE EVENTO' : '📋 SETLIST');
    lines.push(activeTag.name);
    if(isEvent){
      if(activeTag.event_date){
        const d = new Date(activeTag.event_date + 'T00:00:00');
        if(!isNaN(d)) lines.push('📅 ' + d.toLocaleDateString('pt-PT',{day:'2-digit',month:'long',year:'numeric'}));
      }
      if(activeTag.event_time)     lines.push('🕒 ' + activeTag.event_time);
      if(activeTag.event_location) lines.push('📍 ' + activeTag.event_location);
    }
  } else {
    lines.push('📋 SETLIST');
    lines.push('Todas as Músicas');
  }

  lines.push('🗓 Gerado em ' + new Date().toLocaleDateString('pt-PT'));
  lines.push(visible.length + ' músicas');
  lines.push('');
  lines.push('─'.repeat(40));
  lines.push('');

  // Lista: "Título - Artista"
  visible.forEach((s, i) => {
    lines.push(`${i+1}. ${s.title} - ${s.artist}`);
  });

  const text = lines.join('\n');

  if(navigator.clipboard && navigator.clipboard.writeText){
    navigator.clipboard.writeText(text).then(()=>{
      showToast('Lista copiada! (' + visible.length + ' músicas)');
    }).catch(()=>{
      fallbackCopy(text);
    });
  } else {
    fallbackCopy(text);
  }
}

function fallbackCopy(text){
  const ta = document.createElement('textarea');
  ta.value = text;
  ta.style.cssText = 'position:fixed;top:-9999px;left:-9999px;opacity:0';
  document.body.appendChild(ta);
  ta.select();
  try {
    document.execCommand('copy');
    showToast('Lista copiada para o clipboard!');
  } catch(e){
    showToast('Não foi possível copiar. Tenta manualmente.', true);
  }
  document.body.removeChild(ta);
}

// ── Importar do Spotify ───────────────────────────────────────────
function submitSpotifyImport(){
  const input   = document.getElementById('siInput').value.trim();
  const tagName = document.getElementById('siTagName').value.trim();
  const tagType = document.getElementById('siTagType').value;

  if(!input){
    document.getElementById('spotifyImportResult').innerHTML =
      '<div class="spot-import-result err">Cola o URL ou ID da playlist do Spotify.</div>';
    return;
  }

  // UI: loading
  document.getElementById('spotifyImportResult').innerHTML = '';
  document.getElementById('siProgress').style.display = 'flex';
  document.getElementById('siBtnImport').disabled = true;

  post({
    _action: 'import_from_spotify',
    spotify_input: input,
    tag_name: tagName,
    tag_type: tagType
  }, function(r){
    document.getElementById('siProgress').style.display = 'none';
    document.getElementById('siBtnImport').disabled = false;

    if(r.ok){
      document.getElementById('spotifyImportResult').innerHTML =
        `<div class="spot-import-result ok">
          ✅ <strong>${escH(r.playlist_name)}</strong> importada!<br>
          <span style="font-size:.75rem">${r.added} novas músicas adicionadas · ${r.updated} já existentes actualizadas · tag <em>"${escH(r.tag_name)}"</em> criada/actualizada.</span>
        </div>`;
      showToast(`Spotify: ${r.added} músicas importadas!`);
      loadDbFromServer();
    } else {
      document.getElementById('spotifyImportResult').innerHTML =
        `<div class="spot-import-result err">❌ ${escH(r.error||'Erro desconhecido.')}</div>`;
    }
  });
}

// ── Import JSON (upload de ficheiro) ─────────────────────────────
function doImportOriginals(){
  const input = document.createElement('input');
  input.type = 'file';
  input.accept = '.json,application/json';
  input.onchange = function(e){
    const file = e.target.files[0];
    if(!file) return;
    const reader = new FileReader();
    reader.onload = function(ev){
      let data;
      try { data = JSON.parse(ev.target.result); } catch(err){ showToast('Ficheiro JSON inválido.', true); return; }
      if(!confirm(`Importar "${file.name}"?\n\nIsto irá substituir o banco de dados atual (setlist_v2_db.json).\nEsta ação não pode ser desfeita.`)) return;
      showSaving();
      post({ _action:'import_from_json', json_data: JSON.stringify(data) }, function(r){
        hideSaving();
        if(r.ok){ showToast(`Importado! ${r.songs} músicas, ${r.tags} tags.`); loadDbFromServer(); }
        else showToast(r.error||'Erro na importação.', true);
      });
    };
    reader.readAsText(file);
  };
  input.click();
}

// ── Ler duração da track Spotify ao vincular ──────────────────────
// Feito inteiramente no browser (evita limitações de curl no servidor)
const _spotClientId     = '<?= addslashes(envVal('CLIENT_ID') ?: envVal('SPOTIPY_CLIENT_ID') ?: '') ?>';
const _spotClientSecret = '<?= addslashes(envVal('CLIENT_SECRET') ?: envVal('SPOTIPY_CLIENT_SECRET') ?: '') ?>';
let _spotClientToken = null;

async function getSpotClientToken(){
  if(_spotClientToken) return _spotClientToken;
  if(!_spotClientId || !_spotClientSecret) return null;
  try {
    const r = await fetch('https://accounts.spotify.com/api/token', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'Authorization': 'Basic ' + btoa(_spotClientId + ':' + _spotClientSecret)
      },
      body: 'grant_type=client_credentials'
    });
    const data = await r.json();
    _spotClientToken = data.access_token || null;
    return _spotClientToken;
  } catch(e){ return null; }
}

async function fetchSpotifyDuration(inputEl, durFieldId){
  const url = (inputEl.value||'').trim();
  if(!url) return;
  const m = url.match(/track\/([A-Za-z0-9]+)/);
  if(!m){ showToast('URL de track inválido.', true); return; }
  const trackId = m[1];
  const durEl = document.getElementById(durFieldId);
  const btnEl = inputEl.parentElement.querySelector('button');
  const origLabel = btnEl ? btnEl.textContent : '';
  if(btnEl){ btnEl.disabled = true; btnEl.textContent = 'A buscar…'; }

  try {
    // Tenta com user token primeiro (se disponível), senão client credentials
    const token = _spotifyUserToken || await getSpotClientToken();
    if(!token){ showToast('Sem token Spotify disponível.', true); return; }
    const r = await fetch(`https://api.spotify.com/v1/tracks/${trackId}`, {
      headers: { 'Authorization': 'Bearer ' + token }
    });
    if(!r.ok){ showToast('Track não encontrada no Spotify.', true); return; }
    const data = await r.json();
    if(data.duration_ms){
      durEl.value = fmtDuration(data.duration_ms);
      durEl.style.color = '#1db954';
      setTimeout(()=>{ durEl.style.color=''; }, 2000);
      // Guardar URI
      const uriEl = document.getElementById('esSpotifyUri');
      if(uriEl && data.uri) uriEl.value = data.uri;
      showToast('Duração obtida do Spotify!');
    }
  } catch(e){
    showToast('Erro ao contactar Spotify.', true);
  } finally {
    if(btnEl){ btnEl.disabled = false; btnEl.textContent = origLabel; }
  }
}

// ── Exportar tag/lista para Spotify ──────────────────────────────
let _spotifyUserToken = null;
let _spotifyAuthWindow = null;
let _spotifyAuthInterval = null;
const SPOTIFY_SCOPES = 'playlist-modify-private playlist-modify-public';

function openExportSpotify(){
  if(!activeTagFilter){ showToast('Seleciona uma lista ou evento primeiro.', true); return; }
  const tag = (DB.tags||{})[activeTagFilter];
  if(!tag){ showToast('Tag não encontrada.', true); return; }

  // Info da tag
  const songs = Object.values(DB.songs||{}).filter(s=>(s.tags||[]).includes(activeTagFilter));
  const withSpotify = songs.filter(s=>s.spotify_uri||(s.spotify_url||'').includes('track/'));
  document.getElementById('spotifyExportInfo').innerHTML =
    `<strong>${escH(tag.name)}</strong> — ${songs.length} músicas, ${withSpotify.length} com link Spotify`;

  const existing = tag.spotify_id||'';
  document.getElementById('seExistingPlaylist').style.display = existing ? '' : 'none';
  if(existing){
    document.getElementById('seExistingPlaylist').innerHTML =
      `Playlist já vinculada: <a href="https://open.spotify.com/playlist/${escH(existing)}" target="_blank" style="color:#1db954">abrir no Spotify</a>. Será <strong>substituída</strong> pelas músicas actuais.`;
    document.getElementById('seExportBtnLabel').textContent = 'Actualizar Playlist';
  } else {
    document.getElementById('seExportBtnLabel').textContent = 'Criar Playlist';
  }

  if(withSpotify.length < songs.length){
    const missing = songs.length - withSpotify.length;
    document.getElementById('seTrackWarning').style.display = '';
    document.getElementById('seTrackWarning').textContent =
      `⚠ ${missing} música${missing>1?'s':''} sem link Spotify ${missing>1?'serão ignoradas':'será ignorada'}.`;
  } else {
    document.getElementById('seTrackWarning').style.display = 'none';
  }

  document.getElementById('spotifyExportErr').innerHTML = '';
  document.getElementById('seExportResult').style.display = 'none';

  // Se já temos token, vai directo para passo 2
  if(_spotifyUserToken){
    showSeStep2();
  } else {
    document.getElementById('seStep1').style.display = '';
    document.getElementById('seAuthWaiting').style.display = 'none';
    document.getElementById('seStep2').style.display = 'none';
    document.getElementById('seExportBtn').style.display = 'none';
  }
  openModal('spotifyExportModal');
}

async function startSpotifyAuth(){
  const clientId = '<?= addslashes(envVal('CLIENT_ID') ?: envVal('SPOTIPY_CLIENT_ID') ?: getenv('CLIENT_ID') ?: '') ?>';
  if(!clientId){ showErr('spotifyExportErr','CLIENT_ID não configurado no .env.'); return; }
  // Usar exactamente a mesma URI que o PHP usa no callback (evita mismatch)
  const redirectUri = '<?= addslashes(spotifyRedirectUri()) ?>';

  // Gerar PKCE code_verifier e code_challenge
  const verifier = generateCodeVerifier();
  const challenge = await generateCodeChallenge(verifier);
  sessionStorage.setItem('spotify_pkce_verifier', verifier);

  const state = Math.random().toString(36).slice(2);
  sessionStorage.setItem('spotify_oauth_state', state);
  const params = new URLSearchParams({
    response_type:'code', client_id:clientId,
    scope:SPOTIFY_SCOPES, redirect_uri:redirectUri,
    state, code_challenge_method:'S256', code_challenge:challenge
  });
  const authUrl = 'https://accounts.spotify.com/authorize?' + params;
  _spotifyAuthWindow = window.open(authUrl, 'spotify_auth', 'width=480,height=640');
  document.getElementById('seAuthWaiting').style.display = '';
  _spotifyAuthInterval = setInterval(checkSpotifyAuthAuto, 1000);
}

function generateCodeVerifier(){
  const arr = new Uint8Array(64);
  crypto.getRandomValues(arr);
  return btoa(String.fromCharCode(...arr)).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
}

async function generateCodeChallenge(verifier){
  const data = new TextEncoder().encode(verifier);
  const digest = await crypto.subtle.digest('SHA-256', data);
  return btoa(String.fromCharCode(...new Uint8Array(digest))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
}

function spotifyAuthError(msg){
  showErr('spotifyExportErr', 'Erro de autorização Spotify: ' + msg);
  document.getElementById('seAuthWaiting').style.display = 'none';
  clearInterval(_spotifyAuthInterval);
}

function checkSpotifyAuthAuto(){
  try {
    if(!_spotifyAuthWindow || _spotifyAuthWindow.closed){
      clearInterval(_spotifyAuthInterval);
      checkSpotifyAuthManual();
      return;
    }
    const hash = _spotifyAuthWindow.location.hash;
    if(hash && hash.includes('access_token')){
      extractSpotifyToken(_spotifyAuthWindow.location.href);
      _spotifyAuthWindow.close();
      clearInterval(_spotifyAuthInterval);
    }
  } catch(e){ /* cross-origin durante redirect — normal */ }
}

function checkSpotifyAuthManual(){
  clearInterval(_spotifyAuthInterval);
  // Tenta ler da sessionStorage (a callback page vai guardar lá)
  const token = sessionStorage.getItem('spotify_access_token');
  if(token){ _spotifyUserToken = token; showSeStep2(); }
  else showErr('spotifyExportErr','Autorização não detectada. Tenta novamente.');
}

function extractSpotifyToken(token){
  if(token){
    _spotifyUserToken = token;
    sessionStorage.setItem('spotify_access_token', token);
    showSeStep2();
  }
}

function showSeStep2(){
  document.getElementById('seStep1').style.display = 'none';
  document.getElementById('seStep2').style.display = '';
  document.getElementById('seExportBtn').style.display = '';
  // Confirmar utilizador
  fetch('https://api.spotify.com/v1/me',{headers:{Authorization:'Bearer '+_spotifyUserToken}})
    .then(r=>r.json()).then(me=>{
      if(me.id) document.getElementById('seAuthedAs').textContent = `Conta: ${me.display_name||me.id}`;
      else { _spotifyUserToken=null; showErr('spotifyExportErr','Token inválido. Autoriza novamente.'); document.getElementById('seStep1').style.display=''; document.getElementById('seStep2').style.display='none'; document.getElementById('seExportBtn').style.display='none'; }
    }).catch(()=>{});
}

function doExportToSpotify(){
  if(!activeTagFilter||!_spotifyUserToken) return;
  const btn = document.getElementById('seExportBtn');
  const label = document.getElementById('seExportBtnLabel');
  btn.disabled = true; label.textContent = 'A exportar…';
  showSaving();
  post({
    _action:'export_tag_to_spotify',
    tag_id: activeTagFilter,
    user_token: _spotifyUserToken
  }, function(r){
    hideSaving();
    btn.disabled = false;
    if(r.ok){
      label.textContent = 'Actualizar Playlist';
      const el = document.getElementById('seExportResult');
      el.style.display = '';
      el.innerHTML = `<div style="padding:10px 12px;background:rgba(29,185,84,.1);border:1px solid rgba(29,185,84,.3);border-radius:8px;font-size:.8rem">
        ✅ ${r.tracks} músicas exportadas!<br>
        <a href="${escH(r.playlist_url)}" target="_blank" style="color:#1db954;font-weight:600">Abrir playlist no Spotify →</a>
      </div>`;
      document.getElementById('seExistingPlaylist').style.display = '';
      document.getElementById('seExistingPlaylist').innerHTML =
        `Playlist vinculada: <a href="${escH(r.playlist_url)}" target="_blank" style="color:#1db954">abrir no Spotify</a>`;
      loadDbFromServer();
      showToast(`Playlist exportada! ${r.tracks} músicas.`);
    } else {
      label.textContent = 'Tentar novamente';
      showErr('spotifyExportErr', r.error||'Erro ao exportar.');
    }
  });
}

// Mostrar botão "Exportar para Spotify" quando tag activa tem músicas com Spotify
function updateExportSpotifyBtn(){
  const btn = document.getElementById('exportSpotifyBtn');
  if(!btn) return;
  if(!activeTagFilter){ btn.style.display='none'; return; }
  const tag = (DB.tags||{})[activeTagFilter];
  if(!tag){ btn.style.display='none'; return; }
  const songs = Object.values(DB.songs||{}).filter(s=>(s.tags||[]).includes(activeTagFilter));
  const hasSpotify = songs.some(s=>s.spotify_uri||(s.spotify_url||'').includes('track/'));
  btn.style.display = hasSpotify ? '' : 'none';
}

// ── Calendário de Eventos ─────────────────────────────────────────
function openCalendarModal(){
  const tags = DB.tags || {};
  const today = new Date().toISOString().slice(0,10);
  const events = Object.values(tags)
    .filter(t => t.type === 'event' && !t.archived && t.event_date)
    .sort((a,b) => b.event_date.localeCompare(a.event_date)); // mais recente primeiro

  if(!events.length){
    document.getElementById('calendarModalBody').innerHTML =
      '<div style="padding:24px;text-align:center;color:var(--text3);font-size:.8rem">Nenhum evento com data cadastrado.</div>';
    openModal('calendarModal');
    return;
  }

  // Agrupar por mês/ano
  const byMonth = {};
  events.forEach(t => {
    const [year, month] = t.event_date.split('-');
    const key = `${year}-${month}`;
    if(!byMonth[key]) byMonth[key] = [];
    byMonth[key].push(t);
  });

  const monthNames = ['Janeiro','Fevereiro','Março','Abril','Maio','Junho','Julho','Agosto','Setembro','Outubro','Novembro','Dezembro'];

  let html = '';
  Object.keys(byMonth).sort((a,b)=>b.localeCompare(a)).forEach(key => {
    const [year, month] = key.split('-');
    const label = `${monthNames[parseInt(month)-1]} ${year}`;
    html += `<div style="padding:6px 20px 2px;font-family:'DM Mono',monospace;font-size:.5rem;letter-spacing:.18em;text-transform:uppercase;color:var(--text3);border-top:1px solid var(--border)">${escH(label)}</div>`;
    byMonth[key].forEach(t => {
      const isPast = t.event_date < today;
      const d = new Date(t.event_date + 'T00:00:00');
      const dayName = ['Dom','Seg','Ter','Qua','Qui','Sex','Sáb'][d.getDay()];
      const dayNum  = String(d.getDate()).padStart(2,'0');
      const dateStr = `${dayName}, ${dayNum}`;
      const timeStr = t.event_time ? ` · ${t.event_time}` : '';
      const locStr  = t.event_location ? `<span style="color:var(--text3)"> · ${escH(t.event_location)}</span>` : '';
      const pastStyle = isPast ? 'opacity:.55' : '';
      const dot = isPast ? '●' : '◆';
      html += `<div onclick="closeModal('calendarModal');setTagFilter('${t.id}')"
        style="display:flex;align-items:center;gap:12px;padding:10px 20px;cursor:pointer;transition:background var(--tr);${pastStyle}"
        onmouseover="this.style.background='var(--bg3)'" onmouseout="this.style.background=''">
        <div style="width:28px;text-align:center;font-family:'DM Mono',monospace;font-size:.72rem;color:var(--gold);flex-shrink:0">${dayNum}</div>
        <div style="flex:1;min-width:0">
          <div style="font-size:.8rem;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${escH(t.name)}</div>
          <div style="font-size:.65rem;color:var(--text2);margin-top:1px">${escH(dateStr)}${escH(timeStr)}${locStr}</div>
        </div>
        ${isPast ? '<span style="font-size:.55rem;color:var(--text3);font-family:\'DM Mono\',monospace;letter-spacing:.05em">PASSADO</span>' : '<span style="font-size:.55rem;color:var(--accent);font-family:\'DM Mono\',monospace;letter-spacing:.05em">PRÓXIMO</span>'}
      </div>`;
    });
  });

  document.getElementById('calendarModalBody').innerHTML = html;
  openModal('calendarModal');
}

// ── Export DB como JSON ───────────────────────────────────────────
function exportDb(){
  const json = JSON.stringify(DB, null, 2);
  const blob = new Blob([json], { type: 'application/json' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  const date = new Date().toISOString().slice(0,10);
  a.href = url;
  a.download = `setlist_v2_backup_${date}.json`;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
  showToast('Backup exportado!');
}

// ── Print ─────────────────────────────────────────────────────────
function openPrintModal(){
  const tags = DB.tags || {};
  const activeTags = Object.values(tags).filter(t=>!t.archived);

  // ── Popula select da lista ──
  let html = '<option value="">Todas as Músicas</option>';
  const eventTags = activeTags.filter(t=>t.type==='event').sort((a,b)=>(a.event_date||'').localeCompare(b.event_date||''));
  const listTags  = activeTags.filter(t=>t.type==='list');
  const otherTags = activeTags.filter(t=>!['event','list'].includes(t.type));
  if(eventTags.length){ html+='<optgroup label="🎤 Eventos">'; eventTags.forEach(t=>{ const d=t.event_date?' — '+formatEventDate(t.event_date):''; html+=`<option value="${t.id}">${escH(t.name)}${escH(d)}</option>`; }); html+='</optgroup>'; }
  if(listTags.length){  html+='<optgroup label="📋 Listas">'; listTags.forEach(t=>{ html+=`<option value="${t.id}">${escH(t.name)}</option>`; }); html+='</optgroup>'; }
  if(otherTags.length){ html+='<optgroup label="🏷️ Tags">';  otherTags.forEach(t=>{ html+=`<option value="${t.id}">${escH(t.name)}</option>`; }); html+='</optgroup>'; }
  const sel = document.getElementById('printTagSel');
  sel.innerHTML = html;
  if(activeTagFilter && tags[activeTagFilter]) sel.value = activeTagFilter;

  sel.onchange = _renderPrintChips;
  _renderPrintChips();
  document.getElementById('printModal').classList.add('open');
}

function _renderPrintChips(){
  const tags    = DB.tags || {};
  const selTagId = document.getElementById('printTagSel').value;
  const songs   = Object.values(DB.songs || {});
  const filtered = selTagId ? songs.filter(s=>(s.tags||[]).includes(selTagId)) : songs;

  // ── Tags ──
  const relevantTagIds = new Set();
  filtered.forEach(s => (s.tags||[]).forEach(tid => { if(tags[tid]) relevantTagIds.add(tid); }));
  relevantTagIds.delete(selTagId);
  const sortedTags = [...relevantTagIds].map(id=>tags[id]).filter(Boolean).sort((a,b)=>{
    const o={event:0,list:1}; return (o[a.type]??2)!==(o[b.type]??2) ? (o[a.type]??2)-(o[b.type]??2) : (a.name||'').localeCompare(b.name||'','pt');
  });
  _fillChips('printTagChips','printTagChipsEmpty', sortedTags.map(t=>({
    value: t.id,
    label: (t.type==='event'?'🎤 ':t.type==='list'?'📋 ':'🏷️ ') + t.name
  })));

  // ── Tonalidade ──
  const keys = [...new Set(filtered.map(s=>s.key).filter(Boolean))].sort((a,b)=>a.localeCompare(b,'pt'));
  _fillChips('printKeyChips','printKeyChipsEmpty', keys.map(k=>({value:k, label:k})));

  // ── Ritmo ──
  const rhythms = [...new Set(filtered.map(s=>s.rhythm).filter(Boolean))].sort((a,b)=>a.localeCompare(b,'pt'));
  _fillChips('printRhythmChips','printRhythmChipsEmpty', rhythms.map(r=>({value:r, label:r})));

  // ── BPM ──
  const bpms = [...new Set(filtered.map(s=>String(s.bpm||'')).filter(Boolean))].sort((a,b)=>parseInt(a)-parseInt(b));
  _fillChips('printBpmChips','printBpmChipsEmpty', bpms.map(b=>({value:b, label:b+' bpm'})));
}

function _fillChips(wrapId, emptyId, items){
  const wrap  = document.getElementById(wrapId);
  const empty = document.getElementById(emptyId);
  if(!items.length){
    wrap.innerHTML = '';
    wrap.classList.remove('show');
    empty.style.display = '';
    return;
  }
  empty.style.display = 'none';
  wrap.classList.add('show');
  wrap.innerHTML = items.map(item =>
    `<span class="print-tag-chip" data-value="${escH(item.value)}" onclick="togglePrintChip(this)">${escH(item.label)}</span>`
  ).join('');
}

function togglePrintChip(el){
  el.classList.toggle('checked');
}

function toggleAllPrintChips(wrapId){
  const chips = document.querySelectorAll(`#${wrapId} .print-tag-chip`);
  const allChecked = [...chips].every(c=>c.classList.contains('checked'));
  chips.forEach(c => allChecked ? c.classList.remove('checked') : c.classList.add('checked'));
}

function _getCheckedValues(wrapId){
  return [...document.querySelectorAll(`#${wrapId} .print-tag-chip.checked`)].map(c=>c.dataset.value);
}

function doPrint(){
  const tagId = document.getElementById('printTagSel').value;

  // O que mostrar — listas de valores seleccionados (vazio = coluna oculta)
  const selTagIds    = _getCheckedValues('printTagChips');
  const selKeys      = _getCheckedValues('printKeyChips');
  const selRhythms   = _getCheckedValues('printRhythmChips');
  const selBpms      = _getCheckedValues('printBpmChips');
  const showTagCol   = selTagIds.length > 0;
  const showKeyCol   = selKeys.length > 0;
  const showRhythmCol= selRhythms.length > 0;
  const showBpmCol   = selBpms.length > 0;

  const tags = DB.tags || {};
  let songs = Object.values(DB.songs || {});
  if(tagId) songs = songs.filter(s=>(s.tags||[]).includes(tagId));

  const activeTag   = tagId ? tags[tagId] : null;
  const customOrder = activeTag && (activeTag.song_order||[]).length > 0 ? activeTag.song_order : null;
  if(customOrder){
    const orderMap = {};
    customOrder.forEach((id,i) => { orderMap[id]=i; });
    songs.sort((a,b) => {
      const ia = orderMap[a.id]??99999, ib = orderMap[b.id]??99999;
      return ia!==ib ? ia-ib : (a.title||'').localeCompare(b.title||'','pt');
    });
  } else {
    songs.sort((a,b)=>(a.title||'').localeCompare(b.title||'','pt'));
  }

  const totalMs   = songs.reduce((a,s)=>a+(parseInt(s.duration_ms)||0),0);
  const totalDurStr = totalMs ? fmtDuration(totalMs, true) : '';

  const isEvent  = activeTag && activeTag.type==='event';
  const listName = activeTag ? activeTag.name : 'Todas as Músicas';

  // Blocos desta tag (se existirem)
  const blocks = (activeTag && activeTag.blocks) ? activeTag.blocks : [];
  // Indexados por pos_after (-1 = antes de tudo)
  const blocksByPos = {};
  blocks.forEach(b => {
    if(!blocksByPos[b.pos_after]) blocksByPos[b.pos_after] = [];
    blocksByPos[b.pos_after].push(b);
  });

  const colCount = 3 + 1 + (showKeyCol?1:0) + (showRhythmCol?1:0) + (showBpmCol?1:0) + (showTagCol?1:0);
  const durTh = '<th style="padding:6px 8px;border-bottom:2px solid #ddd;font-size:.68rem;font-weight:600;text-align:right">Dur.</th>';
  const metaTh =
    (showKeyCol    ? '<th style="padding:6px 8px;border-bottom:2px solid #ddd;font-size:.68rem;font-weight:600">Tom</th>' : '') +
    (showRhythmCol ? '<th style="padding:6px 8px;border-bottom:2px solid #ddd;font-size:.68rem;font-weight:600">Ritmo</th>' : '') +
    (showBpmCol    ? '<th style="padding:6px 8px;border-bottom:2px solid #ddd;font-size:.68rem;font-weight:600">BPM</th>' : '');
  const tagsTh = showTagCol ? '<th style="padding:6px 8px;border-bottom:2px solid #ddd;font-size:.68rem;font-weight:600">Tags</th>' : '';

  function blockRowHtml(b){
    const blockClr = isEvent ? '#c9960c' : '#1db954';
    return `<tr>
      <td colspan="${colCount}" style="padding:7px 10px;background:#f9f9f9;border-top:2px solid ${blockClr}33;border-bottom:2px solid ${blockClr}33">
        <div style="display:flex;align-items:center;gap:10px">
          <span style="background:${blockClr}22;border:1px solid ${blockClr}55;border-radius:20px;padding:2px 10px;font-size:.62rem;font-family:monospace;color:${blockClr};white-space:nowrap">BLOCO</span>
          <div>
            <div style="font-size:.82rem;font-weight:700;color:#1a1a1a">${escH(b.title)}</div>
            ${b.description ? `<div style="font-size:.7rem;color:#666;margin-top:1px">${escH(b.description)}</div>` : ''}
          </div>
        </div>
      </td>
    </tr>`;
  }

  let rows = '';
  // Blocos com pos_after=-1 (antes da primeira música)
  (blocksByPos[-1]||[]).forEach(b => { rows += blockRowHtml(b); });

  songs.forEach((s,i) => {
    const durMs  = parseInt(s.duration_ms)||0;
    const durStr = durMs ? fmtDuration(durMs) : '';
    const metaTd =
      (showKeyCol    ? `<td style="padding:6px 8px;border-bottom:1px solid #eee;font-size:.72rem">${selKeys.includes(s.key||'')    ? escH(s.key||'')    : ''}</td>` : '') +
      (showRhythmCol ? `<td style="padding:6px 8px;border-bottom:1px solid #eee;font-size:.72rem">${selRhythms.includes(s.rhythm||'') ? escH(s.rhythm||'') : ''}</td>` : '') +
      (showBpmCol    ? `<td style="padding:6px 8px;border-bottom:1px solid #eee;font-size:.72rem">${selBpms.includes(String(s.bpm||''))  ? escH(String(s.bpm||''))  : ''}</td>` : '');
    const tagNames = showTagCol ? selTagIds.map(tid=>{ const t=tags[tid]; return (t&&(s.tags||[]).includes(tid))?t.name:''; }).filter(Boolean).join(', ') : '';
    const tagsTd  = showTagCol ? `<td style="padding:6px 8px;border-bottom:1px solid #eee;font-size:.68rem;color:#888">${escH(tagNames)}</td>` : '';
    rows += `<tr>
      <td style="padding:6px 8px;border-bottom:1px solid #eee;font-size:.7rem;color:#bbb;text-align:center;font-weight:600">${i+1}</td>
      <td style="padding:6px 8px;border-bottom:1px solid #eee;font-size:.86rem;font-weight:600;color:#1a1a1a">${escH(s.title)}</td>
      <td style="padding:6px 8px;border-bottom:1px solid #eee;font-size:.78rem;color:#666">${escH(s.artist)}</td>
      <td style="padding:6px 8px;border-bottom:1px solid #eee;font-size:.72rem;color:#888;text-align:right;font-family:monospace">${durStr}</td>
      ${metaTd}${tagsTd}
    </tr>`;
    // Blocos após esta música (índice i, base-0)
    (blocksByPos[i]||[]).forEach(b => { rows += blockRowHtml(b); });
  });

  // ── Cabeçalho ──
  let header;
  if(isEvent){
    const dateStr  = formatEventDate(activeTag.event_date);
    const dateParts = activeTag.event_date ? activeTag.event_date.split('-') : null;
    const dayNum   = dateParts ? dateParts[2] : '';
    const monthName = dateStr ? dateStr.split(' ')[2] : '';
    header = `
      <div style="background:linear-gradient(135deg,#fffbea 0%,#fff 55%);border:2px solid #f0c419;border-radius:16px;padding:28px 32px;margin-bottom:28px;position:relative;overflow:hidden">
        <div style="position:absolute;top:-30px;right:-30px;width:140px;height:140px;border-radius:50%;background:radial-gradient(circle,rgba(240,196,25,.25),transparent 70%)"></div>
        <div style="display:flex;align-items:center;gap:20px;position:relative">
          ${dayNum?`<div style="background:#f0c419;border-radius:14px;padding:10px 18px;text-align:center;min-width:78px;box-shadow:0 4px 14px rgba(240,196,25,.35)"><div style="font-size:1.7rem;font-weight:800;color:#1a1a1a;line-height:1;font-family:Georgia,serif">${escH(dayNum)}</div><div style="font-size:.62rem;letter-spacing:.1em;text-transform:uppercase;color:#5a4a00;font-weight:700">${escH(monthName)}</div></div>`:''}
          <div style="flex:1">
            <div style="font-size:.62rem;letter-spacing:.18em;text-transform:uppercase;color:#c9960c;font-weight:700;margin-bottom:4px">🎤 SETLIST DE EVENTO</div>
            <h1 style="font-family:Georgia,serif;font-size:1.7rem;color:#1a1a1a;margin-bottom:6px;line-height:1.15">${escH(activeTag.name)}</h1>
            <div style="display:flex;flex-wrap:wrap;gap:14px;font-size:.76rem;color:#555">
              ${dateStr?`<span>📅 ${escH(dateStr)}</span>`:''}
              ${activeTag.event_time?`<span>🕒 ${escH(activeTag.event_time)}</span>`:''}
              ${activeTag.event_location?`<span>📍 ${escH(activeTag.event_location)}</span>`:''}
            </div>
          </div>
        </div>
      </div>
      <div style="font-size:.72rem;color:#999;margin-bottom:14px">${songs.length} músicas · ${blocks.length} blocos${totalDurStr?' · ⏱ '+totalDurStr:''}</div>`;
  } else {
    header = `<h1 style="font-family:Georgia,serif;font-size:1.4rem;margin-bottom:4px;color:#1a1a1a">${escH(listName)}</h1>
      <div style="font-size:.72rem;color:#888;margin-bottom:16px">${songs.length} músicas${totalDurStr?' · ⏱ '+totalDurStr:''} · ${new Date().toLocaleDateString('pt-PT')}</div>`;
  }

  const printHtml = `<style>
    @media print{body{font-family:'DM Sans',Arial,sans-serif;color:#111;background:#fff}}
    body{font-family:'DM Sans',Arial,sans-serif;background:#fff;padding:8px}
  </style>
  ${header}
  <table style="width:100%;border-collapse:collapse">
    <thead><tr>
      <th style="padding:6px 8px;border-bottom:2px solid #ddd;font-size:.68rem;font-weight:600;text-align:center;width:32px">#</th>
      <th style="padding:6px 8px;border-bottom:2px solid #ddd;font-size:.68rem;font-weight:600;text-align:left">Título</th>
      <th style="padding:6px 8px;border-bottom:2px solid #ddd;font-size:.68rem;font-weight:600;text-align:left">Artista</th>
      ${durTh}${metaTh}${tagsTh}
    </tr></thead>
    <tbody>${rows}</tbody>
  </table>`;

  const pw = window.open('','_blank','width=900,height=700');
  pw.document.write(`<!DOCTYPE html><html><head><meta charset="UTF-8"><title>${escH(listName)} · SetList</title></head><body>${printHtml}</body></html>`);
  pw.document.close();
  pw.focus();
  setTimeout(()=>{ pw.print(); }, 400);
  closeModal('printModal');
}

// ── Login ─────────────────────────────────────────────────────────
function doLogin(){
  const pwd = document.getElementById('loginPwd').value;
  if(!pwd){ showErr('loginErr','Digite a senha.'); return; }
  $.post(SCRIPT_URL, {_action:'_login', password:pwd}, function(r){
    if(r.ok){ window.location.reload(); }
    else showErr('loginErr', r.error || 'Senha incorreta.');
  }, 'json').fail(function(){ showErr('loginErr','Erro de rede.'); });
}

// ── Utilities ─────────────────────────────────────────────────────
const SCRIPT_URL = window.location.pathname;

function post(data, cb){
  $.post(SCRIPT_URL, data, cb, 'json')
   .fail(function(xhr){ showToast('Erro de rede: ' + xhr.status, true); });
}

function showSaving(){ document.getElementById('savingInd').classList.add('show'); }
function hideSaving(){ document.getElementById('savingInd').classList.remove('show'); }

function showToast(msg, err=false){
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = 'toast' + (err?' err':'') + ' show';
  setTimeout(()=>{ t.classList.remove('show'); }, 2800);
}

function showErr(id, msg){
  const el = document.getElementById(id);
  if(el) el.innerHTML = `<div class="alert alert-err">${escH(msg)}</div>`;
}
function clearErr(id){
  const el = document.getElementById(id);
  if(el) el.innerHTML = '';
}

function escH(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ── Mobile sidebar toggle ─────────────────────────────────────────
(function(){
  const sidebar = document.getElementById('sidebar');
  const logo    = sidebar ? sidebar.querySelector('.sb-logo') : null;
  if(logo){
    logo.addEventListener('click', function(){
      sidebar.classList.toggle('mob-open');
    });
  }
  // Fecha sidebar ao clicar num item de tag no mobile
  document.addEventListener('click', function(e){
    if(window.innerWidth > 768) return;
    if(e.target.closest('.tag-item') && !e.target.closest('.sb-logo')){
      sidebar.classList.remove('mob-open');
    }
  });
})();

// Close modals on backdrop click (except login)
document.querySelectorAll('.modal-overlay:not(#loginModal)').forEach(o => {
  o.addEventListener('click', function(e){ if(e.target===this) this.classList.remove('open'); });
});
// ESC to close (except login)
document.addEventListener('keydown', function(e){
  if(e.key==='Escape') document.querySelectorAll('.modal-overlay.open:not(#loginModal)').forEach(o=>o.classList.remove('open'));
  if(e.key==='Enter' && document.getElementById('loginModal') && document.getElementById('loginModal').classList.contains('open')) doLogin();
});
</script>
</body>
</html>
