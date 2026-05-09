<?php
// ════════════════════════════════════════════════════════════════
//  SetList — index.php  (sistema completo num único ficheiro)
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
function adminPwd()  { return envVal('ADMIN_PASSWORD') ?: ''; }
function isLocked()  { return adminPwd() !== ''; }
function isAuthed()  { return !isLocked() || !empty($_SESSION['sl_authed']); }

// ── Playlists ────────────────────────────────────────────────────
$PLF = __DIR__ . '/playlists.json';

function loadPlaylists() {
    global $PLF;
    if (!file_exists($PLF)) {
        $d = [[
            'id'=>'principal','name'=>'Marcvs Marcal',
            'spotify_id'=>'4pcomesNQA6DPXj1HFpOjf','is_default'=>true
        ]];
        file_put_contents($PLF, json_encode($d, JSON_PRETTY_PRINT));
        return $d;
    }
    return json_decode(file_get_contents($PLF), true) ?: [];
}
function savePlaylists($d) { global $PLF; file_put_contents($PLF, json_encode($d, JSON_PRETTY_PRINT)); }

function getActivePl() {
    $pls = loadPlaylists();
    $id  = $_GET['pl'] ?? $_POST['pl'] ?? null;
    if ($id) foreach ($pls as $p) if ($p['id'] === $id) return $p;
    foreach ($pls as $p) if (!empty($p['is_default'])) return $p;
    return $pls[0] ?? null;
}

function songsFile($pl) {
    $id = preg_replace('/[^a-z0-9_-]/i','', $pl['id']);
    return __DIR__ . "/songs_{$id}.json";
}
function loadSongs($pl) {
    $f = songsFile($pl);
    if (!file_exists($f)) {
        $leg = __DIR__ . '/songs.json';
        if (!empty($pl['is_default']) && file_exists($leg)) {
            $d = json_decode(file_get_contents($leg), true) ?: [];
            file_put_contents($f, json_encode($d, JSON_PRETTY_PRINT));
            return $d;
        }
        return [];
    }
    return json_decode(file_get_contents($f), true) ?: [];
}
function saveSongs($pl, $d) { file_put_contents(songsFile($pl), json_encode($d, JSON_PRETTY_PRINT)); }

function sortSongs($songs, $col, $ord='asc') {
    usort($songs, function($a,$b) use($col,$ord) {
        $cmp = strcmp(strtoupper($a[$col]??''), strtoupper($b[$col]??''));
        return $ord==='desc' ? -$cmp : $cmp;
    });
    return $songs;
}

function cleanTitle($t) {
    $kw = 'live|ao vivo|remaster(?:ed)?(?:\s+\d{4})?|\d{4}[\w\s\-]*mix|bonus track|explicit'
        . '|radio edit|single version|album version|deluxe|acoustic|demo|instrumental|extended|intro|outro';
    $t = preg_replace('/\s*[\(\[]\s*(?:'.$kw.')[^\)\]]*[\)\]]/iu', '', $t);
    $t = preg_replace('/\s+-\s+(?:'.$kw.').*/iu', '', $t);
    return trim($t);
}

function cifraUrl($song) {
    $u = $song['cifra_url'] ?? ''; $s = $song['cifra_source'] ?? '';
    if (!$u || $u==='N/A') return null;
    if (preg_match('/^https?:\/\//', $u)) return $u;
    if ($s==='ultimate_guitar') return 'https://tabs.ultimate-guitar.com/'.ltrim($u,'/');
    return 'https://www.cifraclub.com.br/'.ltrim($u,'/');
}
function cifraLabel($song) {
    $s = $song['cifra_source'] ?? '';
    if ($s === 'ultimate_guitar') return 'UG';
    if ($s === 'cifraclub') return 'CC';
    return 'link';
}
function detectSrc($u) {
    if (!$u||$u==='N/A') return 'none';
    if (strpos($u,'ultimate-guitar.com') !== false) return 'ultimate_guitar';
    return 'cifraclub';
}

// ── Spotify helpers ──────────────────────────────────────────────
function spotCreds() {
    // envVal() reads .env directly — most reliable on shared hosts
    // where $_ENV may be empty due to variables_order php.ini setting
    $id  = envVal('CLIENT_ID') ?: envVal('SPOTIPY_CLIENT_ID')
        ?: getenv('CLIENT_ID') ?: getenv('SPOTIPY_CLIENT_ID')
        ?: ($_ENV['CLIENT_ID'] ?? $_ENV['SPOTIPY_CLIENT_ID'] ?? '');
    $sec = envVal('CLIENT_SECRET') ?: envVal('SPOTIPY_CLIENT_SECRET')
        ?: getenv('CLIENT_SECRET') ?: getenv('SPOTIPY_CLIENT_SECRET')
        ?: ($_ENV['CLIENT_SECRET'] ?? $_ENV['SPOTIPY_CLIENT_SECRET'] ?? '');
    return [$id, $sec];
}
function hasSpotCreds() { [$i,$s]=spotCreds(); return $i&&$s; }
function spotToken() {
    [$id,$sec] = spotCreds();
    if (!$id||!$sec) return null;
    $ch = curl_init('https://accounts.spotify.com/api/token');
    curl_setopt_array($ch,[
        CURLOPT_HTTPHEADER     => ['Authorization: Basic '.base64_encode("$id:$sec"),
                                   'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $r = json_decode(curl_exec($ch),true); curl_close($ch);
    return $r['access_token'] ?? null;
}
function spotPlInfo($tok,$plId) {
    $ch = curl_init("https://api.spotify.com/v1/playlists/$plId?fields=name,external_urls");
    curl_setopt_array($ch,[
        CURLOPT_HTTPHEADER     => ["Authorization: Bearer $tok"],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw = curl_exec($ch);
    $d   = json_decode($raw, true);
    curl_close($ch);
    if (empty($d['name'])) return null;
    return ['name'=>$d['name'],'url'=>$d['external_urls']['spotify']??("https://open.spotify.com/playlist/$plId")];
}
function spotTracks($tok,$plId) {
    $songs=[]; $url="https://api.spotify.com/v1/playlists/$plId/tracks?limit=100";
    while($url) {
        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_HTTPHEADER     => ["Authorization: Bearer $tok"],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 20,
        ]);
        $d=json_decode(curl_exec($ch),true); curl_close($ch);
        if(empty($d['items'])) break;
        foreach($d['items'] as $item) {
            $t=$item['track']??null;
            if(!$t||empty($t['name'])) continue;
            $songs[]=['title'=>cleanTitle($t['name']),'artist'=>$t['artists'][0]['name']??'',
                      'cifra_url'=>'N/A','cifra_source'=>'cifraclub',
                      'duration_ms'=>(int)($t['duration_ms']??0),
                      'spotify_url'=>$t['external_urls']['spotify']??''];
        }
        $url=$d['next']??null;
    }
    return $songs;
}

function fmtMs($ms) {
    if(!$ms) return null;
    $s=intdiv($ms,1000);$h=intdiv($s,3600);$s-=$h*3600;$m=intdiv($s,60);
    return $h?sprintf('%dh %02dm',$h,$m):sprintf('%dm',$m);
}

// ════════════════════════════════════════════════════════════════
//  AJAX / POST HANDLERS  (all return JSON or redirect)
// ════════════════════════════════════════════════════════════════
$ajax = $_SERVER['HTTP_X_REQUESTED_WITH']??'' === 'XMLHttpRequest'
     || isset($_POST['_ajax']) || isset($_GET['_ajax']);

function jsonOut($d){ header('Content-Type: application/json'); echo json_encode($d); exit; }
function needAuth()  { if(!isAuthed()) jsonOut(['ok'=>false,'error'=>'auth']); }

// Login
if(($_POST['_action']??'')==='_login'){
    if($_POST['password']===adminPwd()){$_SESSION['sl_authed']=true;jsonOut(['ok'=>true]);}
    jsonOut(['ok'=>false,'error'=>'Senha incorreta.']);
}
// Logout
if(isset($_GET['logout'])){ unset($_SESSION['sl_authed']); header('Location: index.php'); exit; }

// Spotify lookup (AJAX GET)
if(isset($_GET['spot_lookup'])){
    [$cid,$csec] = spotCreds();
    if(!$cid||!$csec) jsonOut(['ok'=>false,'error'=>'Credenciais não encontradas no .env (CLIENT_ID / CLIENT_SECRET)']);
    $tok = spotToken();
    if(!$tok) jsonOut(['ok'=>false,'error'=>'Token Spotify falhou — verifique CLIENT_ID e CLIENT_SECRET no .env']);
    $plId = trim($_GET['spot_lookup']);
    $info = spotPlInfo($tok, $plId);
    if(!$info) jsonOut(['ok'=>false,'error'=>'Playlist não encontrada ou privada — confirma que é pública e o ID está correcto']);
    jsonOut(['ok'=>true,'name'=>$info['name'],'url'=>$info['url']]);
}

// Debug endpoint — remove after testing (?spot_debug=1)
if(isset($_GET['spot_debug'])){
    header('Content-Type: application/json');
    [$cid,$csec]=spotCreds();
    $tok=spotToken();
    $info=$tok?spotPlInfo($tok,'37i9dQZF1DXcBWIGoYBM5M'):null; // Spotify Top 50 (always public)
    echo json_encode([
        'has_client_id'  => !empty($cid),
        'has_secret'     => !empty($csec),
        'client_id_len'  => strlen($cid),
        'token_ok'       => !empty($tok),
        'test_pl_info'   => $info,
        'env_file_exists'=> file_exists(__DIR__.'/.env'),
        'php_version'    => PHP_VERSION,
    ], JSON_PRETTY_PRINT);
    exit;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $act=$_POST['_action']??'';

    // ── Reorder songs ──
    if($act==='reorder_songs'){
        needAuth();
        $pl=getActivePl(); $songs=loadSongs($pl);
        $order=json_decode($_POST['order']??'[]',true);
        if(is_array($order)){
            $s=[];foreach($order as $i) if(isset($songs[(int)$i])) $s[]=$songs[(int)$i];
            saveSongs($pl,$s);
        }
        jsonOut(['ok'=>true]);
    }

    // ── Edit song (inline) ──
    if($act==='edit_song'){
        needAuth();
        $pl=getActivePl(); $songs=loadSongs($pl);
        $i=(int)($_POST['index']??-1);
        $title=trim($_POST['title']??''); $artist=trim($_POST['artist']??'');
        $cu=trim($_POST['cifra_url']??''); $cs=trim($_POST['cifra_source']??'cifraclub');
        if($i>=0&&$i<count($songs)&&$title&&$artist){
            $songs[$i]['title']=$title;$songs[$i]['artist']=$artist;
            $songs[$i]['cifra_url']=$cu?:'N/A';$songs[$i]['cifra_source']=$cs;
            saveSongs($pl,$songs);
            jsonOut(['ok'=>true,'title'=>$title,'artist'=>$artist,
                     'cifra_url'=>cifraUrl($songs[$i]),'cifra_label'=>cifraLabel($songs[$i]),'cifra_source'=>$cs]);
        }
        jsonOut(['ok'=>false]);
    }

    // ── Add song ──
    if($act==='add_song'){
        needAuth();
        $pl=getActivePl(); $songs=loadSongs($pl);
        $title=trim($_POST['title']??''); $artist=trim($_POST['artist']??'');
        $cu=trim($_POST['cifra_url']??''); $cs=trim($_POST['cifra_source']??'cifraclub');
        if($title&&$artist){
            $songs[]=['title'=>$title,'artist'=>$artist,'cifra_url'=>$cu?:'N/A','cifra_source'=>$cs];
            saveSongs($pl,$songs);
            $idx=count($songs)-1;
            jsonOut(['ok'=>true,'index'=>$idx,'title'=>$title,'artist'=>$artist,
                     'cifra_url'=>cifraUrl($songs[$idx]),'cifra_label'=>cifraLabel($songs[$idx])]);
        }
        jsonOut(['ok'=>false,'error'=>'Título e artista são obrigatórios.']);
    }

    // ── Delete song ──
    if($act==='delete_song'){
        needAuth();
        $pl=getActivePl(); $songs=loadSongs($pl);
        $i=(int)($_POST['index']??-1);
        if($i>=0&&$i<count($songs)){ array_splice($songs,$i,1); saveSongs($pl,$songs); }
        jsonOut(['ok'=>true]);
    }

    // ── Reorder playlists ──
    if($act==='reorder_pls'){
        needAuth();
        $pls=loadPlaylists(); $order=json_decode($_POST['order']??'[]',true);
        $idx=[];foreach($pls as $p) $idx[$p['id']]=$p;
        $sorted=[];foreach($order as $id) if(isset($idx[$id])) $sorted[]=$idx[$id];
        foreach($sorted as $i=>&$p) $p['is_default']=($i===0); unset($p);
        savePlaylists($sorted); jsonOut(['ok'=>true]);
    }

    // ── Add playlist (fetch name from Spotify, import immediately) ──
    if($act==='add_pl'){
        needAuth();
        $spotId=trim($_POST['spotify_id']??'');
        if(!$spotId) jsonOut(['ok'=>false,'error'=>'ID obrigatório']);
        $tok=spotToken();
        if(!$tok) jsonOut(['ok'=>false,'error'=>'Credenciais Spotify não configuradas no .env']);
        $info=spotPlInfo($tok,$spotId);
        if(!$info) jsonOut(['ok'=>false,'error'=>'Playlist não encontrada ou privada']);
        $pls=loadPlaylists();
        $slug=trim(strtolower(preg_replace('/[^a-z0-9]+/i','-',$info['name'])),'-')?:'playlist';
        $exist=array_column($pls,'id');$base=$slug;$n=2;
        while(in_array($slug,$exist)) $slug=$base.'-'.$n++;
        $newPl=['id'=>$slug,'name'=>$info['name'],'spotify_id'=>$spotId,
                'spotify_url'=>$info['url'],'is_default'=>count($pls)===0];
        $pls[]=$newPl; savePlaylists($pls);
        $tracks=spotTracks($tok,$spotId);
        if($tracks) saveSongs($newPl,$tracks);
        jsonOut(['ok'=>true,'id'=>$slug,'name'=>$info['name'],'spotify_url'=>$info['url'],
                 'track_count'=>count($tracks)]);
    }

    // ── Edit playlist ──
    if($act==='edit_pl'){
        needAuth();
        $pls=loadPlaylists(); $tid=$_POST['target_id']??'';
        $newSpotId=trim($_POST['spotify_id']??'');
        foreach($pls as &$p){
            if($p['id']!==$tid) continue;
            if($newSpotId&&$newSpotId!==$p['spotify_id']){
                $tok=spotToken();
                if($tok){$info=spotPlInfo($tok,$newSpotId);
                    if($info){$p['name']=$info['name'];$p['spotify_url']=$info['url'];}
                }
                $p['spotify_id']=$newSpotId;
            }
        } unset($p);
        savePlaylists($pls); jsonOut(['ok'=>true]);
    }

    // ── Delete playlist ──
    if($act==='delete_pl'){
        needAuth();
        $pls=loadPlaylists(); $tid=$_POST['target_id']??'';
        $pls=array_values(array_filter($pls, function($p) use($tid){ return $p['id']!==$tid; }));
        if(count($pls)) $pls[0]['is_default']=true;
        savePlaylists($pls); jsonOut(['ok'=>true]);
    }

    // ── Import ──
    if($act==='import'){
        needAuth();
        $pls=loadPlaylists(); $tid=$_POST['target_id']??'';
        $targetPl=null;foreach($pls as $p) if($p['id']===$tid){$targetPl=$p;break;}
        if(!$targetPl) jsonOut(['ok'=>false,'error'=>'Lista não encontrada']);
        $tok=spotToken();
        if(!$tok) jsonOut(['ok'=>false,'error'=>'Credenciais Spotify não configuradas']);
        // Update name
        $info=spotPlInfo($tok,$targetPl['spotify_id']);
        if($info){
            foreach($pls as &$p){
                if($p['id']===$tid){$p['name']=$info['name'];$p['spotify_url']=$info['url'];
                    $targetPl=$p;}
            } unset($p); savePlaylists($pls);
        }
        $tracks=spotTracks($tok,$targetPl['spotify_id']);
        if(empty($tracks)) jsonOut(['ok'=>false,'error'=>'Nenhuma faixa encontrada']);
        $mode=$_POST['mode']??'replace';
        if($mode==='merge'){
            $ex=loadSongs($targetPl);
            $keys=array_map(function($s){ return strtolower($s['title'].'|'.$s['artist']); },$ex);
            foreach($tracks as $t) if(!in_array(strtolower($t['title'].'|'.$t['artist']),$keys)) $ex[]=$t;
            saveSongs($targetPl,$ex); $cnt=count($ex);
        } else { saveSongs($targetPl,$tracks); $cnt=count($tracks); }
        jsonOut(['ok'=>true,'count'=>$cnt,'name'=>$targetPl['name'],'pl_id'=>$tid]);
    }
}

// ── Render page ──────────────────────────────────────────────────
$activePl  = getActivePl();
$playlists = loadPlaylists();
$plId      = $activePl['id']??'';
$songs     = loadSongs($activePl);
$sortCol   = $_GET['sort']??''; $sortOrd=$_GET['order']??'asc';
if($sortCol) $songs=sortSongs($songs,$sortCol,$sortOrd);

$totalSongs  = count($songs);
$artistCount = count(array_unique(array_column($songs,'artist')));
$withCifra   = count(array_filter($songs, function($s){ return !empty($s['cifra_url'])&&$s['cifra_url']!=='N/A'; }));
$durStr      = fmtMs(array_sum(array_column($songs,'duration_ms')));
$plSpotUrl   = $activePl['spotify_url']??('https://open.spotify.com/playlist/'.($activePl['spotify_id']??''));
$authed      = isAuthed();
$locked      = isLocked();
$hasSpot     = hasSpotCreds();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($activePl['name']??'SetList') ?> · SetList</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Mono:wght@300;400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
<style>
:root{
  --bg:#0a0a0c;--bg2:#111115;--bg3:#18181e;
  --border:#222228;--border2:#2e2e38;
  --accent:#1db954;--accent2:#1ed760;
  --accent-dim:rgba(29,185,84,.12);--accent-glow:rgba(29,185,84,.25);
  --text:#f0f0f4;--text2:#8888a0;--text3:#555568;
  --danger:#e05252;--danger-dim:rgba(224,82,82,.12);
  --r:8px;--r2:14px;--tr:.18s cubic-bezier(.4,0,.2,1);
  --sw:230px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased}

/* ── SIDEBAR ── */
.sidebar{
  position:fixed;left:0;top:0;bottom:0;width:var(--sw);
  background:var(--bg2);border-right:1px solid var(--border);
  display:flex;flex-direction:column;z-index:100;overflow-y:auto;
  transition:transform var(--tr);
}
.sb-logo{padding:22px 20px 16px;border-bottom:1px solid var(--border);flex-shrink:0}
.sb-logo .wm{font-family:'Playfair Display',serif;font-size:1.2rem;font-weight:900;letter-spacing:-.02em}
.sb-logo .wm span{color:var(--accent)}
.sb-logo .tg{font-family:'DM Mono',monospace;font-size:.56rem;color:var(--text3);letter-spacing:.12em;text-transform:uppercase;margin-top:3px}

/* Playlist list in sidebar */
.sb-pls{padding:12px 10px 6px;flex:1;overflow-y:auto}
.sb-sec{font-family:'DM Mono',monospace;font-size:.56rem;letter-spacing:.15em;text-transform:uppercase;color:var(--text3);padding:0 8px;margin-bottom:8px}
.pl-item{
  display:flex;align-items:center;gap:5px;
  padding:4px 6px;border-radius:var(--r);
  color:var(--text2);font-size:.78rem;
  position:relative;transition:background var(--tr);
}
.pl-item:hover{background:var(--bg3);color:var(--text)}
.pl-item.active{background:var(--accent-dim);color:var(--accent)}
.pl-item.active::before{content:'';position:absolute;left:0;top:20%;bottom:20%;width:3px;background:var(--accent);border-radius:0 3px 3px 0}
.pl-dot{width:6px;height:6px;border-radius:50%;background:var(--text3);flex-shrink:0}
.pl-item.active .pl-dot{background:var(--accent)}
.pl-name-text{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-align:left}
.pl-def-badge{font-family:'DM Mono',monospace;font-size:.48rem;letter-spacing:.08em;text-transform:uppercase;background:var(--accent-dim);color:var(--accent);border:1px solid var(--accent-glow);padding:1px 4px;border-radius:3px;flex-shrink:0}

/* Sidebar action icons on pl-item hover */
.pl-actions{display:none;gap:1px;flex-shrink:0}
.pl-item:hover .pl-actions{display:flex}
.pl-act-btn{background:none;border:none;cursor:pointer;color:var(--text3);padding:2px 4px;border-radius:4px;display:flex;align-items:center;transition:color var(--tr)}
.pl-act-btn:hover{color:var(--text)}
.pl-act-btn.danger:hover{color:var(--danger)}
.pl-act-btn svg{width:11px;height:11px}

/* drag handle in sidebar */
.pl-order-btns{display:flex;flex-direction:column;gap:0;flex-shrink:0}
.pl-name-btn{background:none;border:none;cursor:pointer;display:flex;align-items:center;gap:7px;flex:1;min-width:0;padding:0;color:inherit;font:inherit;text-align:left}

.sb-bottom{padding:8px 10px 14px;border-top:1px solid var(--border);flex-shrink:0;margin-top:auto}
.sb-add-pl{
  display:flex;align-items:center;gap:7px;width:100%;
  padding:7px 8px;border-radius:var(--r);
  border:1px dashed var(--border2);background:none;
  color:var(--text3);font-family:'DM Sans',sans-serif;font-size:.78rem;
  cursor:pointer;transition:all var(--tr);
}
.sb-add-pl:hover{border-color:var(--accent);color:var(--accent);background:var(--accent-dim)}
.sb-add-pl svg{width:13px;height:13px;flex-shrink:0}
.sb-auth{display:flex;align-items:center;gap:6px;font-size:.68rem;color:var(--text3);padding:6px 8px;margin-bottom:4px}
.sb-auth svg{width:10px;height:10px;flex-shrink:0}

/* ── MAIN ── */
.main{margin-left:var(--sw);min-height:100vh;display:flex;flex-direction:column}

.topbar{
  border-bottom:1px solid var(--border);padding:13px 26px;
  display:flex;align-items:center;justify-content:space-between;gap:10px;
  background:rgba(10,10,12,.9);backdrop-filter:blur(12px);
  position:sticky;top:0;z-index:50;
}
.topbar-left{display:flex;align-items:center;gap:10px}
.tb-title{font-family:'Playfair Display',serif;font-size:1.1rem;font-weight:700;letter-spacing:-.02em;display:flex;align-items:center;gap:8px}
.tb-sub{font-family:'DM Mono',monospace;font-size:.58rem;color:var(--text3);letter-spacing:.1em;text-transform:uppercase;margin-top:2px}

.content{padding:22px 26px;flex:1}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:var(--r);font-family:'DM Sans',sans-serif;font-size:.8rem;font-weight:500;cursor:pointer;text-decoration:none;border:1px solid transparent;transition:all var(--tr);white-space:nowrap}
.btn svg{width:13px;height:13px;flex-shrink:0}
.btn-primary{background:var(--accent);color:#000;border-color:var(--accent)}
.btn-primary:hover{background:var(--accent2);box-shadow:0 0 16px var(--accent-glow)}
.btn-outline{background:transparent;color:var(--text2);border-color:var(--border2)}
.btn-outline:hover{border-color:var(--accent);color:var(--accent);background:var(--accent-dim)}
.btn-ghost{background:transparent;color:var(--text3);border-color:transparent;padding:5px 9px}
.btn-ghost:hover{color:var(--text);background:var(--bg3)}
.btn-danger{background:transparent;color:var(--danger);border-color:transparent;padding:5px 9px}
.btn-danger:hover{background:var(--danger-dim);border-color:var(--danger)}

/* ── STATS ── */
.stats-row{display:flex;gap:10px;margin-bottom:18px;flex-wrap:wrap}
.stat-card{background:var(--bg2);border:1px solid var(--border);border-radius:var(--r2);padding:12px 16px;flex:1;min-width:90px}
.stat-num{font-family:'Playfair Display',serif;font-size:1.6rem;font-weight:700;line-height:1}
.stat-label{font-family:'DM Mono',monospace;font-size:.56rem;letter-spacing:.12em;text-transform:uppercase;color:var(--text3);margin-top:3px}

/* ── SEARCH ── */
.search-row{display:flex;gap:7px;margin-bottom:14px;flex-wrap:wrap;align-items:center}
.search-wrap{position:relative;flex:1;min-width:160px}
.search-wrap svg{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--text3);width:13px;height:13px;pointer-events:none}
.search-input{width:100%;background:var(--bg2);border:1px solid var(--border);border-radius:var(--r);padding:8px 13px 8px 34px;font-family:'DM Sans',sans-serif;font-size:.82rem;color:var(--text);outline:none;transition:border-color var(--tr)}
.search-input:focus{border-color:var(--border2)}
.search-input::placeholder{color:var(--text3)}

/* ── TABLE ── */
.table-wrap{border:1px solid var(--border);border-radius:var(--r2);overflow:hidden;background:var(--bg2)}
table{width:100%;border-collapse:collapse}
thead th{font-family:'DM Mono',monospace;font-size:.56rem;letter-spacing:.14em;text-transform:uppercase;color:var(--text3);padding:9px 13px;border-bottom:1px solid var(--border);text-align:left;background:var(--bg2);font-weight:400}
tbody tr{border-bottom:1px solid var(--border);transition:background var(--tr)}
tbody tr:last-child{border-bottom:none}
tbody tr:hover{background:var(--bg3)}
tbody td{padding:6px 13px;font-size:.82rem;color:var(--text);vertical-align:middle}
.td-num{font-family:'DM Mono',monospace;font-size:.62rem;color:var(--text3);width:40px}
.td-title{font-weight:500}
.td-artist{color:var(--text2);font-size:.76rem}
.td-actions{width:100px;text-align:right;white-space:nowrap}
.td-actions .aw{display:inline-flex;align-items:center;gap:2px}
.td-actions form{display:inline-flex}
.td-cifra{width:46px}
.td-spot{width:36px}

/* drag */
.drag-handle{cursor:grab;color:var(--text3);padding:0 5px 0 0;opacity:0;transition:opacity var(--tr);display:flex;align-items:center}
tr:hover .drag-handle{opacity:1}
.drag-handle:active{cursor:grabbing}
.ui-sortable-helper{background:var(--bg3)!important;box-shadow:0 8px 28px rgba(0,0,0,.5);opacity:.95}
.ui-sortable-placeholder{visibility:visible!important;background:var(--accent-dim)!important;border:1px dashed var(--accent-glow)!important;height:32px!important}

/* badges */
.badge{display:inline-flex;align-items:center;padding:1px 6px;border-radius:20px;font-family:'DM Mono',monospace;font-size:.56rem;letter-spacing:.1em;text-transform:uppercase}
.badge-green{background:var(--accent-dim);color:var(--accent);border:1px solid var(--accent-glow)}
.badge-gray{background:var(--bg3);color:var(--text3);border:1px solid var(--border)}

/* spotify link */
.spot-link{display:inline-flex;align-items:center;gap:3px;color:#1db954;font-size:.6rem;font-family:'DM Mono',monospace;text-decoration:none;padding:2px 5px;border-radius:4px;border:1px solid rgba(29,185,84,.3);transition:all var(--tr)}
.spot-link:hover{background:rgba(29,185,84,.12)}
.spot-link svg{width:9px;height:9px;flex-shrink:0}

/* saving indicator */
.saving{font-family:'DM Mono',monospace;font-size:.6rem;color:var(--accent);letter-spacing:.08em;opacity:0;transition:opacity .3s}
.saving.show{opacity:1}

/* row flash after edit */
@keyframes rowFlash{0%{background:var(--accent-dim)}100%{background:transparent}}
tr.just-edited td{animation:rowFlash 1.2s ease forwards}

/* ── MODAL ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.75);backdrop-filter:blur(4px);z-index:200;display:flex;align-items:center;justify-content:center;padding:16px;opacity:0;pointer-events:none;transition:opacity var(--tr)}
.modal-overlay.open{opacity:1;pointer-events:all}
.modal{background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r2);padding:26px;width:100%;max-width:460px;transform:translateY(10px);transition:transform var(--tr)}
.modal-overlay.open .modal{transform:translateY(0)}
.modal-title{font-family:'Playfair Display',serif;font-size:1.1rem;font-weight:700;margin-bottom:2px}
.modal-sub{font-size:.76rem;color:var(--text3);margin-bottom:18px}
.modal-footer{display:flex;gap:8px;justify-content:flex-end;margin-top:18px}

/* ── FORMS ── */
.fg{margin-bottom:15px}
.fl{display:block;font-family:'DM Mono',monospace;font-size:.6rem;letter-spacing:.12em;text-transform:uppercase;color:var(--text3);margin-bottom:6px}
.fi{width:100%;background:var(--bg3);border:1px solid var(--border2);border-radius:var(--r);padding:9px 13px;font-family:'DM Sans',sans-serif;font-size:.875rem;color:var(--text);outline:none;transition:border-color var(--tr),-webkit-box-shadow var(--tr)}
.fi:focus{border-color:var(--accent);box-shadow:0 0 0 3px var(--accent-dim)}
.fi::placeholder{color:var(--text3)}
select.fi{background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23555568' stroke-width='2'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;padding-right:32px;cursor:pointer;-webkit-appearance:none}

/* cifra source btns */
.src-btns{display:flex;gap:5px;margin-bottom:7px}
.src-btn{padding:5px 11px;border-radius:6px;border:1px solid var(--border2);background:transparent;color:var(--text2);font-family:'DM Sans',sans-serif;font-size:.76rem;cursor:pointer;transition:all .15s}
.src-btn:hover{border-color:var(--accent);color:var(--accent)}
.src-btn.active{background:var(--accent-dim);border-color:var(--accent);color:var(--accent)}

/* alerts */
.alert{padding:9px 13px;border-radius:var(--r);font-size:.78rem;margin-bottom:14px;display:flex;align-items:center;gap:7px}
.alert-err{background:var(--danger-dim);border:1px solid rgba(224,82,82,.3);color:#f88}
.alert-ok{background:var(--accent-dim);border:1px solid var(--accent-glow);color:var(--accent)}

/* lookup status */
.ls{font-size:.7rem;margin-top:5px;min-height:1.1em;display:flex;align-items:center;gap:5px}
.ls.loading{color:var(--text3)}.ls.ok{color:var(--accent)}.ls.err{color:#f88}
@keyframes spin{to{transform:rotate(360deg)}}

/* read-only notice */
.ron{display:flex;align-items:center;gap:7px;padding:8px 13px;margin-bottom:12px;background:rgba(29,185,84,.07);border:1px solid rgba(29,185,84,.18);border-radius:var(--r);font-size:.76rem;color:var(--text2)}
.ron svg{color:var(--accent);flex-shrink:0}

/* import panel inside sidebar */
.import-panel{padding:10px 10px 6px;border-top:1px solid var(--border)}
.import-panel select{font-size:.72rem;padding:5px 8px}

/* copy toast */
.cp-toast{position:fixed;bottom:22px;right:22px;background:var(--accent);color:#000;font-family:'DM Mono',monospace;font-size:.68rem;letter-spacing:.08em;padding:9px 16px;border-radius:8px;transform:translateY(60px);opacity:0;transition:all .25s cubic-bezier(.4,0,.2,1);z-index:300;pointer-events:none}
.cp-toast.show{transform:translateY(0);opacity:1}

/* hamburger */
.hamburger{display:none;background:none;border:none;color:var(--text);cursor:pointer;padding:5px;border-radius:var(--r)}
.hamburger svg{width:20px;height:20px;display:block}
.sb-backdrop{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:99}

/* scrollbar */
::-webkit-scrollbar{width:5px}
::-webkit-scrollbar-track{background:transparent}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:3px}

/* animations */
@keyframes fadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.fade-up{animation:fadeUp .3s ease forwards}

/* ── MOBILE ── */
@media(max-width:700px){
  :root{--sw:0px}
  .sidebar{transform:translateX(-230px);width:230px;box-shadow:4px 0 24px rgba(0,0,0,.5)}
  .sidebar.open{transform:translateX(0)}
  .sb-backdrop.open{display:block}
  .hamburger{display:flex}
  .main{margin-left:0}
  .topbar{padding:10px 13px}
  .tb-title{font-size:.95rem}
  .tb-sub{display:none}
  .content{padding:13px 11px}
  .stats-row{flex-wrap:nowrap;overflow-x:auto;padding-bottom:4px;gap:7px}
  .stat-card{min-width:80px;padding:9px 11px;flex-shrink:0}
  .stat-num{font-size:1.25rem}
  .search-row .btn-outline{font-size:.7rem;padding:5px 9px}
  .drag-handle{display:none}
  th:nth-child(1),td:nth-child(1){display:none}
  th:nth-child(5),td:nth-child(5){display:none}
  th:nth-child(6),td:nth-child(6){display:none}
  .td-actions{width:60px}
  .btn-ghost,.btn-danger{padding:3px 5px}
  .btn-ghost .btn-lbl,.btn-danger .btn-lbl{display:none}
  .topbar .btn-primary .btn-lbl{display:none}
}

@media print{
  .sidebar,.topbar,.search-row,.td-actions,.btn,.hamburger,.sb-backdrop{display:none!important}
  .main{margin-left:0}
  body{background:#fff;color:#000}
  .table-wrap{border:1px solid #ccc}
  thead th,tbody td{color:#000!important}
  thead th{background:#eee!important}
}
</style>
</head>
<body>

<?php // ── SIDEBAR ──────────────────────────────────────────────
?>
<aside class="sidebar" id="sidebar">
  <div class="sb-logo">
    <div class="wm">Set<span>.</span>List</div>
    <div class="tg">Music Manager</div>
  </div>

  <div class="sb-pls">
    <div class="sb-sec" style="margin-bottom:10px">Listas</div>
    <ul id="plSortable" style="list-style:none;padding:0;margin:0;display:flex;flex-direction:column;gap:2px">
      <?php foreach($playlists as $idx=>$pl):
        $isAct = ($pl['id']===$plId);
        $sUrl  = $pl['spotify_url']??('https://open.spotify.com/playlist/'.$pl['spotify_id']);
      ?>
      <li data-id="<?= htmlspecialchars($pl['id']) ?>">
        <div class="pl-item <?= $isAct?'active':'' ?>">
          <span class="pl-order-btns">
            <?php if($idx>0): ?>
            <span class="pl-act-btn" onclick="movePl('<?= htmlspecialchars($pl['id'],ENT_QUOTES) ?>',-1)" title="Mover para cima">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="18 15 12 9 6 15"/></svg>
            </span>
            <?php else: ?><span class="pl-act-btn" style="opacity:.15;pointer-events:none"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="18 15 12 9 6 15"/></svg></span>
            <?php endif; ?>
            <?php if($idx<count($playlists)-1): ?>
            <span class="pl-act-btn" onclick="movePl('<?= htmlspecialchars($pl['id'],ENT_QUOTES) ?>',1)" title="Mover para baixo">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg>
            </span>
            <?php else: ?><span class="pl-act-btn" style="opacity:.15;pointer-events:none"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 12 15 18 9"/></svg></span>
            <?php endif; ?>
          </span>
          <button class="pl-name-btn" onclick="switchPl('<?= htmlspecialchars($pl['id'],ENT_QUOTES) ?>')">
            <span class="pl-dot"></span>
            <span class="pl-name-text"><?= htmlspecialchars($pl['name']) ?></span>
            <?php if($idx===0): ?><span class="pl-def-badge">padrão</span><?php endif; ?>
          </button>
          <span class="pl-actions">
            <span class="pl-act-btn" onclick="openImportModal('<?= htmlspecialchars($pl['id'],ENT_QUOTES) ?>','<?= htmlspecialchars($pl['name'],ENT_QUOTES) ?>')" title="Importar do Spotify">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg>
            </span>
            <a class="pl-act-btn" href="<?= htmlspecialchars($sUrl) ?>" target="_blank" title="Abrir no Spotify">
              <svg viewBox="0 0 24 24" fill="currentColor" style="color:#1db954"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.586 14.424a.623.623 0 0 1-.857.207c-2.348-1.435-5.304-1.76-8.785-.964a.623.623 0 1 1-.277-1.215c3.809-.87 7.076-.496 9.712 1.115a.623.623 0 0 1 .207.857zm1.223-2.722a.78.78 0 0 1-1.072.257c-2.687-1.652-6.785-2.131-9.965-1.166a.78.78 0 1 1-.453-1.492c3.633-1.102 8.147-.568 11.233 1.329a.78.78 0 0 1 .257 1.072zm.105-2.835C14.692 8.95 9.375 8.775 6.297 9.71a.937.937 0 1 1-.543-1.793c3.521-1.068 9.376-.862 13.066 1.346a.937.937 0 0 1-.906 1.604z"/></svg>
            </a>
            <span class="pl-act-btn" onclick="openEditPlModal('<?= htmlspecialchars($pl['id'],ENT_QUOTES) ?>','<?= htmlspecialchars($pl['spotify_id'],ENT_QUOTES) ?>','<?= htmlspecialchars($pl['name'],ENT_QUOTES) ?>')" title="Editar">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            </span>
            <?php if(count($playlists)>1): ?>
            <span class="pl-act-btn danger" onclick="deletePl('<?= htmlspecialchars($pl['id'],ENT_QUOTES) ?>','<?= htmlspecialchars($pl['name'],ENT_QUOTES) ?>')" title="Remover">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/></svg>
            </span>
            <?php endif; ?>
          </span>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>

  <div class="sb-bottom">
    <?php if($locked): ?>
    <div class="sb-auth">
      <?php if($authed): ?>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <span style="flex:1">Sessão activa</span>
        <a href="?logout" style="color:var(--text3);text-decoration:none;font-size:.62rem">sair</a>
      <?php else: ?>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="var(--text3)" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        <span>Modo leitura</span>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <button class="sb-add-pl" id="addPlBtn">
      <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Nova lista do Spotify
    </button>
  </div>
</aside>
<div class="sb-backdrop" id="backdrop"></div>

<?php // ── MAIN ──────────────────────────────────────────────────
?>
<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="hamburger" id="menuBtn">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
      </button>
      <div>
        <div class="tb-title">
          <?= htmlspecialchars($activePl['name']??'SetList') ?>
          <?php if(!empty($activePl['spotify_id'])): ?>
          <a href="<?= htmlspecialchars($plSpotUrl) ?>" target="_blank" class="spot-link" title="Abrir no Spotify">
            <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.586 14.424a.623.623 0 0 1-.857.207c-2.348-1.435-5.304-1.76-8.785-.964a.623.623 0 1 1-.277-1.215c3.809-.87 7.076-.496 9.712 1.115a.623.623 0 0 1 .207.857zm1.223-2.722a.78.78 0 0 1-1.072.257c-2.687-1.652-6.785-2.131-9.965-1.166a.78.78 0 1 1-.453-1.492c3.633-1.102 8.147-.568 11.233 1.329a.78.78 0 0 1 .257 1.072zm.105-2.835C14.692 8.95 9.375 8.775 6.297 9.71a.937.937 0 1 1-.543-1.793c3.521-1.068 9.376-.862 13.066 1.346a.937.937 0 0 1-.906 1.604z"/></svg>
            Spotify
          </a>
          <?php endif; ?>
        </div>
        <div class="tb-sub">
          <?= $totalSongs ?> músicas<?= $durStr?' · '.$durStr:'' ?>
          <?php if(!empty($activePl['is_default'])): ?> · <span style="color:var(--accent)">✦ padrão</span><?php endif; ?>
        </div>
      </div>
    </div>
    <div style="display:flex;gap:7px;align-items:center">
      <span class="saving" id="savingInd">Salvo ✓</span>
      <button class="btn btn-outline" id="copyListBtn" title="Copiar lista como texto">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
        <span class="btn-lbl">Copiar</span>
      </button>
      <button class="btn btn-primary" id="addSongBtn">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        <span class="btn-lbl">Adicionar</span>
      </button>
    </div>
  </div>

  <div class="content fade-up">
    <?php if(!$authed&&$locked): ?>
    <div class="ron">
      <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      Modo leitura — clica em qualquer acção para autenticar.
    </div>
    <?php endif; ?>

    <div class="stats-row">
      <div class="stat-card"><div class="stat-num"><?= str_pad($totalSongs,2,'0',STR_PAD_LEFT) ?></div><div class="stat-label">Músicas</div></div>
      <div class="stat-card"><div class="stat-num"><?= str_pad($artistCount,2,'0',STR_PAD_LEFT) ?></div><div class="stat-label">Artistas</div></div>
      <div class="stat-card"><div class="stat-num"><?= str_pad($withCifra,2,'0',STR_PAD_LEFT) ?></div><div class="stat-label">Com cifra</div></div>
      <?php if($durStr): ?>
      <div class="stat-card"><div class="stat-num" style="font-size:1.2rem"><?= $durStr ?></div><div class="stat-label">Duração</div></div>
      <?php endif; ?>
    </div>

    <div class="search-row">
      <div class="search-wrap">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input type="text" class="search-input" id="searchInput" placeholder="Buscar…">
      </div>
      <a href="?pl=<?= urlencode($plId) ?>&sort=title&order=<?= ($sortCol==='title'&&$sortOrd==='asc')?'desc':'asc' ?>" class="btn btn-outline">A→Z Título</a>
      <a href="?pl=<?= urlencode($plId) ?>&sort=artist&order=<?= ($sortCol==='artist'&&$sortOrd==='asc')?'desc':'asc' ?>" class="btn btn-outline">A→Z Artista</a>
    </div>

    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th style="width:34px"></th>
            <th class="td-num">#</th>
            <th>Título</th>
            <th>Artista</th>
            <th class="td-cifra">Cifra</th>
            <th class="td-spot"></th>
            <th class="td-actions">Ações</th>
          </tr>
        </thead>
        <tbody id="songList">
          <?php foreach($songs as $i=>$song):
            $cu  = cifraUrl($song); $cl = cifraLabel($song);
            $cr  = $song['cifra_url']??''; $cs=detectSrc($cr);
            $su  = $song['spotify_url']??'';
          ?>
          <tr data-i="<?= $i ?>"
              data-title="<?= htmlspecialchars($song['title'],ENT_QUOTES) ?>"
              data-artist="<?= htmlspecialchars($song['artist'],ENT_QUOTES) ?>"
              data-cifra-url="<?= htmlspecialchars($cr,ENT_QUOTES) ?>"
              data-cifra-src="<?= htmlspecialchars($cs,ENT_QUOTES) ?>"
              class="song-row">
            <td style="width:34px;padding-right:0">
              <span class="drag-handle">
                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="5" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="19" r="1"/></svg>
              </span>
            </td>
            <td class="td-num"><?= str_pad($i+1,2,'0',STR_PAD_LEFT) ?></td>
            <td class="td-title"><?= htmlspecialchars($song['title']) ?></td>
            <td class="td-artist"><?= htmlspecialchars($song['artist']) ?></td>
            <td class="td-cifra">
              <?php if($cu): ?>
                <a href="<?= htmlspecialchars($cu) ?>" target="_blank" class="badge badge-green" style="text-decoration:none"><?= $cl ?></a>
              <?php else: ?>
                <span class="badge badge-gray">—</span>
              <?php endif; ?>
            </td>
            <td class="td-spot">
              <?php if($su): ?>
                <a href="<?= htmlspecialchars($su) ?>" target="_blank" class="spot-link">
                  <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.586 14.424a.623.623 0 0 1-.857.207c-2.348-1.435-5.304-1.76-8.785-.964a.623.623 0 1 1-.277-1.215c3.809-.87 7.076-.496 9.712 1.115a.623.623 0 0 1 .207.857zm1.223-2.722a.78.78 0 0 1-1.072.257c-2.687-1.652-6.785-2.131-9.965-1.166a.78.78 0 1 1-.453-1.492c3.633-1.102 8.147-.568 11.233 1.329a.78.78 0 0 1 .257 1.072zm.105-2.835C14.692 8.95 9.375 8.775 6.297 9.71a.937.937 0 1 1-.543-1.793c3.521-1.068 9.376-.862 13.066 1.346a.937.937 0 0 1-.906 1.604z"/></svg>
                </a>
              <?php endif; ?>
            </td>
            <td class="td-actions">
              <div class="aw">
                <button class="btn btn-ghost edit-btn" data-i="<?= $i ?>" title="Editar">
                  <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                  <span class="btn-lbl">Editar</span>
                </button>
                <button class="btn btn-danger del-btn" data-i="<?= $i ?>" title="Excluir">
                  <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php // ── MODALS ────────────────────────────────────────────────
?>

<!-- Song modal (add / edit) -->
<div class="modal-overlay" id="songModal">
  <div class="modal">
    <div class="modal-title" id="songModalTitle">Adicionar Música</div>
    <div class="modal-sub" id="songModalSub"></div>
    <input type="hidden" id="songModalMode" value="add">
    <input type="hidden" id="songModalIdx" value="">
    <div class="fg">
      <label class="fl">Título</label>
      <input class="fi" type="text" id="smTitle" placeholder="Ex: Resposta">
    </div>
    <div class="fg">
      <label class="fl">Artista</label>
      <input class="fi" type="text" id="smArtist" placeholder="Ex: Skank">
    </div>
    <div class="fg">
      <label class="fl">Fonte da Cifra</label>
      <div class="src-btns">
        <button type="button" class="src-btn active" onclick="setSrc('cifraclub')" data-src="cifraclub">Cifra Club</button>
        <button type="button" class="src-btn" onclick="setSrc('ultimate_guitar')" data-src="ultimate_guitar">Ultimate Guitar</button>
        <button type="button" class="src-btn" onclick="setSrc('other')" data-src="other">Outro / URL</button>
      </div>
      <input class="fi" type="text" id="smCifraUrl" placeholder="skank/resposta ou URL completa">
      <div style="font-size:.68rem;color:var(--text3);margin-top:4px" id="smCifraHint"></div>
    </div>
    <div id="smError" class="alert alert-err" style="display:none"></div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('songModal')">Cancelar</button>
      <button class="btn btn-primary" id="smSaveBtn">Salvar</button>
    </div>
  </div>
</div>

<!-- Add playlist modal -->
<div class="modal-overlay" id="addPlModal">
  <div class="modal">
    <div class="modal-title">Nova Lista</div>
    <div class="modal-sub">Cole o ID ou URL da playlist — o nome é buscado automaticamente.</div>
    <div class="fg">
      <label class="fl">ID ou URL do Spotify</label>
      <input class="fi" type="text" id="plSpotRaw" placeholder="4pcomesNQA6… ou https://open.spotify.com/playlist/…" autocomplete="off">
      <div class="ls" id="plLookupStatus"></div>
    </div>
    <div id="plAddError" class="alert alert-err" style="display:none"></div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('addPlModal')">Cancelar</button>
      <button class="btn btn-primary" id="plAddBtn" disabled>Criar e Importar →</button>
    </div>
  </div>
</div>

<!-- Edit playlist modal -->
<div class="modal-overlay" id="editPlModal">
  <div class="modal">
    <div class="modal-title">Editar Lista</div>
    <div class="modal-sub" id="editPlSub"></div>
    <div class="fg">
      <label class="fl">ID da Playlist Spotify</label>
      <input class="fi" type="text" id="editPlSpotId">
      <div style="font-size:.68rem;color:var(--text3);margin-top:4px">Ao alterar o ID, o nome da lista é actualizado ao guardar.</div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('editPlModal')">Cancelar</button>
      <button class="btn btn-primary" id="editPlSaveBtn">Salvar</button>
    </div>
  </div>
</div>

<!-- Import modal -->
<div class="modal-overlay" id="importModal">
  <div class="modal">
    <div class="modal-title">Importar do Spotify</div>
    <div class="modal-sub" id="importModalSub"></div>
    <?php if(!$hasSpot): ?>
    <div class="alert alert-err">
      Credenciais Spotify não configuradas no <code style="font-family:'DM Mono',monospace">.env</code>.<br>
      Adiciona <code style="font-family:'DM Mono',monospace;font-size:.72rem">CLIENT_ID</code> e <code style="font-family:'DM Mono',monospace;font-size:.72rem">CLIENT_SECRET</code>.
    </div>
    <?php else: ?>
    <div class="fg">
      <label class="fl">Modo</label>
      <div style="display:flex;flex-direction:column;gap:7px;margin-top:3px">
        <label style="display:flex;gap:9px;align-items:flex-start;cursor:pointer">
          <input type="radio" name="imp_mode" value="replace" id="impReplace" checked style="margin-top:3px;accent-color:var(--accent)">
          <div><div style="font-size:.82rem;font-weight:500">Substituir</div><div style="font-size:.7rem;color:var(--text3)">Apaga a lista e importa tudo do Spotify</div></div>
        </label>
        <label style="display:flex;gap:9px;align-items:flex-start;cursor:pointer">
          <input type="radio" name="imp_mode" value="merge" id="impMerge" style="margin-top:3px;accent-color:var(--accent)">
          <div><div style="font-size:.82rem;font-weight:500">Mesclar</div><div style="font-size:.7rem;color:var(--text3)">Adiciona músicas novas sem apagar existentes</div></div>
        </label>
      </div>
    </div>
    <div id="importResult" class="alert alert-ok" style="display:none"></div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('importModal')">Fechar</button>
      <button class="btn btn-primary" id="importDoBtn">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg>
        Importar
      </button>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Lock modal -->
<div class="modal-overlay" id="lockModal">
  <div class="modal" style="max-width:340px;text-align:center">
    <div style="color:var(--accent);margin-bottom:10px">
      <svg xmlns="http://www.w3.org/2000/svg" width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    </div>
    <div class="modal-title" style="font-size:1rem">Confirmar identidade</div>
    <div class="modal-sub">Insere a senha para continuar.</div>
    <div id="lockErr" class="alert alert-err" style="display:none;margin-bottom:10px"></div>
    <input class="fi" type="password" id="lockPwd" placeholder="••••••••"
           style="text-align:center;letter-spacing:.2em;font-size:1rem;margin-bottom:12px">
    <div style="display:flex;gap:7px;justify-content:center">
      <button class="btn btn-outline" onclick="closeLockModal()">Cancelar</button>
      <button class="btn btn-primary" id="lockBtn">Entrar</button>
    </div>
  </div>
</div>

<div class="cp-toast" id="cpToast">Copiado ✓</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
<script>
// ── State ──────────────────────────────────────────────────────
var PL_ID   = <?= json_encode($plId) ?>;
var LOCKED  = <?= $locked?'true':'false' ?>;
var AUTHED  = <?= $authed?'true':'false' ?>;
var HAS_SPOT= <?= $hasSpot?'true':'false' ?>;
var SONGS   = <?php
  $songData = array();
  foreach($songs as $s) {
      $songData[] = array(
          'title'       => $s['title'],
          'artist'      => $s['artist'],
          'cifra_url'   => isset($s['cifra_url']) ? $s['cifra_url'] : '',
          'cifra_source'=> isset($s['cifra_source']) ? $s['cifra_source'] : 'cifraclub',
          'spotify_url' => isset($s['spotify_url']) ? $s['spotify_url'] : '',
      );
  }
  echo json_encode($songData, JSON_UNESCAPED_UNICODE);
?>;

var _lockCb = null;
var _editPlId = null;
var _importPlId = null;
var _curSongSrc = 'cifraclub';

var srcHints = {
  cifraclub:       'Caminho: <strong>skank/resposta</strong> ou URL completa do Cifra Club.',
  ultimate_guitar: 'Cole a URL completa do Ultimate Guitar.',
  other:           'Cole qualquer URL de cifra.'
};
var srcPH = {
  cifraclub:       'skank/resposta  ou  URL completa',
  ultimate_guitar: 'https://tabs.ultimate-guitar.com/...',
  other:           'https://...'
};

// ── Helpers ────────────────────────────────────────────────────
function post(data, cb) {
  data.pl = PL_ID;
  $.post(window.location.pathname + '?pl=' + PL_ID, data, cb, 'json')
   .fail(function(){ alert('Erro de rede.'); });
}

function showSaving(m) { $('#savingInd').text(m||'Salvo ✓').addClass('show'); }
function hideSaving()  { setTimeout(function(){ $('#savingInd').removeClass('show'); }, 1800); }

function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function toast(m) {
  var t=$('#cpToast'); t.text(m||'Copiado ✓').addClass('show');
  setTimeout(function(){ t.removeClass('show'); }, 2200);
}

function renumber() {
  $('#songList tr.song-row:visible').each(function(i){
    $(this).find('.td-num').text(String(i+1).padStart(2,'0'));
  });
}

// ── Auth (lock modal) ──────────────────────────────────────────
function guardedAction(fn) {
  if (!LOCKED || AUTHED) { fn(); return; }
  _lockCb = fn;
  openLockModal(fn);
}
function openLockModal(cb) {
  _lockCb = cb || null;
  $('#lockPwd').val(''); $('#lockErr').hide();
  openModal('lockModal');
  setTimeout(function(){ $('#lockPwd').focus(); }, 80);
}
function closeLockModal() { closeModal('lockModal'); _lockCb=null; }

$('#lockBtn').on('click', function() {
  var pwd=$('#lockPwd').val();
  $(this).prop('disabled',true).text('…');
  $.post(location.pathname+'?pl='+PL_ID, {_action:'_login', password:pwd}, function(r){
    $('#lockBtn').prop('disabled',false).text('Entrar');
    if(r.ok){ AUTHED=true; closeLockModal(); if(_lockCb){var f=_lockCb;_lockCb=null;f();} }
    else { $('#lockErr').text(r.error||'Senha incorreta.').show(); $('#lockPwd').select(); }
  },'json');
});
$('#lockPwd').on('keydown',function(e){
  if(e.key==='Enter') $('#lockBtn').click();
  if(e.key==='Escape') closeLockModal();
});

// Close any modal on backdrop click
$(document).on('click','.modal-overlay',function(e){ if(e.target===this) $(this).removeClass('open'); });

// ── Mobile sidebar ─────────────────────────────────────────────
$('#menuBtn').on('click',function(){ $('#sidebar').toggleClass('open'); $('#backdrop').toggleClass('open'); });
$('#backdrop').on('click',function(){ $('#sidebar,#backdrop').removeClass('open'); });

// ── Switch playlist ────────────────────────────────────────────
function switchPl(id) {
  window.location.href = '?pl=' + encodeURIComponent(id);
}

// ── Song drag reorder ──────────────────────────────────────────
$(function(){
  $('#songList').sortable({
    handle: '.drag-handle',
    placeholder: 'ui-sortable-placeholder',
    start: function(e,ui){
      if(LOCKED&&!AUTHED){
        $('#songList').sortable('cancel');
        guardedAction(function(){});
        return false;
      }
    },
    update: function(){
      var ids=$('#songList tr.song-row').map(function(){ return $(this).data('i'); }).get();
      showSaving('Salvando…');
      post({_action:'reorder_songs', order:JSON.stringify(ids)}, function(){
        showSaving(); hideSaving(); renumber();
      });
    }
  });
});

// ── Sidebar playlist order — up/down buttons ───────────────────
function movePl(id, dir) {
  guardedAction(function(){
    var items = $('#plSortable li');
    var idx = -1;
    items.each(function(i){ if($(this).data('id')===id) idx=i; });
    if(idx<0) return;
    var newIdx = idx + dir;
    if(newIdx<0 || newIdx>=items.length) return;

    // Swap in DOM
    var el = items.eq(idx);
    if(dir < 0) el.insertBefore(items.eq(newIdx));
    else        el.insertAfter(items.eq(newIdx));

    // Save new order
    var ids=$('#plSortable li').map(function(){ return $(this).data('id'); }).get();
    post({_action:'reorder_pls', order:JSON.stringify(ids)}, function(r){
      if(r.ok){
        $('#plSortable li').each(function(i){
          var badge=$(this).find('.pl-def-badge');
          if(i===0){ if(!badge.length) $(this).find('.pl-name-text').after(' <span class="pl-def-badge">padrão</span>'); }
          else badge.remove();
        });
      }
    });
  });
}

// ── Search ─────────────────────────────────────────────────────
$('#searchInput').on('input', function(){
  var q=$(this).val().toLowerCase();
  $('.song-row').each(function(){
    var t=$(this).find('.td-title').text().toLowerCase();
    var a=$(this).find('.td-artist').text().toLowerCase();
    $(this).toggle(!q||t.includes(q)||a.includes(q));
  });
});

// ── Cifra source selector ──────────────────────────────────────
function setSrc(src){
  _curSongSrc=src;
  $('#smCifraUrl').attr('placeholder', srcPH[src]||'');
  $('#smCifraHint').html(srcHints[src]||'');
  $('.src-btn').each(function(){ $(this).toggleClass('active',$(this).data('src')===src); });
}

// ── Song modal ─────────────────────────────────────────────────
function openSongModal(mode, idx) {
  $('#smError').hide();
  if(mode==='add'){
    $('#songModalTitle').text('Adicionar Música');
    $('#songModalSub').text('');
    $('#smTitle,#smArtist,#smCifraUrl').val('');
    setSrc('cifraclub');
    $('#songModalMode').val('add'); $('#songModalIdx').val('');
    $('#smSaveBtn').text('Adicionar');
  } else {
    var s=SONGS[idx];
    $('#songModalTitle').text('Editar Música');
    $('#songModalSub').text('#'+String(idx+1).padStart(2,'0'));
    $('#smTitle').val(s.title); $('#smArtist').val(s.artist);
    var cu=s.cifra_url==='N/A'?'':s.cifra_url;
    $('#smCifraUrl').val(cu);
    setSrc(s.cifra_source||'cifraclub');
    $('#songModalMode').val('edit'); $('#songModalIdx').val(idx);
    $('#smSaveBtn').text('Salvar');
  }
  openModal('songModal');
  setTimeout(function(){ $('#smTitle').focus(); }, 80);
}

$('#addSongBtn').on('click', function(){ guardedAction(function(){ openSongModal('add'); }); });

$(document).on('click','.edit-btn',function(){
  var idx=parseInt($(this).data('i'));
  guardedAction(function(){ openSongModal('edit',idx); });
});

$('#smSaveBtn').on('click', saveSong);
$('#songModal').on('keydown',function(e){ if(e.key==='Enter') saveSong(); if(e.key==='Escape') closeModal('songModal'); });

function saveSong(){
  var mode=$('#songModalMode').val();
  var title=$('#smTitle').val().trim();
  var artist=$('#smArtist').val().trim();
  var cu=$('#smCifraUrl').val().trim();
  if(!title||!artist){ $('#smError').text('Título e artista são obrigatórios.').show(); return; }
  $('#smSaveBtn').prop('disabled',true).text('…');

  var data={title:title,artist:artist,cifra_url:cu,cifra_source:_curSongSrc};
  if(mode==='edit'){
    data._action='edit_song'; data.index=$('#songModalIdx').val();
    post(data,function(r){
      $('#smSaveBtn').prop('disabled',false).text('Salvar');
      if(!r.ok){ $('#smError').text('Erro ao salvar.').show(); return; }
      var idx=parseInt(data.index);
      SONGS[idx].title=r.title; SONGS[idx].artist=r.artist;
      SONGS[idx].cifra_url=data.cifra_url; SONGS[idx].cifra_source=data.cifra_source;
      var row=$('#songList tr[data-i="'+idx+'"]');
      row.find('.td-title').text(r.title);
      row.find('.td-artist').text(r.artist);
      row.data('title',r.title).data('artist',r.artist);
      // Update cifra badge
      var cell=row.find('.td-cifra');
      if(r.cifra_url){ cell.html('<a href="'+r.cifra_url+'" target="_blank" class="badge badge-green" style="text-decoration:none">'+r.cifra_label+'</a>'); }
      else { cell.html('<span class="badge badge-gray">—</span>'); }
      row.addClass('just-edited'); setTimeout(function(){ row.removeClass('just-edited'); },1300);
      closeModal('songModal');
    });
  } else {
    data._action='add_song';
    post(data,function(r){
      $('#smSaveBtn').prop('disabled',false).text('Adicionar');
      if(!r.ok){ $('#smError').text(r.error||'Erro ao adicionar.').show(); return; }
      SONGS.push({title:r.title,artist:r.artist,cifra_url:data.cifra_url,cifra_source:data.cifra_source,spotify_url:''});
      var num=String(r.index+1).padStart(2,'0');
      var cifraBadge=r.cifra_url?'<a href="'+r.cifra_url+'" target="_blank" class="badge badge-green" style="text-decoration:none">'+r.cifra_label+'</a>':'<span class="badge badge-gray">—</span>';
      var row='<tr data-i="'+r.index+'" data-title="'+escH(r.title)+'" data-artist="'+escH(r.artist)+'" data-cifra-url="'+escH(data.cifra_url)+'" data-cifra-src="'+escH(data.cifra_source)+'" class="song-row">'
        +'<td style="width:34px;padding-right:0"><span class="drag-handle"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="5" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="19" r="1"/></svg></span></td>'
        +'<td class="td-num">'+num+'</td>'
        +'<td class="td-title">'+escH(r.title)+'</td>'
        +'<td class="td-artist">'+escH(r.artist)+'</td>'
        +'<td class="td-cifra">'+cifraBadge+'</td>'
        +'<td class="td-spot"></td>'
        +'<td class="td-actions"><div class="aw"><button class="btn btn-ghost edit-btn" data-i="'+r.index+'" title="Editar"><svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg><span class="btn-lbl">Editar</span></button>'
        +'<button class="btn btn-danger del-btn" data-i="'+r.index+'" title="Excluir"><svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg></button>'
        +'</div></td></tr>';
      $('#songList').append(row);
      closeModal('songModal');
      toast('Música adicionada!');
    });
  }
}

// ── Delete song ────────────────────────────────────────────────
$(document).on('click','.del-btn',function(){
  var idx=parseInt($(this).data('i'));
  guardedAction(function(){
    if(!confirm('Excluir esta música?')) return;
    post({_action:'delete_song',index:idx},function(r){
      if(r.ok){
        $('tr[data-i="'+idx+'"]').fadeOut(200,function(){ $(this).remove(); renumber(); });
        SONGS.splice(idx,1);
      }
    });
  });
});

// ── Add playlist ───────────────────────────────────────────────
var _lookupTimer=null, _lookupOk=false, _lookupId=null;

function extractSpotId(raw){
  raw=raw.trim();
  // Full URL: extract ID after /playlist/
  var m=raw.match(/playlist\/([A-Za-z0-9]{10,})/);
  if(m) return m[1];
  // Raw ID: alphanumeric, 10–40 chars
  if(/^[A-Za-z0-9]{10,40}$/.test(raw)) return raw;
  return null;
}

$('#addPlBtn').on('click',function(){
  guardedAction(function(){
    $('#plSpotRaw').val(''); $('#plLookupStatus').text('').attr('class','ls');
    $('#plAddBtn').prop('disabled',true); $('#plAddError').hide();
    _lookupOk=false; _lookupId=null;
    openModal('addPlModal');
    setTimeout(function(){ $('#plSpotRaw').focus(); },80);
  });
});

$('#plSpotRaw').on('input',function(){
  clearTimeout(_lookupTimer); _lookupOk=false; _lookupId=null;
  $('#plAddBtn').prop('disabled',true);
  var id=extractSpotId($(this).val());
  if(!id){ $('#plLookupStatus').text($(this).val().length>3?'ID não reconhecido.':'').attr('class','ls'); return; }
  if(!HAS_SPOT){
    _lookupId=id; _lookupOk=true; $('#plAddBtn').prop('disabled',false);
    $('#plLookupStatus').html('✓ ID: '+id+' (sem credenciais Spotify — nome genérico)').attr('class','ls ok');
    return;
  }
  $('#plLookupStatus').html('<span style="display:inline-block;animation:spin 1s linear infinite">⟳</span> A buscar…').attr('class','ls loading');
  _lookupTimer=setTimeout(function(){
    $.get('?pl='+PL_ID,{spot_lookup:id},function(r){
      if(r.ok){
        _lookupId=id; _lookupOk=true; $('#plAddBtn').prop('disabled',false);
        $('#plLookupStatus').html('✓ <strong>'+escH(r.name)+'</strong> &nbsp;<a href="'+r.url+'" target="_blank" style="color:var(--accent);font-size:.65rem">abrir ↗</a>').attr('class','ls ok');
      } else {
        $('#plLookupStatus').text('✗ '+(r.error||'Não encontrada')).attr('class','ls err');
      }
    },'json').fail(function(){ $('#plLookupStatus').text('✗ Erro de ligação').attr('class','ls err'); });
  },600);
});
$('#plSpotRaw').on('keydown',function(e){ if(e.key==='Enter'&&_lookupOk) $('#plAddBtn').click(); });

$('#plAddBtn').on('click',function(){
  if(!_lookupOk||!_lookupId) return;
  $(this).prop('disabled',true).text('A importar…');
  $.post('?pl='+PL_ID,{_action:'add_pl',spotify_id:_lookupId,pl:PL_ID},function(r){
    $('#plAddBtn').prop('disabled',false).text('Criar e Importar →');
    if(r.ok){ window.location.href='?pl='+encodeURIComponent(r.id); }
    else { $('#plAddError').text(r.error||'Erro').show(); }
  },'json');
});

// ── Edit playlist ──────────────────────────────────────────────
function openEditPlModal(id,spotId,name){
  guardedAction(function(){
    _editPlId=id;
    $('#editPlSub').text('Lista: '+name);
    $('#editPlSpotId').val(spotId);
    openModal('editPlModal');
    setTimeout(function(){ $('#editPlSpotId').focus(); },80);
  });
}
$('#editPlSaveBtn').on('click',function(){
  var newSpotId=$('#editPlSpotId').val().trim();
  $(this).prop('disabled',true).text('…');
  $.post('?pl='+PL_ID,{_action:'edit_pl',target_id:_editPlId,spotify_id:newSpotId,pl:PL_ID},function(r){
    $('#editPlSaveBtn').prop('disabled',false).text('Salvar');
    if(r.ok){ closeModal('editPlModal'); window.location.reload(); }
  },'json');
});

// ── Delete playlist ────────────────────────────────────────────
function deletePl(id,name){
  guardedAction(function(){
    if(!confirm('Remover "'+name+'"? As músicas guardadas não são apagadas.')) return;
    $.post('?pl='+PL_ID,{_action:'delete_pl',target_id:id,pl:PL_ID},function(r){
      if(r.ok){ window.location.href='?'; }
    },'json');
  });
}

// ── Import ─────────────────────────────────────────────────────
function openImportModal(plId,plName){
  guardedAction(function(){
    _importPlId=plId;
    $('#importModalSub').text('Playlist: '+plName);
    $('#importResult').hide(); $('#impReplace').prop('checked',true);
    openModal('importModal');
  });
}
$('#importDoBtn').on('click',function(){
  var mode=$('input[name="imp_mode"]:checked').val();
  $(this).prop('disabled',true).text('A importar…');
  $.post('?pl='+PL_ID,{_action:'import',target_id:_importPlId,mode:mode,pl:PL_ID},function(r){
    $('#importDoBtn').prop('disabled',false).html('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg> Importar');
    if(r.ok){
      $('#importResult').text(r.count+' músicas importadas para "'+r.name+'".').show();
      if(_importPlId===PL_ID) setTimeout(function(){ window.location.reload(); },1200);
    }
  },'json');
});

// ── Copy list as text ──────────────────────────────────────────
$('#copyListBtn').on('click',function(){
  var name=<?= json_encode($activePl['name']??'SetList') ?>;
  var date=new Date().toLocaleDateString('pt-BR',{day:'2-digit',month:'2-digit',year:'numeric'});
  var lines=['═══════════════════════════════════','  '+name.toUpperCase(),'  '+SONGS.length+' músicas · '+date,'═══════════════════════════════════',''];
  SONGS.forEach(function(s,i){ lines.push(String(i+1).padStart(2,'0')+'.  '+s.title+'  —  '+s.artist); });
  lines.push(''); lines.push('───────────────────────────────────');
  var text=lines.join('\n');
  if(navigator.clipboard&&navigator.clipboard.writeText){
    navigator.clipboard.writeText(text).then(function(){ toast('Copiado ✓'); });
  } else {
    var ta=document.createElement('textarea');ta.value=text;ta.style.cssText='position:fixed;opacity:0';
    document.body.appendChild(ta);ta.select();document.execCommand('copy');document.body.removeChild(ta);
    toast('Copiado ✓');
  }
});

// ── Utility ────────────────────────────────────────────────────
function escH(s){ return $('<span>').text(s).html(); }
</script>
</body>
</html>
