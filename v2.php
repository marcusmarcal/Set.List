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

// ── Auth (desabilitada — acesso aberto) ──────────────────────────
function adminPwd()  { return ''; }
function isLocked()  { return false; }
function isAuthed()  { return true; }

function jsonOut($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function needAuth() {
    // Auth desabilitada — sempre autorizado
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

// ── Login (desabilitado) ─────────────────────────────────────────
if(($_POST['_action']??'')==='_login'){ jsonOut(['ok'=>true]); }
if(isset($_GET['logout'])){ header('Location: '.$_SERVER['PHP_SELF']); exit; }

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
        $db['tags'][$tid] = ['id'=>$tid,'name'=>$name,'type'=>$type,'spotify_id'=>$spot,'color'=>trim($_POST['color']??'')];
        saveDb($db);
        jsonOut(['ok'=>true,'id'=>$tid,'name'=>$name,'type'=>$type]);
    }

    if($act==='edit_tag'){
        needAuth();
        $db  = loadDb();
        $tid = trim($_POST['tag_id']??'');
        if(!isset($db['tags'][$tid])) jsonOut(['ok'=>false,'error'=>'Tag não encontrada.']);
        foreach(['name','type','spotify_id','color'] as $f){ if(isset($_POST[$f])) $db['tags'][$tid][$f]=trim($_POST[$f]); }
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
}

// ── Verificação de DB ─────────────────────────────────────────────
$db         = loadDb();
$dbExists   = file_exists($DB_FILE) && count($db['songs']) > 0;
$totalSongs = count($db['songs']);
$totalTags  = count($db['tags']);
$rhythms    = $db['settings']['rhythms'] ?? [];
$allKeys    = $db['settings']['keys'] ?? [];
$tags       = $db['tags'] ?? [];
$listTags   = array_values(array_filter($tags, fn($t) => ($t['type']??'custom')==='list'));
$customTags = array_values(array_filter($tags, fn($t) => ($t['type']??'custom')!=='list'));

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

/* ── SPOT LINK ── */
.spot-link{display:inline-flex;align-items:center;gap:3px;color:#1db954;font-size:.58rem;font-family:'DM Mono',monospace;text-decoration:none;padding:2px 5px;border-radius:4px;border:1px solid rgba(29,185,84,.3);transition:all var(--tr)}
.spot-link:hover{background:rgba(29,185,84,.12)}
.spot-link svg{width:8px;height:8px}

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
  table thead th:nth-child(3),  /* Tags */
  table thead th:nth-child(5),  /* Ritmo */
  table thead th:nth-child(6),  /* BPM */
  table tbody td:nth-child(3),
  table tbody td:nth-child(5),
  table tbody td:nth-child(6){display:none}
  table thead th:nth-child(1),
  table tbody td:nth-child(1){display:none} /* nº */
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
    <button class="sb-btn" onclick="openPrintModal()">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
      Imprimir Lista
    </button>
    <?php if(isAuthed()): ?>
    <button class="sb-btn" onclick="openModal('tagManagerModal')">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.07 4.93a10 10 0 010 14.14M4.93 4.93a10 10 0 000 14.14"/></svg>
      Gerir Tags
    </button>
    <?php endif; ?>
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
      <?php if(isAuthed()): ?>
      <button class="btn btn-primary no-print" onclick="openModal('addSongModal')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        Adicionar Música
      </button>
      <?php endif; ?>
      <?php if($canImport && isAuthed()): ?>
      <button class="btn btn-purple no-print" onclick="doImportOriginals()">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        Importar JSONs
      </button>
      <?php endif; ?>
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
        <?php if($canImport && isAuthed()): ?>
        <button class="btn btn-primary" onclick="doImportOriginals()">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          Importar JSONs Existentes
        </button>
        <?php endif; ?>
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
        <div class="stat-num" id="statCustomTags"><?= count($customTags) ?></div>
        <div class="stat-label">Tags</div>
      </div>
      <div class="stat-card">
        <div class="stat-num" id="statVisible">—</div>
        <div class="stat-label">Filtradas</div>
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

    <!-- TABLE -->
    <div class="table-wrap">
      <table id="songTable">
        <thead>
          <tr>
            <th class="td-num">#</th>
            <th>Título / Artista</th>
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
      <div class="fg"><label class="fl">URL Spotify</label><input class="fi" id="asSpotify" placeholder="https://open.spotify.com/track/…"></div>
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
      <div class="fg"><label class="fl">URL Spotify</label><input class="fi" id="esSpotify"></div>
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
    <div class="modal-sub">Tags organizam as músicas. Tipo "lista" representa uma setlist; "músico" para atribuições de instrumentos.</div>
    <div class="modal-body">
      <div id="addTagErr"></div>
      <div class="fg"><label class="fl">Nome *</label><input class="fi" id="atName" placeholder="Ex: Renato Guitarra"></div>
      <div class="fg"><label class="fl">Tipo</label>
        <select class="fi" id="atType">
          <option value="list">📋 Lista / Setlist</option>
          <option value="musician">🎸 Músico / Instrumento</option>
          <option value="status">📌 Status (ex: aprendendo)</option>
          <option value="custom">🏷️ Personalizada</option>
        </select>
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
        <select class="fi" id="etType">
          <option value="list">📋 Lista / Setlist</option>
          <option value="musician">🎸 Músico / Instrumento</option>
          <option value="status">📌 Status</option>
          <option value="custom">🏷️ Personalizada</option>
        </select>
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
  <div class="modal" style="max-width:420px">
    <div class="modal-title">Imprimir Lista</div>
    <div class="modal-sub">Escolhe a lista ou tag e as opções de impressão.</div>
    <div class="modal-body">
      <div class="fg"><label class="fl">Lista / Tag para imprimir</label>
        <select class="fi" id="printTagSel">
          <option value="">Todas as Músicas</option>
        </select>
      </div>
      <div class="fg" style="display:flex;align-items:center;gap:10px">
        <input type="checkbox" id="printWithTags" style="width:16px;height:16px;accent-color:var(--accent)">
        <label for="printWithTags" style="font-size:.82rem;cursor:pointer">Mostrar todas as tags de cada música</label>
      </div>
      <div class="fg" style="display:flex;align-items:center;gap:10px">
        <input type="checkbox" id="printWithMeta" checked style="width:16px;height:16px;accent-color:var(--accent)">
        <label for="printWithMeta" style="font-size:.82rem;cursor:pointer">Mostrar tonalidade, BPM e ritmo</label>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('printModal')">Cancelar</button>
      <button class="btn btn-primary" onclick="doPrint()">Imprimir / PDF</button>
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

function renderSidebar(){
  const tags = DB.tags || {};
  const songs = Object.values(DB.songs || {});
  let html = `
    <div class="tag-item ${activeTagFilter===''?'active':''}" data-tag="" onclick="setTagFilter('')">
      <div class="tag-dot"></div>
      <span>Todas as Músicas</span>
      <span class="tag-count" id="totalCount">${songs.length}</span>
    </div>`;

  // Separate by type
  const lists = Object.values(tags).filter(t=>t.type==='list');
  const musicians = Object.values(tags).filter(t=>t.type==='musician');
  const statuses = Object.values(tags).filter(t=>t.type==='status');
  const customs = Object.values(tags).filter(t=>!['list','musician','status'].includes(t.type));

  function tagSection(label, tagArr, dotClass){
    if(!tagArr.length) return '';
    let h = `<div style="font-family:'DM Mono',monospace;font-size:.48rem;letter-spacing:.15em;text-transform:uppercase;color:var(--text3);padding:10px 8px 4px">${label}</div>`;
    tagArr.forEach(t => {
      const cnt = songs.filter(s=>(s.tags||[]).includes(t.id)).length;
      const isAct = activeTagFilter===t.id;
      h += `<div class="tag-item ${isAct?'active':''}" data-tag="${t.id}" onclick="setTagFilter('${t.id}')">
        <div class="tag-dot ${dotClass}"></div>
        <span>${escH(t.name)}</span>
        <span class="tag-count">${cnt}</span>
      </div>`;
    });
    return h;
  }

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

  // Tag filter
  let tHtml = '<option value="">Todas as tags</option>';
  Object.values(tags).forEach(t => {
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

  // Print tag select
  let pHtml = '<option value="">Todas as Músicas</option>';
  Object.values(tags).forEach(t => { pHtml += `<option value="${t.id}">${escH(t.name)}</option>`; });
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

  let songs = Object.values(DB.songs || {});

  // Tag filter (sidebar)
  if(activeTagFilter) songs = songs.filter(s=>(s.tags||[]).includes(activeTagFilter));

  // Additional filters
  if(fTag)    songs = songs.filter(s=>(s.tags||[]).includes(fTag));
  if(fRhythm) songs = songs.filter(s=>s.rhythm===fRhythm);
  if(fKey)    songs = songs.filter(s=>s.key===fKey);
  if(q)       songs = songs.filter(s=>(s.title||'').toLowerCase().includes(q)||(s.artist||'').toLowerCase().includes(q));

  // Sort
  songs.sort((a,b)=>{
    const va=(a[sortBy]||a.title||'').toLowerCase();
    const vb=(b[sortBy]||b.title||'').toLowerCase();
    return va.localeCompare(vb,'pt');
  });

  renderTable(songs);
  document.getElementById('statVisible').textContent = songs.length;
  document.getElementById('currentViewSub').textContent = songs.length + ' músicas' + (activeTagFilter ? ' nesta lista' : ' no catálogo');
}

function renderTable(songs){
  const tags = DB.tags || {};
  const tbody = document.getElementById('songBody');
  if(!songs.length){
    tbody.innerHTML = `<tr><td colspan="7"><div class="empty-state"><div class="em-icon">🎵</div><h3>Nenhuma música encontrada</h3><p>Ajusta os filtros ou adiciona novas músicas ao catálogo.</p></div></td></tr>`;
    return;
  }

  let html = '';
  songs.forEach((s, i) => {
    const songTags = (s.tags||[]).map(tid => tags[tid]).filter(Boolean);
    const listTags = songTags.filter(t=>t.type==='list');
    const otherTags = songTags.filter(t=>t.type!=='list');

    let tagsHtml = '';
    listTags.forEach(t  => { tagsHtml += `<span class="chip chip-list">${escH(t.name)}</span>`; });
    otherTags.forEach(t => {
      const cls = t.type==='musician'?'chip-musician':'chip-custom';
      tagsHtml += `<span class="chip ${cls}">${escH(t.name)}</span>`;
    });

    const keyHtml  = s.key    ? `<span class="chip chip-key">${escH(s.key)}</span>` : '';
    const bpmHtml  = s.bpm    ? `<span class="chip chip-bpm">${escH(s.bpm)} bpm</span>` : '';
    const rHtml    = s.rhythm ? `<span class="chip chip-rhythm">${escH(s.rhythm)}</span>` : '';
    const spotHtml = s.spotify_url ? `<a href="${s.spotify_url}" target="_blank" class="spot-link"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.586 14.424a.623.623 0 01-.857.207c-2.348-1.435-5.304-1.76-8.785-.964a.623.623 0 01-.277-1.215c3.809-.87 7.077-.496 9.713 1.115a.623.623 0 01.206.857zm1.223-2.722a.779.779 0 01-1.072.257c-2.687-1.652-6.785-2.131-9.965-1.166a.779.779 0 01-.973-.52.779.779 0 01.52-.972c3.632-1.102 8.147-.568 11.233 1.329a.779.779 0 01.257 1.072zm.105-2.835C14.692 8.95 9.375 8.775 6.297 9.71a.935.935 0 11-.543-1.79c3.533-1.072 9.404-.865 13.115 1.338a.935.935 0 11-.954 1.609z"/></svg>Spotify</a>` : '';

    const editBtn = canEdit ? `<button class="btn btn-ghost" style="padding:4px 7px" onclick="event.stopPropagation();openEditSong('${s.id}')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg></button>` : '';

    const isSel = selectedSongIds.has(s.id);

    html += `<tr class="song-row${isSel?' selected':''}" data-id="${s.id}" onclick="toggleSongSelection('${s.id}')">
      <td class="td-num">${i+1}</td>
      <td>
        <div class="td-title">${escH(s.title)}</div>
        <div class="td-artist">${escH(s.artist)}</div>
        ${spotHtml ? '<div class="td-meta">'+spotHtml+'</div>' : ''}
      </td>
      <td><div style="display:flex;flex-wrap:wrap;gap:4px">${tagsHtml}</div></td>
      <td>${keyHtml}</td>
      <td>${rHtml}</td>
      <td>${bpmHtml}</td>
      <td class="td-actions no-print"><div class="td-actions-inner">${editBtn}</div></td>
    </tr>`;
  });
  tbody.innerHTML = html;
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
  const songs = Object.values(DB.songs||{});
  const tags  = Object.values(DB.tags||{});
  document.getElementById('statSongs').textContent = songs.length;
  document.getElementById('statTags').textContent  = tags.filter(t=>t.type==='list').length;
  document.getElementById('statCustomTags').textContent = tags.filter(t=>t.type!=='list').length;
}

// ── Tags Picker ───────────────────────────────────────────────────
function renderTagsPicker(containerId, selectedTags){
  const tags = DB.tags || {};
  const el = document.getElementById(containerId);
  let html = '';
  Object.values(tags).forEach(t => {
    const sel = selectedTags.includes(t.id);
    const selCls = t.type==='list'?'sel-list':t.type==='musician'?'sel-musician':'sel-custom';
    html += `<span class="tp-chip ${sel?selCls:''}" data-tid="${t.id}" onclick="toggleTagChip(this,'${containerId}','${t.type}')">
      ${escH(t.name)}
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
  const tags   = getSelectedTags('asTagsPicker');
  post({
    _action:'add_song', title, artist,
    key: document.getElementById('asKey').value,
    bpm: document.getElementById('asBpm').value,
    rhythm: document.getElementById('asRhythm').value,
    spotify_url: document.getElementById('asSpotify').value,
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
function openEditSong(uuid){
  const s = DB.songs[uuid];
  if(!s) return;
  document.getElementById('esId').value = uuid;
  document.getElementById('esTitle').value  = s.title||'';
  document.getElementById('esArtist').value = s.artist||'';
  document.getElementById('esBpm').value    = s.bpm||'';
  document.getElementById('esSpotify').value= s.spotify_url||'';
  document.getElementById('esNotes').value  = s.notes||'';
  document.getElementById('editSongSub').textContent = s.title;
  renderTagsPicker('esTagsPicker', s.tags||[]);
  populateRhythmSelects();
  // Set key and rhythm after populate
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
  const tags   = getSelectedTags('esTagsPicker');
  post({
    _action:'edit_song', song_id: uuid, title, artist,
    key: document.getElementById('esKey').value,
    bpm: document.getElementById('esBpm').value,
    rhythm: document.getElementById('esRhythm').value,
    spotify_url: document.getElementById('esSpotify').value,
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
function submitAddTag(){
  const name = document.getElementById('atName').value.trim();
  const type = document.getElementById('atType').value;
  const spot = document.getElementById('atSpotify').value.trim();
  if(!name){ showErr('addTagErr','Nome obrigatório.'); return; }
  post({ _action:'add_tag', name, type, spotify_id: spot }, function(r){
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
  const typeLabel = { list:'Lista', musician:'Músico', status:'Status', custom:'Tag' };
  const typeCls   = { list:'chip-list', musician:'chip-musician', status:'chip-custom', custom:'chip-custom' };
  let html = '<div style="display:flex;flex-direction:column;gap:4px">';
  Object.values(tags).forEach(t => {
    const cnt = songs.filter(s=>(s.tags||[]).includes(t.id)).length;
    html += `<div style="display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:var(--r);border:1px solid var(--border);background:var(--bg3);cursor:pointer" onclick="openEditTag('${t.id}')">
      <span class="chip ${typeCls[t.type]||'chip-custom'}">${typeLabel[t.type]||t.type}</span>
      <span style="flex:1;font-size:.83rem">${escH(t.name)}</span>
      <span style="font-family:'DM Mono',monospace;font-size:.6rem;color:var(--text3)">${cnt} músicas</span>
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="12" height="12" style="color:var(--text3)"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
    </div>`;
  });
  html += '</div>';
  el.innerHTML = html;
}

function openEditTag(tid){
  const t = (DB.tags || {})[tid];
  if(!t) return;
  document.getElementById('etId').value = tid;
  document.getElementById('etName').value = t.name || '';
  document.getElementById('etType').value = t.type || 'custom';
  document.getElementById('etSpotify').value = t.spotify_id || '';
  closeModal('tagManagerModal');
  document.getElementById('editTagModal').classList.add('open');
}

function submitEditTag(){
  const tid  = document.getElementById('etId').value;
  const name = document.getElementById('etName').value.trim();
  if(!name){ showErr('editTagErr','Nome obrigatório.'); return; }
  post({ _action:'edit_tag', tag_id:tid, name, type: document.getElementById('etType').value, spotify_id: document.getElementById('etSpotify').value }, function(r){
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

// ── Import Originals ──────────────────────────────────────────────
function doImportOriginals(){
  if(!confirm('Importar músicas e listas dos JSONs originais?\n\nOs JSONs originais NÃO serão modificados.\nO banco V2 (setlist_v2_db.json) será criado/atualizado.')) return;
  showSaving();
  post({ _action:'import_from_originals' }, function(r){
    hideSaving();
    if(r.ok){
      showToast(`Importado! ${r.songs} músicas únicas, ${r.tags} listas como tags.`);
      loadDbFromServer();
    } else {
      showToast(r.error||'Erro na importação.', true);
    }
  });
}

// ── Print ─────────────────────────────────────────────────────────
function openPrintModal(){
  // Populate print tag select
  const tags = DB.tags || {};
  let html = '<option value="">Todas as Músicas</option>';
  const listTags = Object.values(tags).filter(t=>t.type==='list');
  const otherTags = Object.values(tags).filter(t=>t.type!=='list');
  if(listTags.length){ html += '<optgroup label="Listas">'; listTags.forEach(t=>{ html+=`<option value="${t.id}">${escH(t.name)}</option>`; }); html+='</optgroup>'; }
  if(otherTags.length){ html += '<optgroup label="Tags">'; otherTags.forEach(t=>{ html+=`<option value="${t.id}">${escH(t.name)}</option>`; }); html+='</optgroup>'; }
  document.getElementById('printTagSel').innerHTML = html;
  document.getElementById('printModal').classList.add('open');
}

function doPrint(){
  const tagId    = document.getElementById('printTagSel').value;
  const withTags = document.getElementById('printWithTags').checked;
  const withMeta = document.getElementById('printWithMeta').checked;

  // Build songs list locally (faster than server round-trip)
  const tags = DB.tags || {};
  let songs = Object.values(DB.songs || {});
  if(tagId) songs = songs.filter(s=>(s.tags||[]).includes(tagId));
  songs.sort((a,b)=>(a.title||'').localeCompare(b.title||'','pt'));

  const listName = tagId ? (tags[tagId]?.name||'Lista') : 'Todas as Músicas';

  const metaTh = withMeta ? '<th style="padding:5px 8px;border-bottom:2px solid #ccc;font-size:.68rem;font-weight:600;text-align:left">Tom</th><th style="padding:5px 8px;border-bottom:2px solid #ccc;font-size:.68rem;font-weight:600;text-align:left">Ritmo</th><th style="padding:5px 8px;border-bottom:2px solid #ccc;font-size:.68rem;font-weight:600;text-align:left">BPM</th>' : '';
  const tagsTh  = withTags ? '<th style="padding:5px 8px;border-bottom:2px solid #ccc;font-size:.68rem;font-weight:600;text-align:left">Tags</th>' : '';

  let rows = '';
  songs.forEach((s,i)=>{
    const metaTd = withMeta ? `<td style="padding:5px 8px;border-bottom:1px solid #eee;font-size:.72rem">${escH(s.key||'')}</td><td style="padding:5px 8px;border-bottom:1px solid #eee;font-size:.72rem">${escH(s.rhythm||'')}</td><td style="padding:5px 8px;border-bottom:1px solid #eee;font-size:.72rem">${s.bpm||''}</td>` : '';
    const tagNames = withTags ? (s.tags||[]).map(tid=>tags[tid]?.name).filter(Boolean).join(', ') : '';
    const tagsTd  = withTags ? `<td style="padding:5px 8px;border-bottom:1px solid #eee;font-size:.68rem;color:#777">${escH(tagNames)}</td>` : '';
    rows += `<tr>
      <td style="padding:5px 8px;border-bottom:1px solid #eee;font-size:.68rem;color:#aaa;text-align:center">${i+1}</td>
      <td style="padding:5px 8px;border-bottom:1px solid #eee;font-size:.82rem;font-weight:500">${escH(s.title)}</td>
      <td style="padding:5px 8px;border-bottom:1px solid #eee;font-size:.76rem;color:#555">${escH(s.artist)}</td>
      ${metaTd}${tagsTd}
    </tr>`;
  });

  const html = `
    <style>
      @media print { body { font-family: 'DM Sans', Arial, sans-serif; color: #111; } }
      body { font-family: 'DM Sans', Arial, sans-serif; }
    </style>
    <h1 style="font-family:Georgia,serif;font-size:1.4rem;margin-bottom:4px">${escH(listName)}</h1>
    <div style="font-size:.72rem;color:#888;margin-bottom:16px">${songs.length} músicas · ${new Date().toLocaleDateString('pt-BR')}</div>
    <table style="width:100%;border-collapse:collapse">
      <thead><tr>
        <th style="padding:5px 8px;border-bottom:2px solid #ccc;font-size:.68rem;font-weight:600;text-align:center;width:32px">#</th>
        <th style="padding:5px 8px;border-bottom:2px solid #ccc;font-size:.68rem;font-weight:600;text-align:left">Título</th>
        <th style="padding:5px 8px;border-bottom:2px solid #ccc;font-size:.68rem;font-weight:600;text-align:left">Artista</th>
        ${metaTh}${tagsTh}
      </tr></thead>
      <tbody>${rows}</tbody>
    </table>`;

  const pw = window.open('','_blank','width=900,height=700');
  pw.document.write(`<!DOCTYPE html><html><head><meta charset="UTF-8"><title>${escH(listName)} · SetList</title></head><body>${html}</body></html>`);
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
