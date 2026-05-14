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
    $kw = 'live|ao vivo|remaster(?:ed)?(?:\s+\d{4})?|\d{4}\s+remaster(?:ed)?|\d{4}[\w\s\-]*mix'
        . '|bonus track|explicit|radio edit|single version|album version|deluxe|acoustic'
        . '|demo|instrumental|extended|intro|outro';
    $t = preg_replace('/\s*[\(\[]\s*(?:'.$kw.')[^\)\]]*[\)\]]/iu', '', $t);
    $t = preg_replace('/\s+-\s+(?:'.$kw.').*/iu', '', $t);
    $t = preg_replace('/\s*-\s*\d{4}\s+remaster(?:ed)?\s*$/iu', '', $t);
    return trim($t);
}

function toSlugPHP($s) {
    $s = mb_strtolower($s, 'UTF-8');
    $map = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
            'é'=>'e','ê'=>'e','ë'=>'e','è'=>'e',
            'í'=>'i','î'=>'i','ï'=>'i','ì'=>'i',
            'ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ò'=>'o',
            'ú'=>'u','û'=>'u','ü'=>'u','ù'=>'u',
            'ç'=>'c','ñ'=>'n','ý'=>'y'];
    $s = strtr($s, $map);
    $s = preg_replace('/[^a-z0-9\s-]/', '', $s);
    $s = preg_replace('/[\s_]+/', '-', trim($s));
    return trim(preg_replace('/-+/', '-', $s), '-');
}

function cifraUrlAuto($song) {
    $u = $song['cifra_url'] ?? ''; $s = $song['cifra_source'] ?? 'cifraclub';
    // Has a real saved URL — use it
    if ($u && $u !== 'N/A') {
        if (preg_match('/^https?:\/\//', $u)) return $u;
        if ($s === 'ultimate_guitar') return 'https://tabs.ultimate-guitar.com/'.ltrim($u,'/');
        return 'https://www.cifraclub.com.br/'.ltrim($u,'/');
    }
    // Only auto-generate for cifraclub source (or unset)
    if ($s && $s !== 'cifraclub') return null;
    $artist = $song['artist'] ?? ''; $title = $song['title'] ?? '';
    if (!$artist || !$title) return null;
    $aSlug = toSlugPHP($artist);
    $tSlug = toSlugPHP($title);
    if (!$aSlug || !$tSlug) return null;
    return "https://www.cifraclub.com.br/{$aSlug}/{$tSlug}/";
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

// ── Spotify OAuth (Authorization Code) ──────────────────────────
function spotOAuthUrl() {
    [$id,] = spotCreds();
    if (!$id) return null;
    $redirect = spotRedirectUri();
    $scope = 'playlist-modify-public playlist-modify-private playlist-read-private';
    $state = bin2hex(random_bytes(8));
    $_SESSION['spot_oauth_state'] = $state;
    return 'https://accounts.spotify.com/authorize?'
        . http_build_query([
            'client_id'     => $id,
            'response_type' => 'code',
            'redirect_uri'  => $redirect,
            'scope'         => $scope,
            'state'         => $state,
        ]);
}
function spotRedirectUri() {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'http';
    return $proto.'://'.$_SERVER['HTTP_HOST'].strtok($_SERVER['REQUEST_URI'],'?').'?spot_oauth_cb=1';
}
function spotExchangeCode($code) {
    [$id,$sec] = spotCreds();
    $ch = curl_init('https://accounts.spotify.com/api/token');
    curl_setopt_array($ch,[
        CURLOPT_HTTPHEADER     => ['Authorization: Basic '.base64_encode("$id:$sec"),'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => http_build_query(['grant_type'=>'authorization_code','code'=>$code,'redirect_uri'=>spotRedirectUri()]),
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false, CURLOPT_TIMEOUT => 15,
    ]);
    $r = json_decode(curl_exec($ch),true); curl_close($ch);
    if (!empty($r['access_token'])) {
        $_SESSION['spot_user_token']   = $r['access_token'];
        $_SESSION['spot_user_refresh'] = $r['refresh_token'] ?? '';
        $_SESSION['spot_user_expiry']  = time() + ($r['expires_in'] ?? 3600) - 60;
        return true;
    }
    return false;
}
function spotRefreshUserToken() {
    if (empty($_SESSION['spot_user_refresh'])) return false;
    [$id,$sec] = spotCreds();
    $ch = curl_init('https://accounts.spotify.com/api/token');
    curl_setopt_array($ch,[
        CURLOPT_HTTPHEADER     => ['Authorization: Basic '.base64_encode("$id:$sec"),'Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
        CURLOPT_POSTFIELDS     => http_build_query(['grant_type'=>'refresh_token','refresh_token'=>$_SESSION['spot_user_refresh']]),
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false, CURLOPT_TIMEOUT => 15,
    ]);
    $r = json_decode(curl_exec($ch),true); curl_close($ch);
    if (!empty($r['access_token'])) {
        $_SESSION['spot_user_token']  = $r['access_token'];
        $_SESSION['spot_user_expiry'] = time() + ($r['expires_in'] ?? 3600) - 60;
        return true;
    }
    return false;
}
function spotUserToken() {
    if (empty($_SESSION['spot_user_token'])) return null;
    if (!empty($_SESSION['spot_user_expiry']) && time() > $_SESSION['spot_user_expiry']) {
        if (!spotRefreshUserToken()) return null;
    }
    return $_SESSION['spot_user_token'];
}
function hasSpotUserToken() { return !empty(spotUserToken()); }

// Get Spotify user id
function spotUserId($tok) {
    $ch = curl_init('https://api.spotify.com/v1/me');
    curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>["Authorization: Bearer $tok"],CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>false,CURLOPT_TIMEOUT=>10]);
    $r = json_decode(curl_exec($ch),true); curl_close($ch);
    return $r['id'] ?? null;
}

// Get track IDs currently in a Spotify playlist
function spotPlaylistTrackUris($tok,$plId) {
    $uris=[]; $url="https://api.spotify.com/v1/playlists/$plId/tracks?limit=100&fields=next,items(track(uri,name,artists))";
    while($url) {
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>["Authorization: Bearer $tok"],CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>false,CURLOPT_TIMEOUT=>20]);
        $d=json_decode(curl_exec($ch),true); curl_close($ch);
        if(empty($d['items'])) break;
        foreach($d['items'] as $item) {
            $t=$item['track']??null;
            if($t&&!empty($t['uri'])) $uris[$t['uri']]=['name'=>$t['name']??'','artist'=>$t['artists'][0]['name']??''];
        }
        $url=$d['next']??null;
    }
    return $uris;
}

// Add tracks to Spotify playlist (max 100 per call)
function spotAddTracks($tok,$plId,$uris) {
    foreach(array_chunk($uris,100) as $chunk){
        $ch=curl_init("https://api.spotify.com/v1/playlists/$plId/tracks");
        curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>["Authorization: Bearer $tok","Content-Type: application/json"],CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['uris'=>array_values($chunk)]),CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>false,CURLOPT_TIMEOUT=>20]);
        curl_exec($ch); curl_close($ch);
    }
}

// Remove tracks from Spotify playlist
function spotRemoveTracks($tok,$plId,$uris) {
    foreach(array_chunk($uris,100) as $chunk){
        $tracks=array_map(function($u){return['uri'=>$u];},$chunk);
        $ch=curl_init("https://api.spotify.com/v1/playlists/$plId/tracks");
        curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>["Authorization: Bearer $tok","Content-Type: application/json"],CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>'DELETE',CURLOPT_POSTFIELDS=>json_encode(['tracks'=>$tracks]),CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>false,CURLOPT_TIMEOUT=>20]);
        curl_exec($ch); curl_close($ch);
    }
}

// Search Spotify for a track URI by title+artist
function spotSearchTrack($tok,$title,$artist) {
    $q=urlencode("track:$title artist:$artist");
    $ch=curl_init("https://api.spotify.com/v1/search?q=$q&type=track&limit=1");
    curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>["Authorization: Bearer $tok"],CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>false,CURLOPT_TIMEOUT=>10]);
    $d=json_decode(curl_exec($ch),true); curl_close($ch);
    return $d['tracks']['items'][0]['uri'] ?? null;
}

// Get composers (writers) from Spotify track features — uses audio-features endpoint for track_id
// Note: Spotify API does not expose composers directly via public API.
// We use the track's artists as a fallback and fetch album info to get any credited composer info.
// For true composer metadata we search the track and read the artists array + album.
function spotTrackComposers($tok,$trackUri) {
    // Extract track ID from URI spotify:track:XXXX or URL
    if(preg_match('/track[\/:]([A-Za-z0-9]{10,})/',$trackUri,$m)) $trackId=$m[1];
    else return null;
    $ch=curl_init("https://api.spotify.com/v1/tracks/$trackId");
    curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>["Authorization: Bearer $tok"],CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>false,CURLOPT_TIMEOUT=>10]);
    $d=json_decode(curl_exec($ch),true); curl_close($ch);
    if(empty($d['artists'])) return null;
    // Spotify public API does not expose songwriters separately from performers.
    // We return all credited artists (which often includes composers for solo acts).
    return array_map(function($a){return $a['name'];},$d['artists']);
}

// Fetch composers for a song via Spotify search
function spotFetchComposer($tok,$title,$artist) {
    $uri = spotSearchTrack($tok,$title,$artist);
    if(!$uri) return null;
    $composers = spotTrackComposers($tok,$uri);
    if(!$composers) return null;
    return implode(', ',$composers);
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

// ── Spotify OAuth callback ────────────────────────────────────────
if (isset($_GET['spot_oauth_cb'])) {
    if (!empty($_GET['code']) && ($_GET['state']??'') === ($_SESSION['spot_oauth_state']??'__')) {
        spotExchangeCode($_GET['code']);
    }
    $redirect = strtok($_SERVER['REQUEST_URI'],'?');
    $pl = $_GET['pl'] ?? '';
    header('Location: '.$redirect.($pl?'?pl='.urlencode($pl):''));
    exit;
}
if (isset($_GET['spot_logout'])) {
    unset($_SESSION['spot_user_token'],$_SESSION['spot_user_refresh'],$_SESSION['spot_user_expiry']);
    header('Location: '.strtok($_SERVER['REQUEST_URI'],'?').'?pl='.urlencode($_GET['pl']??''));
    exit;
}


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

// Cifra search — build slug URL and verify it exists
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

    // ── Add playlist (with or without Spotify) ──
    if($act==='add_pl'){
        needAuth();
        $spotId=trim($_POST['spotify_id']??'');
        $manualName=trim($_POST['name']??'');

        if($spotId){
            // With Spotify ID
            $tok=spotToken();
            if(!$tok) jsonOut(['ok'=>false,'error'=>'Credenciais Spotify não configuradas no .env']);
            $info=spotPlInfo($tok,$spotId);
            if(!$info) jsonOut(['ok'=>false,'error'=>'Playlist não encontrada ou privada']);
            $name=$manualName?:$info['name'];
            $pls=loadPlaylists();
            $slug=trim(strtolower(preg_replace('/[^a-z0-9]+/i','-',$name)),'-')?:'playlist';
            $exist=array_column($pls,'id');$base=$slug;$n=2;
            while(in_array($slug,$exist)) $slug=$base.'-'.$n++;
            $newPl=['id'=>$slug,'name'=>$name,'spotify_id'=>$spotId,
                    'spotify_url'=>$info['url'],'is_default'=>count($pls)===0];
            $pls[]=$newPl; savePlaylists($pls);
            $tracks=spotTracks($tok,$spotId);
            if($tracks) saveSongs($newPl,$tracks);
            jsonOut(['ok'=>true,'id'=>$slug,'name'=>$name,'spotify_url'=>$info['url'],
                     'track_count'=>count($tracks)]);
        } else {
            // Without Spotify — name is required
            if(!$manualName) jsonOut(['ok'=>false,'error'=>'O nome é obrigatório.']);
            $pls=loadPlaylists();
            $slug=trim(strtolower(preg_replace('/[^a-z0-9]+/i','-',$manualName)),'-')?:'playlist';
            $exist=array_column($pls,'id');$base=$slug;$n=2;
            while(in_array($slug,$exist)) $slug=$base.'-'.$n++;
            $newPl=['id'=>$slug,'name'=>$manualName,'spotify_id'=>'','is_default'=>count($pls)===0];
            $pls[]=$newPl; savePlaylists($pls);
            saveSongs($newPl,[]);
            jsonOut(['ok'=>true,'id'=>$slug,'name'=>$manualName,'spotify_url'=>'','track_count'=>0]);
        }
    }

    // ── Edit playlist ──
    if($act==='edit_pl'){
        needAuth();
        $pls=loadPlaylists(); $tid=$_POST['target_id']??'';
        $newSpotId=trim($_POST['spotify_id']??'');
        $newName=trim($_POST['name']??'');
        foreach($pls as &$p){
            if($p['id']!==$tid) continue;
            if($newName) $p['name']=$newName;
            if($newSpotId&&$newSpotId!==$p['spotify_id']){
                $tok=spotToken();
                if($tok){$info=spotPlInfo($tok,$newSpotId);
                    if($info){
                        // Only overwrite name from Spotify if user didn't set one manually
                        if(!$newName) $p['name']=$info['name'];
                        $p['spotify_url']=$info['url'];
                    }
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
    // ── Merge playlists ──
    if($act==='merge_playlists'){
        needAuth();
        $sourceIds = json_decode($_POST['source_ids']??'[]',true);
        $newName   = trim($_POST['name']??'');
        if(!$newName||empty($sourceIds)) jsonOut(['ok'=>false,'error'=>'Nome e listas de origem são obrigatórios.']);
        $pls = loadPlaylists();
        $slug = trim(strtolower(preg_replace('/[^a-z0-9]+/i','-',$newName)),'-')?:'merged';
        $exist = array_column($pls,'id'); $base=$slug; $n=2;
        while(in_array($slug,$exist)) $slug=$base.'-'.$n++;
        $newPl = ['id'=>$slug,'name'=>$newName,'spotify_id'=>'','is_default'=>false];
        $merged = []; $seen = [];
        foreach($sourceIds as $sid){
            $srcPl=null; foreach($pls as $p) if($p['id']===$sid){$srcPl=$p;break;}
            if(!$srcPl) continue;
            foreach(loadSongs($srcPl) as $s){
                $key = strtolower($s['title'].'|'.$s['artist']);
                if(!isset($seen[$key])){ $seen[$key]=true; $merged[]=$s; }
            }
        }
        $pls[]=$newPl; savePlaylists($pls);
        saveSongs($newPl,$merged);
        jsonOut(['ok'=>true,'id'=>$slug,'name'=>$newName,'count'=>count($merged)]);
    }

    // ── Fetch composer from Spotify ──
    if($act==='fetch_composer'){
        needAuth();
        $tok = spotToken();
        if(!$tok) jsonOut(['ok'=>false,'error'=>'Credenciais Spotify não configuradas']);
        $title  = trim($_POST['title']??'');
        $artist = trim($_POST['artist']??'');
        if(!$title||!$artist) jsonOut(['ok'=>false,'error'=>'Título e artista são obrigatórios']);
        $composer = spotFetchComposer($tok,$title,$artist);
        if(!$composer) jsonOut(['ok'=>false,'error'=>'Compositor não encontrado no Spotify para esta faixa.']);
        jsonOut(['ok'=>true,'composer'=>$composer]);
    }

    // ── Fetch composers for all songs in playlist ──
    if($act==='fetch_all_composers'){
        needAuth();
        $tok = spotToken();
        if(!$tok) jsonOut(['ok'=>false,'error'=>'Credenciais Spotify não configuradas']);
        $pl = getActivePl(); $songs = loadSongs($pl);
        $updated = 0;
        foreach($songs as &$s){
            if(!empty($s['composer'])) continue; // skip already fetched
            $c = spotFetchComposer($tok,$s['title'],$s['artist']);
            if($c){ $s['composer']=$c; $updated++; }
        } unset($s);
        saveSongs($pl,$songs);
        jsonOut(['ok'=>true,'updated'=>$updated,'songs'=>$songs]);
    }

    // ── Spotify sync diff ──
    if($act==='spot_diff'){
        needAuth();
        $pl = getActivePl();
        if(empty($pl['spotify_id'])) jsonOut(['ok'=>false,'error'=>'Esta lista não tem playlist Spotify associada.']);
        $tok = spotUserToken();
        if(!$tok) jsonOut(['ok'=>false,'error'=>'auth_required']);
        $spotTracks = spotPlaylistTrackUris($tok,$pl['spotify_id']);
        $localSongs = loadSongs($pl);
        // Build local lookup by title+artist slug
        $localKeys = [];
        foreach($localSongs as $s) $localKeys[strtolower($s['title'].'|'.$s['artist'])] = $s;
        // Find what's on Spotify but not local (only_spotify)
        $onlySpotify = [];
        foreach($spotTracks as $uri=>$info){
            $key = strtolower($info['name'].'|'.$info['artist']);
            if(!isset($localKeys[$key])) $onlySpotify[]=array_merge($info,['uri'=>$uri]);
        }
        // Find what's local but not on Spotify (only_local) — need to search URIs
        $onlyLocal = [];
        $spotKeyMap = [];
        foreach($spotTracks as $uri=>$info) $spotKeyMap[strtolower($info['name'].'|'.$info['artist'])]=$uri;
        foreach($localSongs as $s){
            $key = strtolower($s['title'].'|'.$s['artist']);
            if(!isset($spotKeyMap[$key])) $onlyLocal[]=$s;
        }
        jsonOut(['ok'=>true,'only_spotify'=>$onlySpotify,'only_local'=>$onlyLocal,'spotify_total'=>count($spotTracks),'local_total'=>count($localSongs)]);
    }

    // ── Spotify sync apply ──
    if($act==='spot_sync_apply'){
        needAuth();
        $pl = getActivePl();
        if(empty($pl['spotify_id'])) jsonOut(['ok'=>false,'error'=>'Esta lista não tem playlist Spotify associada.']);
        $tok = spotUserToken();
        if(!$tok) jsonOut(['ok'=>false,'error'=>'auth_required']);
        $addLocal   = json_decode($_POST['add_local']??'[]',true);   // URIs to add to local
        $removeLocal= json_decode($_POST['remove_local']??'[]',true); // URIs to remove from local
        $addSpot    = json_decode($_POST['add_spotify']??'[]',true);  // title|artist pairs to add to Spotify
        $removeSpot = json_decode($_POST['remove_spotify']??'[]',true); // URIs to remove from Spotify
        $songs = loadSongs($pl);
        // Apply to local
        if($addLocal){
            $appTok = spotToken();
            foreach($addLocal as $uri){
                if(preg_match('/track[\/:]([A-Za-z0-9]{10,})/',$uri,$m)){
                    $ch=curl_init("https://api.spotify.com/v1/tracks/{$m[1]}");
                    curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>["Authorization: Bearer $appTok"],CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>false,CURLOPT_TIMEOUT=>10]);
                    $d=json_decode(curl_exec($ch),true); curl_close($ch);
                    if(!empty($d['name'])) $songs[]=['title'=>cleanTitle($d['name']),'artist'=>$d['artists'][0]['name']??'','cifra_url'=>'N/A','cifra_source'=>'cifraclub','duration_ms'=>(int)($d['duration_ms']??0),'spotify_url'=>$d['external_urls']['spotify']??''];
                }
            }
        }
        if($removeLocal){
            // Remove by spotify_url or by title|artist key
            $songs=array_values(array_filter($songs,function($s) use($removeLocal){
                return !in_array($s['spotify_url']??'',$removeLocal);
            }));
        }
        saveSongs($pl,$songs);
        // Apply to Spotify
        if($addSpot){
            $appTok2 = spotToken();
            $urisToAdd = [];
            foreach($addSpot as $ta){
                [$t,$a] = explode('|',$ta,2);
                $uri = spotSearchTrack($tok,$t,$a);
                if($uri) $urisToAdd[]=$uri;
            }
            if($urisToAdd) spotAddTracks($tok,$pl['spotify_id'],$urisToAdd);
        }
        if($removeSpot) spotRemoveTracks($tok,$pl['spotify_id'],$removeSpot);
        jsonOut(['ok'=>true,'local_count'=>count($songs)]);
    }
}

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
$hasSpotUser = hasSpotUserToken();
$spotOAuthLink = ($hasSpot && !$hasSpotUser) ? spotOAuthUrl() : null;
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
/* ── Mobile action menu (overflow buttons) ── */
.topbar-actions{display:flex;align-items:center;gap:6px;flex-shrink:0}
.tb-more-btn{display:none}
.tb-overflow-menu{
  display:none;position:absolute;top:calc(100% + 6px);right:13px;
  background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r2);
  padding:6px;z-index:300;min-width:170px;
  box-shadow:0 8px 32px rgba(0,0,0,.5);
}
.tb-overflow-menu.open{display:flex;flex-direction:column;gap:3px}
.tb-overflow-menu .btn{justify-content:flex-start;width:100%;font-size:.78rem;padding:7px 10px}

@media(max-width:700px){
  :root{--sw:0px}
  .sidebar{transform:translateX(-230px);width:230px;box-shadow:4px 0 24px rgba(0,0,0,.5)}
  .sidebar.open{transform:translateX(0)}
  .sb-backdrop.open{display:block}
  .hamburger{display:flex}
  .main{margin-left:0}
  .topbar{padding:10px 13px;position:relative}
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
  /* Hide all topbar secondary buttons — show only Add and overflow trigger */
  .topbar #mergePlBtn,
  .topbar #composerBtn,
  .topbar #syncBtn,
  .topbar #printBtn,
  .topbar #copyListBtn{display:none}
  .topbar .btn-primary .btn-lbl{display:none}
  .tb-more-btn{display:inline-flex}
  /* Modal full-screen on mobile */
  .modal-overlay{align-items:flex-end;padding:0}
  .modal{
    border-radius:var(--r2) var(--r2) 0 0;
    max-width:100%!important;
    width:100%;
    max-height:88vh;
    overflow-y:auto;
    padding:20px 16px 28px;
  }
}

@media print{
  .sidebar,.topbar,.search-row,.td-actions,.btn,.hamburger,.sb-backdrop,
  .modal-overlay,.stats-row,.ron,.cp-toast{display:none!important}
  .main{margin-left:0}
  body{background:#fff;color:#000;font-family:'DM Sans',sans-serif}
  .content{padding:0}
  .print-header{display:block!important;margin-bottom:14px}
  .table-wrap{border:1px solid #bbb;border-radius:0}
  table{width:100%;border-collapse:collapse}
  thead th{background:#eee!important;color:#333!important;font-size:.6rem;letter-spacing:.1em;padding:6px 10px;border-bottom:1px solid #bbb}
  tbody td{color:#111!important;border-bottom:1px solid #e0e0e0}
  tbody tr:last-child td{border-bottom:none}
  .td-num{font-family:monospace;color:#555!important}
  .td-cifra,.td-spot{display:none}
  th:nth-child(1),td:nth-child(1){display:none}
  .badge{display:none}

  /* 1 page — compact */
  body.print-1page{font-size:9pt}
  body.print-1page thead th{padding:3px 8px;font-size:7pt}
  body.print-1page tbody td{padding:2px 8px;font-size:8.5pt}
  body.print-1page .print-header h2{font-size:13pt}
  body.print-1page .print-header p{font-size:7pt}
  @page.print-1page{size:A4 portrait;margin:1cm 1.5cm}

  /* 2 pages — normal */
  body.print-2page{font-size:11pt}
  body.print-2page thead th{padding:5px 10px;font-size:8pt}
  body.print-2page tbody td{padding:5px 10px;font-size:10pt}
  body.print-2page .print-header h2{font-size:16pt}
  body.print-2page .print-header p{font-size:8pt}

  @page{margin:1.5cm 1.8cm;size:A4 portrait}
}

/* print header (hidden on screen) */
.print-header{display:none}
.print-header h2{font-family:'Playfair Display',serif;font-size:1.3rem;font-weight:700;margin:0 0 2px 0;color:#111}
.print-header p{font-family:monospace;font-size:.62rem;color:#666;margin:0 0 10px 0;letter-spacing:.06em;text-transform:uppercase}
.print-header hr{border:none;border-top:1.5px solid #222;margin-bottom:12px}

/* print modal */
#printModal .modal{max-width:340px}
.print-opt{display:flex;gap:10px;margin-bottom:16px}
.print-opt-btn{flex:1;padding:14px 10px;border-radius:8px;border:2px solid var(--border2);background:var(--bg3);color:var(--text2);cursor:pointer;font-family:'DM Sans',sans-serif;font-size:.8rem;transition:all .15s;text-align:center}
.print-opt-btn:hover{border-color:var(--accent);color:var(--accent);background:var(--accent-dim)}
.print-opt-btn.selected{border-color:var(--accent);background:var(--accent-dim);color:var(--accent)}
.print-opt-btn .pob-icon{font-size:1.4rem;display:block;margin-bottom:4px}
.print-opt-btn .pob-label{font-weight:600;display:block}
.print-opt-btn .pob-sub{font-size:.68rem;color:var(--text3);display:block;margin-top:2px}
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
            <?php if(!empty($pl['spotify_id'])): ?>
            <span class="pl-act-btn" onclick="openImportModal('<?= htmlspecialchars($pl['id'],ENT_QUOTES) ?>','<?= htmlspecialchars($pl['name'],ENT_QUOTES) ?>')" title="Importar do Spotify">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg>
            </span>
            <a class="pl-act-btn" href="<?= htmlspecialchars($sUrl) ?>" target="_blank" title="Abrir no Spotify">
              <svg viewBox="0 0 24 24" fill="currentColor" style="color:#1db954"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.586 14.424a.623.623 0 0 1-.857.207c-2.348-1.435-5.304-1.76-8.785-.964a.623.623 0 1 1-.277-1.215c3.809-.87 7.076-.496 9.712 1.115a.623.623 0 0 1 .207.857zm1.223-2.722a.78.78 0 0 1-1.072.257c-2.687-1.652-6.785-2.131-9.965-1.166a.78.78 0 1 1-.453-1.492c3.633-1.102 8.147-.568 11.233 1.329a.78.78 0 0 1 .257 1.072zm.105-2.835C14.692 8.95 9.375 8.775 6.297 9.71a.937.937 0 1 1-.543-1.793c3.521-1.068 9.376-.862 13.066 1.346a.937.937 0 0 1-.906 1.604z"/></svg>
            </a>
            <?php endif; ?>
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
      Nova lista
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
    <div class="topbar-actions">
      <span class="saving" id="savingInd">Salvo ✓</span>

      <!-- Desktop: all buttons visible -->
      <button class="btn btn-outline" id="mergePlBtn" title="Merge de listas">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 7H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3"/><polyline points="15 3 12 0 9 3"/><line x1="12" y1="0" x2="12" y2="15"/></svg>
        <span class="btn-lbl">Merge</span>
      </button>
      <?php if($hasSpot): ?>
      <button class="btn btn-outline" id="composerBtn" title="Buscar compositores via Spotify">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/></svg>
        <span class="btn-lbl">Compositores</span>
      </button>
      <?php if(!empty($activePl['spotify_id'])): ?>
      <button class="btn btn-outline" id="syncBtn" title="Sincronizar com Spotify">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><polyline points="23 20 23 14 17 14"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg>
        <span class="btn-lbl">Sync</span>
      </button>
      <?php endif; ?>
      <?php endif; ?>
      <button class="btn btn-outline" id="printBtn" title="Imprimir lista" onclick="openPrintModal()">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        <span class="btn-lbl">Imprimir</span>
      </button>
      <button class="btn btn-outline" id="copyListBtn" title="Copiar lista como texto">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
        <span class="btn-lbl">Copiar</span>
      </button>

      <!-- Mobile: "⋯" overflow trigger -->
      <button class="btn btn-outline tb-more-btn" id="tbMoreBtn" title="Mais opções">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="5" cy="12" r="1"/><circle cx="12" cy="12" r="1"/><circle cx="19" cy="12" r="1"/></svg>
      </button>

      <!-- Mobile overflow dropdown -->
      <div class="tb-overflow-menu" id="tbOverflowMenu">
        <button class="btn btn-outline" id="mergePlBtnM">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 7H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3"/><polyline points="15 3 12 0 9 3"/><line x1="12" y1="0" x2="12" y2="15"/></svg>
          Merge de Listas
        </button>
        <?php if($hasSpot): ?>
        <button class="btn btn-outline" id="composerBtnM">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/></svg>
          Compositores
        </button>
        <?php if(!empty($activePl['spotify_id'])): ?>
        <button class="btn btn-outline" id="syncBtnM">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><polyline points="23 20 23 14 17 14"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg>
          Sync Spotify
        </button>
        <?php endif; ?>
        <?php endif; ?>
        <button class="btn btn-outline" id="printBtnM" onclick="closeOverflow();openPrintModal()">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
          Imprimir
        </button>
        <button class="btn btn-outline" id="copyListBtnM">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
          Copiar Lista
        </button>
      </div>

      <button class="btn btn-primary" id="addSongBtn">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        <span class="btn-lbl">Adicionar</span>
      </button>
    </div>
  </div>

  <div class="content fade-up">
    <!-- Print-only header (hidden on screen) -->
    <div class="print-header" id="printHeader">
      <h2><?= htmlspecialchars($activePl['name']??'SetList') ?></h2>
      <p><?= $totalSongs ?> músicas<?= $durStr?' · '.$durStr:'' ?> · <span id="printDateSpan"></span></p>
      <hr>
    </div>

    <?php if(!$authed&&$locked): ?>
    <div class="ron">
      <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
      Modo leitura — clica em qualquer acção para autenticar.
    </div>
    <?php endif; ?>

    <div class="stats-row">
      <div class="stat-card"><div class="stat-num"><?= str_pad($totalSongs,2,'0',STR_PAD_LEFT) ?></div><div class="stat-label">Músicas</div></div>
      <div class="stat-card"><div class="stat-num"><?= str_pad($artistCount,2,'0',STR_PAD_LEFT) ?></div><div class="stat-label">Artistas</div></div>
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
            <th>Artista / Compositor</th>
            <th class="td-cifra">Cifra</th>
            <th class="td-spot"></th>
            <th class="td-actions">Ações</th>
          </tr>
        </thead>
        <tbody id="songList">
          <?php foreach($songs as $i=>$song):
            $cu  = cifraUrlAuto($song);   // generated or saved URL
            $cl  = cifraLabel($song);
            $cr  = $song['cifra_url']??''; $cs=detectSrc($cr);
            $su  = $song['spotify_url']??'';
            $isAuto = (!$cr || $cr==='N/A') && $cu;  // true = slug-generated, not manually saved
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
            <td class="td-artist">
              <?= htmlspecialchars($song['artist']) ?>
              <?php if(!empty($song['composer'])): ?>
                <div style="font-size:.65rem;color:var(--text3);margin-top:1px">✍ <?= htmlspecialchars($song['composer']) ?></div>
              <?php endif; ?>
            </td>
            <td class="td-cifra">
              <?php if($cu): ?>
                <a href="<?= htmlspecialchars($cu) ?>" target="_blank"
                   class="badge <?= $isAuto?'badge-gray':'badge-green' ?>"
                   style="text-decoration:none"
                   title="<?= $isAuto?'URL gerada automaticamente — pode não existir':'Cifra salva' ?>"
                ><?= $cl ?></a>
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

<!-- Print modal -->
<div class="modal-overlay" id="printModal">
  <div class="modal" style="max-width:340px">
    <div class="modal-title">Imprimir Lista</div>
    <div class="modal-sub">Escolhe quantas páginas queres usar.</div>
    <div class="print-opt">
      <button class="print-opt-btn selected" id="printOpt1" onclick="selectPrintOpt(1)">
        <span class="pob-icon">📄</span>
        <span class="pob-label">1 Página</span>
        <span class="pob-sub">Fonte reduzida,<br>tudo numa folha</span>
      </button>
      <button class="print-opt-btn" id="printOpt2" onclick="selectPrintOpt(2)">
        <span class="pob-icon">📄📄</span>
        <span class="pob-label">2 Páginas</span>
        <span class="pob-sub">Fonte normal,<br>mais espaçado</span>
      </button>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('printModal')">Cancelar</button>
      <button class="btn btn-primary" onclick="doPrint()">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Imprimir
      </button>
    </div>
  </div>
</div>

<!-- Add playlist modal -->
<div class="modal-overlay" id="addPlModal">
  <div class="modal">
    <div class="modal-title">Nova Lista</div>
    <div class="modal-sub">Cria uma lista vazia ou importa do Spotify.</div>
    <div class="fg">
      <label class="fl">Nome da Lista <span style="color:var(--danger)">*</span></label>
      <input class="fi" type="text" id="plName" placeholder="Ex: Rock Clássico" autocomplete="off">
    </div>
    <div class="fg" id="plSpotFg">
      <label class="fl" style="display:flex;align-items:center;justify-content:space-between">
        <span>ID ou URL do Spotify <span style="font-size:.65rem;color:var(--text3)">(opcional)</span></span>
      </label>
      <input class="fi" type="text" id="plSpotRaw" placeholder="4pcomesNQA6… ou https://open.spotify.com/playlist/…" autocomplete="off">
      <div class="ls" id="plLookupStatus"></div>
    </div>
    <div id="plAddError" class="alert alert-err" style="display:none"></div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('addPlModal')">Cancelar</button>
      <button class="btn btn-primary" id="plAddBtn">Criar Lista</button>
    </div>
  </div>
</div>

<!-- Edit playlist modal -->
<div class="modal-overlay" id="editPlModal">
  <div class="modal">
    <div class="modal-title">Editar Lista</div>
    <div class="modal-sub" id="editPlSub"></div>
    <div class="fg">
      <label class="fl">Nome da Lista</label>
      <input class="fi" type="text" id="editPlName" placeholder="Ex: Rock Clássico">
    </div>
    <div class="fg">
      <label class="fl">ID da Playlist Spotify</label>
      <input class="fi" type="text" id="editPlSpotId">
      <div style="font-size:.68rem;color:var(--text3);margin-top:4px">Ao alterar o ID, o nome é actualizado a partir do Spotify (a menos que tenhas definido um nome manualmente).</div>
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

<!-- Merge Playlists modal -->
<div class="modal-overlay" id="mergeModal">
  <div class="modal" style="max-width:500px">
    <div class="modal-title">Merge de Listas</div>
    <div class="modal-sub">Selecciona duas ou mais listas para fundir numa nova setlist.</div>
    <div class="fg">
      <label class="fl">Listas de origem <span style="color:var(--danger)">*</span></label>
      <div id="mergePlList" style="display:flex;flex-direction:column;gap:5px;max-height:180px;overflow-y:auto;border:1px solid var(--border2);border-radius:var(--r);padding:8px">
        <?php foreach($playlists as $p): ?>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.82rem">
          <input type="checkbox" class="merge-pl-check" value="<?= htmlspecialchars($p['id'],ENT_QUOTES) ?>" style="accent-color:var(--accent)">
          <span><?= htmlspecialchars($p['name']) ?></span>
          <span style="color:var(--text3);font-size:.68rem;font-family:'DM Mono',monospace"><?= count(loadSongs($p)) ?> músicas</span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="fg">
      <label class="fl">Nome da nova lista <span style="color:var(--danger)">*</span></label>
      <input class="fi" type="text" id="mergeNewName" placeholder="Ex: Setlist Verão 2025">
    </div>
    <div id="mergeError" class="alert alert-err" style="display:none"></div>
    <div id="mergeResult" class="alert alert-ok" style="display:none"></div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('mergeModal')">Cancelar</button>
      <button class="btn btn-primary" id="mergeDoBtn">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><path d="M8 7H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3"/><polyline points="15 3 12 0 9 3"/><line x1="12" y1="0" x2="12" y2="15"/></svg>
        Criar Merge
      </button>
    </div>
  </div>
</div>

<!-- Composer modal -->
<div class="modal-overlay" id="composerModal">
  <div class="modal" style="max-width:480px">
    <div class="modal-title">Buscar Compositores</div>
    <div class="modal-sub">Usa os metadados do Spotify para preencher o campo de compositor nas músicas desta lista.</div>
    <?php if(!$hasSpot): ?>
    <div class="alert alert-err">Credenciais Spotify não configuradas no <code style="font-family:'DM Mono',monospace">.env</code>.</div>
    <?php else: ?>
    <div style="font-size:.78rem;color:var(--text2);margin-bottom:12px;line-height:1.5">
      O Spotify indica os artistas creditados em cada faixa. Para artistas/compositores solo isto equivale ao compositor. Músicas que já têm compositor definido serão ignoradas.
    </div>
    <div id="composerProgress" style="display:none">
      <div style="font-family:'DM Mono',monospace;font-size:.7rem;color:var(--text3);margin-bottom:6px" id="composerProgressTxt">A processar…</div>
      <div style="height:4px;background:var(--border2);border-radius:2px;overflow:hidden"><div id="composerProgressBar" style="height:100%;background:var(--accent);width:0%;transition:width .3s"></div></div>
    </div>
    <div id="composerResult" class="alert alert-ok" style="display:none"></div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('composerModal')">Fechar</button>
      <button class="btn btn-primary" id="composerDoBtn">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        Buscar Todos
      </button>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- Sync with Spotify modal -->
<div class="modal-overlay" id="syncModal">
  <div class="modal" style="max-width:560px">
    <div class="modal-title">Sincronizar com Spotify</div>
    <div class="modal-sub" id="syncModalSub">Compara a lista local com a playlist do Spotify e aplica as diferenças.</div>
    <?php if(!$hasSpot): ?>
    <div class="alert alert-err">Credenciais Spotify não configuradas.</div>
    <?php elseif(!$hasSpotUser): ?>
    <div style="text-align:center;padding:16px 0">
      <div style="font-size:.82rem;color:var(--text2);margin-bottom:14px">Para sincronizar é necessário autenticar com a tua conta Spotify (permissão de escrita na playlist).</div>
      <?php if($spotOAuthLink): ?>
      <a href="<?= htmlspecialchars($spotOAuthLink) ?>" class="btn btn-primary" style="display:inline-flex">
        <svg viewBox="0 0 24 24" fill="currentColor" style="width:14px;height:14px"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.586 14.424a.623.623 0 0 1-.857.207c-2.348-1.435-5.304-1.76-8.785-.964a.623.623 0 1 1-.277-1.215c3.809-.87 7.076-.496 9.712 1.115a.623.623 0 0 1 .207.857zm1.223-2.722a.78.78 0 0 1-1.072.257c-2.687-1.652-6.785-2.131-9.965-1.166a.78.78 0 1 1-.453-1.492c3.633-1.102 8.147-.568 11.233 1.329a.78.78 0 0 1 .257 1.072zm.105-2.835C14.692 8.95 9.375 8.775 6.297 9.71a.937.937 0 1 1-.543-1.793c3.521-1.068 9.376-.862 13.066 1.346a.937.937 0 0 1-.906 1.604z"/></svg>
        Ligar conta Spotify
      </a>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div style="font-size:.72rem;color:var(--text3);display:flex;align-items:center;gap:6px;margin-bottom:12px">
      <svg viewBox="0 0 24 24" fill="currentColor" style="width:12px;height:12px;color:var(--accent)"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.586 14.424a.623.623 0 0 1-.857.207c-2.348-1.435-5.304-1.76-8.785-.964a.623.623 0 1 1-.277-1.215c3.809-.87 7.076-.496 9.712 1.115a.623.623 0 0 1 .207.857zm1.223-2.722a.78.78 0 0 1-1.072.257c-2.687-1.652-6.785-2.131-9.965-1.166a.78.78 0 1 1-.453-1.492c3.633-1.102 8.147-.568 11.233 1.329a.78.78 0 0 1 .257 1.072zm.105-2.835C14.692 8.95 9.375 8.775 6.297 9.71a.937.937 0 1 1-.543-1.793c3.521-1.068 9.376-.862 13.066 1.346a.937.937 0 0 1-.906 1.604z"/></svg>
      Conta Spotify ligada &nbsp;·&nbsp; <a href="?pl=<?= urlencode($plId) ?>&spot_logout=1" style="color:var(--text3);text-decoration:none">desligar</a>
    </div>
    <div id="syncLoadingWrap" style="text-align:center;padding:18px 0;display:none">
      <div style="font-family:'DM Mono',monospace;font-size:.72rem;color:var(--text3)">A analisar diferenças…</div>
    </div>
    <div id="syncDiffWrap" style="display:none">
      <div id="syncStats" style="font-family:'DM Mono',monospace;font-size:.68rem;color:var(--text3);margin-bottom:10px"></div>
      <!-- Only in Spotify -->
      <div id="syncOnlySpotWrap">
        <div style="font-size:.76rem;font-weight:600;color:var(--accent);margin-bottom:6px">📥 Apenas no Spotify (não estão na lista local)</div>
        <div id="syncOnlySpotList" style="max-height:140px;overflow-y:auto;font-size:.78rem;display:flex;flex-direction:column;gap:3px"></div>
        <div style="margin-top:8px;display:flex;align-items:center;gap:8px">
          <label style="display:flex;align-items:center;gap:5px;cursor:pointer;font-size:.75rem;color:var(--text2)">
            <input type="checkbox" id="syncAddLocalAll" style="accent-color:var(--accent)"> Adicionar todas à lista local
          </label>
        </div>
      </div>
      <hr style="border:none;border-top:1px solid var(--border);margin:12px 0">
      <!-- Only in local -->
      <div id="syncOnlyLocalWrap">
        <div style="font-size:.76rem;font-weight:600;color:#f0a050;margin-bottom:6px">📤 Apenas na lista local (não estão no Spotify)</div>
        <div id="syncOnlyLocalList" style="max-height:140px;overflow-y:auto;font-size:.78rem;display:flex;flex-direction:column;gap:3px"></div>
        <div style="margin-top:8px;display:flex;align-items:center;gap:8px">
          <label style="display:flex;align-items:center;gap:5px;cursor:pointer;font-size:.75rem;color:var(--text2)">
            <input type="checkbox" id="syncAddSpotAll" style="accent-color:var(--accent)"> Adicionar todas ao Spotify
          </label>
        </div>
      </div>
      <div id="syncNoChanges" style="display:none;text-align:center;padding:16px 0;font-size:.82rem;color:var(--text3)">✓ Lista local e Spotify estão sincronizados!</div>
    </div>
    <div id="syncResult" class="alert alert-ok" style="display:none;margin-top:10px"></div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('syncModal')">Fechar</button>
      <button class="btn btn-outline" id="syncAnalyseBtn">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        Analisar
      </button>
      <button class="btn btn-primary" id="syncApplyBtn" style="display:none">Aplicar Selecção</button>
    </div>
    <?php endif; ?>
  </div>
</div>


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
    $('#smCifraHint').html('');
    setSrc('cifraclub');
    $('#songModalMode').val('add'); $('#songModalIdx').val('');
    $('#smSaveBtn').text('Adicionar');
  } else {
    var s=SONGS[idx];
    $('#songModalTitle').text('Editar Música');
    $('#songModalSub').text('#'+String(idx+1).padStart(2,'0'));
    $('#smTitle').val(s.title); $('#smArtist').val(s.artist);
    var hasManual = s.cifra_url && s.cifra_url!=='N/A' && s.cifra_url!=='';
    setSrc(s.cifra_source||'cifraclub');
    if(hasManual){
      $('#smCifraUrl').val(s.cifra_url);
      $('#smCifraHint').html('Cifra salva manualmente.');
    } else {
      // Pre-fill with generated slug URL
      var urls = buildCifraUrl(s.artist, s.title);
      $('#smCifraUrl').val(urls[0]||'');
      $('#smCifraHint').html(urls[0]
        ? 'URL gerada por slug — <a href="'+urls[0]+'" target="_blank" style="color:var(--accent)">verifica se existe ↗</a>. Edita se necessário.'
        : '');
    }
    $('#songModalMode').val('edit'); $('#songModalIdx').val(idx);
    $('#smSaveBtn').text('Salvar');
  }
  openModal('songModal');
  setTimeout(function(){ $('#smTitle').focus(); }, 80);
}

// ── Cifra auto-search (client-side slug builder) ───────────────
function toSlugJS(s){
  var map = {
    'á':'a','à':'a','ã':'a','â':'a','ä':'a',
    'é':'e','ê':'e','ë':'e','è':'e',
    'í':'i','î':'i','ï':'i','ì':'i',
    'ó':'o','ô':'o','õ':'o','ö':'o','ò':'o',
    'ú':'u','û':'u','ü':'u','ù':'u',
    'ç':'c','ñ':'n','ý':'y'
  };
  s = s.toLowerCase();
  s = s.replace(/[áàãâäéêëèíîïìóôõöòúûüùçñý]/g, function(c){ return map[c]||c; });
  s = s.replace(/[^a-z0-9\s-]/g, '');
  s = s.replace(/[\s_]+/g, '-').trim();
  s = s.replace(/-+/g, '-').replace(/^-|-$/g, '');
  return s;
}

function buildCifraUrl(artist, title){
  var candidates = [];
  var a = artist, t = toSlugJS(title);

  // Primary
  candidates.push(toSlugJS(a));
  // Without "The " prefix
  candidates.push(toSlugJS(a.replace(/^the\s+/i,'')));
  // Without "& Something" / "e Something" suffix
  candidates.push(toSlugJS(a.replace(/\s*[&e]\s+.*/i,'')));
  // Deduplicate
  candidates = candidates.filter(function(v,i,arr){ return v && arr.indexOf(v)===i; });

  return candidates.map(function(aSlug){
    return 'https://www.cifraclub.com.br/'+aSlug+'/'+t+'/';
  });
}

function searchCifraAuto(title, artist){
  var urls = buildCifraUrl(artist, title);
  var primary = urls[0];
  // Show the best-guess URL immediately as a clickable suggestion
  $('#smCifraUrl').val(primary);
  $('#smCifraHint').html(
    'URL gerada automaticamente — <a href="'+primary+'" target="_blank" style="color:var(--accent)">verifica se existe ↗</a>. '
    + (urls.length > 1 ? 'Alternativas: '
        + urls.slice(1).map(function(u){
            return '<a href="'+u+'" target="_blank" style="color:var(--text2);font-size:.65rem">↗</a>';
          }).join(' ')
      : '')
  );
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
  var m=raw.match(/playlist\/([A-Za-z0-9]{10,})/);
  if(m) return m[1];
  if(/^[A-Za-z0-9]{10,40}$/.test(raw)) return raw;
  return null;
}

$('#addPlBtn').on('click',function(){
  guardedAction(function(){
    $('#plName').val(''); $('#plSpotRaw').val('');
    $('#plLookupStatus').text('').attr('class','ls');
    $('#plAddError').hide();
    _lookupOk=false; _lookupId=null;
    openModal('addPlModal');
    setTimeout(function(){ $('#plName').focus(); },80);
  });
});

$('#plSpotRaw').on('input',function(){
  clearTimeout(_lookupTimer); _lookupOk=false; _lookupId=null;
  var raw=$(this).val().trim();
  if(!raw){ $('#plLookupStatus').text('').attr('class','ls'); return; }
  var id=extractSpotId(raw);
  if(!id){ $('#plLookupStatus').text(raw.length>3?'ID não reconhecido.':'').attr('class','ls'); return; }
  if(!HAS_SPOT){
    _lookupId=id; _lookupOk=true;
    $('#plLookupStatus').html('✓ ID: '+id+' (sem credenciais Spotify — nome definido manualmente)').attr('class','ls ok');
    return;
  }
  $('#plLookupStatus').html('<span style="display:inline-block;animation:spin 1s linear infinite">⟳</span> A buscar no Spotify…').attr('class','ls loading');
  _lookupTimer=setTimeout(function(){
    $.get('?pl='+PL_ID,{spot_lookup:id},function(r){
      if(r.ok){
        _lookupId=id; _lookupOk=true;
        // Auto-fill name if empty
        if(!$('#plName').val().trim()) $('#plName').val(r.name);
        $('#plLookupStatus').html('✓ <strong>'+escH(r.name)+'</strong> &nbsp;<a href="'+r.url+'" target="_blank" style="color:var(--accent);font-size:.65rem">abrir ↗</a>').attr('class','ls ok');
      } else {
        $('#plLookupStatus').text('✗ '+(r.error||'Não encontrada')).attr('class','ls err');
      }
    },'json').fail(function(){ $('#plLookupStatus').text('✗ Erro de ligação').attr('class','ls err'); });
  },600);
});
$('#plSpotRaw').on('keydown',function(e){ if(e.key==='Enter') doCreatePl(); });
$('#plName').on('keydown',function(e){ if(e.key==='Enter') doCreatePl(); });

function doCreatePl(){
  var name=$('#plName').val().trim();
  if(!name){ $('#plAddError').text('O nome da lista é obrigatório.').show(); return; }
  var spotId=_lookupId||'';
  // If user typed a Spotify URL/ID but lookup hasn't resolved, try raw
  if(!spotId){
    var raw=$('#plSpotRaw').val().trim();
    if(raw){ spotId=extractSpotId(raw)||''; }
  }
  $('#plAddError').hide();
  var btn=$('#plAddBtn');
  btn.prop('disabled',true).text('A criar…');
  $.post('?pl='+PL_ID,{_action:'add_pl',name:name,spotify_id:spotId,pl:PL_ID},function(r){
    btn.prop('disabled',false).text('Criar Lista');
    if(r.ok){ window.location.href='?pl='+encodeURIComponent(r.id); }
    else { $('#plAddError').text(r.error||'Erro').show(); }
  },'json');
}
$('#plAddBtn').on('click', doCreatePl);

// ── Edit playlist ──────────────────────────────────────────────
function openEditPlModal(id,spotId,name){
  guardedAction(function(){
    _editPlId=id;
    $('#editPlSub').text('A editar: '+name);
    $('#editPlName').val(name);
    $('#editPlSpotId').val(spotId);
    openModal('editPlModal');
    setTimeout(function(){ $('#editPlName').focus(); },80);
  });
}
$('#editPlSaveBtn').on('click',function(){
  var newSpotId=$('#editPlSpotId').val().trim();
  var newName=$('#editPlName').val().trim();
  $(this).prop('disabled',true).text('…');
  $.post('?pl='+PL_ID,{_action:'edit_pl',target_id:_editPlId,spotify_id:newSpotId,name:newName,pl:PL_ID},function(r){
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

// ── Print ──────────────────────────────────────────────────────
var _printPages = 1;

function openPrintModal() {
  _printPages = 1;
  $('#printOpt1').addClass('selected');
  $('#printOpt2').removeClass('selected');
  openModal('printModal');
}
function selectPrintOpt(n) {
  _printPages = n;
  $('#printOpt1').toggleClass('selected', n===1);
  $('#printOpt2').toggleClass('selected', n===2);
}
function doPrint() {
  // Set date in print header
  var date = new Date().toLocaleDateString('pt-PT',{day:'2-digit',month:'long',year:'numeric'});
  $('#printDateSpan').text(date);
  // Apply body class for page sizing
  $('body').removeClass('print-1page print-2page').addClass('print-'+_printPages+'page');
  closeModal('printModal');
  setTimeout(function(){
    window.print();
    // Clean up class after print dialog closes
    setTimeout(function(){ $('body').removeClass('print-1page print-2page'); }, 500);
  }, 200);
}

var HAS_SPOT_USER = <?= $hasSpotUser?'true':'false' ?>;
var ALL_PLS = <?= json_encode(array_map(function($p){ return ['id'=>$p['id'],'name'=>$p['name']]; }, $playlists), JSON_UNESCAPED_UNICODE) ?>;

// ── CSS for sync diff rows ─────────────────────────────────────
(function(){
  var s=document.createElement('style');
  s.textContent=
    '.sync-row{display:flex;align-items:center;gap:7px;padding:4px 7px;border-radius:5px;background:var(--bg3);transition:background .15s}'
   +'.sync-row:hover{background:var(--border)}'
   +'.sync-row label{flex:1;display:flex;align-items:center;gap:7px;cursor:pointer;font-size:.78rem}'
   +'.sync-row .sr-title{font-weight:500;color:var(--text)}'
   +'.sync-row .sr-artist{color:var(--text3);font-size:.68rem}'
   +'.composer-row{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:3px 0;border-bottom:1px solid var(--border);font-size:.76rem}'
   +'.composer-row:last-child{border-bottom:none}'
  ;
  document.head.appendChild(s);
})();

// ── Merge playlists ────────────────────────────────────────────
$('#mergePlBtn').on('click', function(){
  guardedAction(function(){
    $('#mergeNewName').val('');
    $('.merge-pl-check').prop('checked',false);
    $('#mergeError,#mergeResult').hide();
    openModal('mergeModal');
    setTimeout(function(){ $('#mergeNewName').focus(); }, 80);
  });
});

$('#mergeDoBtn').on('click', function(){
  var ids = $('.merge-pl-check:checked').map(function(){ return $(this).val(); }).get();
  var name = $('#mergeNewName').val().trim();
  if(ids.length < 2){ $('#mergeError').text('Selecciona pelo menos 2 listas.').show(); return; }
  if(!name){ $('#mergeError').text('Define um nome para a nova lista.').show(); return; }
  $('#mergeError').hide();
  var btn = $(this);
  btn.prop('disabled',true).text('A criar…');
  $.post('?pl='+PL_ID, {_action:'merge_playlists', source_ids:JSON.stringify(ids), name:name, pl:PL_ID}, function(r){
    btn.prop('disabled',false).html('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><path d="M8 7H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3"/><polyline points="15 3 12 0 9 3"/><line x1="12" y1="0" x2="12" y2="15"/></svg> Criar Merge');
    if(r.ok){
      $('#mergeResult').text('Lista "'+r.name+'" criada com '+r.count+' músicas!').show();
      setTimeout(function(){ window.location.href='?pl='+encodeURIComponent(r.id); }, 1200);
    } else {
      $('#mergeError').text(r.error||'Erro ao criar merge.').show();
    }
  }, 'json').fail(function(){ btn.prop('disabled',false); $('#mergeError').text('Erro de rede.').show(); });
});

// ── Composer fetch ─────────────────────────────────────────────
$('#composerBtn').on('click', function(){
  guardedAction(function(){
    $('#composerProgress').hide();
    $('#composerResult').hide();
    $('#composerDoBtn').prop('disabled',false).html('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg> Buscar Todos');
    openModal('composerModal');
  });
});

$('#composerDoBtn').on('click', function(){
  var btn = $(this);
  btn.prop('disabled',true).text('A processar…');
  $('#composerProgress').show();
  $('#composerProgressBar').css('width','0%');
  $('#composerProgressTxt').text('A processar…');
  $('#composerResult').hide();

  $.post('?pl='+PL_ID, {_action:'fetch_all_composers', pl:PL_ID}, function(r){
    btn.prop('disabled',false).text('Buscar Todos');
    $('#composerProgress').hide();
    if(r.ok){
      // Update SONGS and DOM
      if(r.songs){
        r.songs.forEach(function(s,i){
          if(s.composer){
            SONGS[i] = SONGS[i]||{};
            SONGS[i].composer = s.composer;
            var row = $('#songList tr[data-i="'+i+'"]');
            var artistCell = row.find('.td-artist');
            if(artistCell.length){
              var existing = artistCell.find('.composer-hint');
              if(!existing.length){
                artistCell.append('<div class="composer-hint" style="font-size:.65rem;color:var(--text3);margin-top:1px">✍ '+escH(s.composer)+'</div>');
              } else {
                existing.text('✍ '+s.composer);
              }
            }
          }
        });
      }
      var msg = r.updated > 0
        ? '✓ '+r.updated+' música'+(r.updated===1?'':'s')+' com compositor actualizado.'
        : 'Nenhuma música nova para actualizar (todas já têm compositor ou não foram encontradas).';
      $('#composerResult').text(msg).show();
    } else {
      $('#composerResult').removeClass('alert-ok').addClass('alert-err').text(r.error||'Erro').show();
      setTimeout(function(){ $('#composerResult').removeClass('alert-err').addClass('alert-ok'); }, 4000);
    }
  }, 'json').fail(function(){
    btn.prop('disabled',false);
    $('#composerProgress').hide();
    $('#composerResult').removeClass('alert-ok').addClass('alert-err').text('Erro de rede.').show();
    setTimeout(function(){ $('#composerResult').removeClass('alert-err').addClass('alert-ok'); }, 4000);
  });
});

// ── Sync with Spotify ──────────────────────────────────────────
var _syncDiff = null;

$('#syncBtn').on('click', function(){
  guardedAction(function(){
    _syncDiff = null;
    $('#syncDiffWrap,#syncResult,#syncLoadingWrap').hide();
    $('#syncApplyBtn').hide();
    openModal('syncModal');
  });
});

$('#syncAnalyseBtn').on('click', function(){
  $('#syncLoadingWrap').show();
  $('#syncDiffWrap,#syncResult').hide();
  $('#syncApplyBtn').hide();
  var btn = $(this);
  btn.prop('disabled',true);

  $.post('?pl='+PL_ID, {_action:'spot_diff', pl:PL_ID}, function(r){
    btn.prop('disabled',false);
    $('#syncLoadingWrap').hide();
    if(!r.ok){
      if(r.error==='auth_required'){
        $('#syncResult').removeClass('alert-ok').addClass('alert-err')
          .text('Necessário ligar conta Spotify primeiro. Recarrega a página e clica em "Ligar conta Spotify".').show();
        setTimeout(function(){ $('#syncResult').removeClass('alert-err').addClass('alert-ok'); }, 5000);
      } else {
        $('#syncResult').removeClass('alert-ok').addClass('alert-err').text(r.error||'Erro').show();
        setTimeout(function(){ $('#syncResult').removeClass('alert-err').addClass('alert-ok'); }, 5000);
      }
      return;
    }
    _syncDiff = r;
    renderSyncDiff(r);
  }, 'json').fail(function(){
    btn.prop('disabled',false);
    $('#syncLoadingWrap').hide();
    $('#syncResult').removeClass('alert-ok').addClass('alert-err').text('Erro de rede.').show();
    setTimeout(function(){ $('#syncResult').removeClass('alert-err').addClass('alert-ok'); }, 4000);
  });
});

function renderSyncDiff(r){
  var onlySpot = r.only_spotify||[];
  var onlyLocal = r.only_local||[];

  $('#syncStats').text('Spotify: '+r.spotify_total+' músicas · Local: '+r.local_total+' músicas · Diferenças: '+(onlySpot.length+onlyLocal.length));

  // Only Spotify section
  var spotList = $('#syncOnlySpotList').empty();
  if(onlySpot.length){
    $('#syncOnlySpotWrap').show();
    onlySpot.forEach(function(t, i){
      var id = 'cbs_'+i;
      var row = $('<div class="sync-row">'
        +'<label for="'+id+'">'
        +'<input type="checkbox" id="'+id+'" class="sync-add-local" data-uri="'+escH(t.uri)+'" style="accent-color:var(--accent)" checked>'
        +'<span><span class="sr-title">'+escH(t.name)+'</span> <span class="sr-artist">— '+escH(t.artist)+'</span></span>'
        +'</label>'
        +'</div>');
      spotList.append(row);
    });
  } else {
    $('#syncOnlySpotWrap').hide();
  }

  // Only local section
  var localList = $('#syncOnlyLocalList').empty();
  if(onlyLocal.length){
    $('#syncOnlyLocalWrap').show();
    onlyLocal.forEach(function(s, i){
      var id = 'cbl_'+i;
      var key = s.title+'|'+s.artist;
      var row = $('<div class="sync-row">'
        +'<label for="'+id+'">'
        +'<input type="checkbox" id="'+id+'" class="sync-add-spot" data-key="'+escH(key)+'" style="accent-color:var(--accent)" checked>'
        +'<span><span class="sr-title">'+escH(s.title)+'</span> <span class="sr-artist">— '+escH(s.artist)+'</span></span>'
        +'</label>'
        +'</div>');
      localList.append(row);
    });
  } else {
    $('#syncOnlyLocalWrap').hide();
  }

  if(!onlySpot.length && !onlyLocal.length){
    $('#syncNoChanges').show();
    $('#syncOnlySpotWrap,#syncOnlyLocalWrap').hide();
  } else {
    $('#syncNoChanges').hide();
    $('#syncApplyBtn').show();
  }
  $('#syncDiffWrap').show();
}

// Select-all checkboxes
$('#syncAddLocalAll').on('change', function(){
  $('.sync-add-local').prop('checked', $(this).is(':checked'));
});
$('#syncAddSpotAll').on('change', function(){
  $('.sync-add-spot').prop('checked', $(this).is(':checked'));
});

$('#syncApplyBtn').on('click', function(){
  // Collect selections
  var addLocal = [];
  $('.sync-add-local:checked').each(function(){ addLocal.push($(this).data('uri')); });

  var addSpotify = [];
  $('.sync-add-spot:checked').each(function(){ addSpotify.push($(this).data('key')); });

  if(!addLocal.length && !addSpotify.length){
    toast('Nenhuma opção seleccionada.');
    return;
  }

  // Confirmation
  var msgs = [];
  if(addLocal.length) msgs.push('Adicionar '+addLocal.length+' música(s) à lista local');
  if(addSpotify.length) msgs.push('Adicionar '+addSpotify.length+' música(s) ao Spotify');
  if(!confirm('Confirmas as seguintes alterações?\n\n• '+msgs.join('\n• '))) return;

  var btn = $(this);
  btn.prop('disabled',true).text('A sincronizar…');

  $.post('?pl='+PL_ID, {
    _action:'spot_sync_apply',
    pl:PL_ID,
    add_local:JSON.stringify(addLocal),
    remove_local:JSON.stringify([]),
    add_spotify:JSON.stringify(addSpotify),
    remove_spotify:JSON.stringify([])
  }, function(r){
    btn.prop('disabled',false).text('Aplicar Selecção');
    if(r.ok){
      $('#syncResult').text('✓ Sincronização aplicada com sucesso! Lista local: '+r.local_count+' músicas.').show();
      $('#syncApplyBtn').hide();
      setTimeout(function(){ window.location.reload(); }, 1800);
    } else {
      $('#syncResult').removeClass('alert-ok').addClass('alert-err').text(r.error||'Erro ao sincronizar.').show();
      setTimeout(function(){ $('#syncResult').removeClass('alert-err').addClass('alert-ok'); }, 5000);
    }
  }, 'json').fail(function(){
    btn.prop('disabled',false).text('Aplicar Selecção');
    $('#syncResult').removeClass('alert-ok').addClass('alert-err').text('Erro de rede.').show();
    setTimeout(function(){ $('#syncResult').removeClass('alert-err').addClass('alert-ok'); }, 4000);
  });
});

// ── Mobile overflow menu ───────────────────────────────────────
function closeOverflow(){ $('#tbOverflowMenu').removeClass('open'); }
$('#tbMoreBtn').on('click', function(e){
  e.stopPropagation();
  $('#tbOverflowMenu').toggleClass('open');
});
$(document).on('click', function(e){
  if(!$(e.target).closest('#tbOverflowMenu,#tbMoreBtn').length) closeOverflow();
});
// Mirror mobile buttons to their desktop counterparts
$('#mergePlBtnM').on('click', function(){ closeOverflow(); $('#mergePlBtn').trigger('click'); });
$('#composerBtnM').on('click', function(){ closeOverflow(); $('#composerBtn').trigger('click'); });
$('#syncBtnM').on('click', function(){ closeOverflow(); $('#syncBtn').trigger('click'); });
$('#copyListBtnM').on('click', function(){ closeOverflow(); $('#copyListBtn').trigger('click'); });

// ── Utility ────────────────────────────────────────────────────
function escH(s){ return $('<span>').text(s).html(); }
</script>
</body>
</html>
