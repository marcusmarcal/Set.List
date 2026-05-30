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

// ── JSON response helper ─────────────────────────────────────────
function jsonOut($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Auth guard for POST actions ──────────────────────────────────
function needAuth() {
    if (!isAuthed()) {
        jsonOut(['ok' => false, 'error' => 'not_authed']);
    }
}

// ── Playlists ────────────────────────────────────────────────────
$PLF = __DIR__ . '/playlists.json';

function newPlId() {
    // Numeric timestamp-based ID, guaranteed unique
    $id = (string)time();
    $pls = loadPlaylists();
    $exist = array_column($pls, 'id');
    while(in_array($id, $exist)) $id = (string)((int)$id + 1);
    return $id;
}

function loadPlaylists() {
    global $PLF;
    if (!file_exists($PLF)) {
        $d = [[
            'id'=>'principal','name'=>'Marcvs Marcal',
            'spotify_id'=>'4pcomesNQA6DPXj1HFpOjf','is_default'=>true
        ]];
        file_put_contents($PLF, json_encode($d, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        return $d;
    }
    return json_decode(file_get_contents($PLF), true) ?: [];
}
function savePlaylists($d) {
    global $PLF;
    file_put_contents($PLF, json_encode($d, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}

function getActivePl() {
    $pls = loadPlaylists();
    $id  = $_GET['pl'] ?? $_POST['pl'] ?? null;
    if ($id) foreach ($pls as $p) if ($p['id'] === $id) return $p;
    foreach ($pls as $p) if (!empty($p['is_default'])) return $p;
    return $pls[0] ?? null;
}

function songsFile($pl) {
    // For new numeric IDs: just digits, safe as-is.
    // For legacy slug IDs: keep only safe filesystem chars.
    // Use raw id if it only contains safe chars, otherwise sanitise.
    $id = $pl['id'];
    if (!preg_match('/^[a-z0-9_\-]+$/i', $id)) {
        $id = preg_replace('/[^a-z0-9_\-]/i', '', $id);
    }
    return __DIR__ . "/songs_{$id}.json";
}
function loadSongs($pl) {
    $f = songsFile($pl);
    if (!file_exists($f)) {
        $leg = __DIR__ . '/songs.json';
        if (!empty($pl['is_default']) && file_exists($leg)) {
            $d = json_decode(file_get_contents($leg), true) ?: [];
            file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
            return $d;
        }
        return [];
    }
    $raw = file_get_contents($f);
    $d   = json_decode($raw, true);
    return is_array($d) ? $d : [];
}
function saveSongs($pl, $d) {
    file_put_contents(songsFile($pl), json_encode($d, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
}

function sortSongs($songs, $col, $ord='asc') {
    usort($songs, function($a,$b) use($col,$ord) {
        $cmp = strcmp(strtoupper($a[$col]??''), strtoupper($b[$col]??''));
        return $ord==='desc' ? -$cmp : $cmp;
    });
    return $songs;
}

function cleanTitle($t) {
    // Suffix keywords that indicate a variant (not part of the core title)
    $kw = 'live|ao vivo|remaster(?:ed)?(?:\s+\d{4})?|\d{4}\s+remaster(?:ed)?|\d{4}[\w\s\-]*mix'
        . '|bonus track|explicit|radio edit|single version|album version|deluxe|acoustic'
        . '|demo|instrumental|extended|intro|outro|version|versão|mix|edit|reprise'
        . '|in\s+\w+|at\s+\w+|from\s+\w+'; // e.g. "Live In Berlin", "Live At Wembley"
    // Remove (...) / [...] blocks that contain any keyword
    $t = preg_replace('/\s*[\(\[]\s*(?:'.$kw.')[^\)\]]*[\)\]]/iu', '', $t);
    // Remove everything after " - keyword..." or " – keyword..."
    $t = preg_replace('/\s+[–\-]\s+(?:'.$kw.').*/iu', '', $t);
    // Remove trailing year-remaster pattern
    $t = preg_replace('/\s*-\s*\d{4}\s+remaster(?:ed)?\s*$/iu', '', $t);
    // Collapse multiple artists separated by / or ; keeping only first song title
    // e.g. "Metamorfose Ambulante / Anna Júlia" — if both parts look like song titles, keep first
    // (Only strip if second part is short and looks like a title, not an artist)
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
            $rawName = $t['name'];
            $songs[]=['title'=>$rawName,'title_display'=>cleanTitle($rawName),
                      'artist'=>$t['artists'][0]['name']??'',
                      'cifra_url'=>'N/A','cifra_source'=>'cifraclub',
                      'duration_ms'=>(int)($t['duration_ms']??0),
                      'spotify_url'=>$t['external_urls']['spotify']??'',
                      'spotify_uri'=>$t['uri']??''];
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
    $uris=[]; $url="https://api.spotify.com/v1/playlists/$plId/tracks?limit=100&fields=next,items(track(uri,name,duration_ms,popularity,artists))";
    while($url) {
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>["Authorization: Bearer $tok"],CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>false,CURLOPT_TIMEOUT=>20]);
        $d=json_decode(curl_exec($ch),true); curl_close($ch);
        if(empty($d['items'])) break;
        foreach($d['items'] as $item) {
            $t=$item['track']??null;
            if($t&&!empty($t['uri'])) $uris[$t['uri']]=[
                'name'        => $t['name']??'',
                'artist'      => $t['artists'][0]['name']??'',
                'duration_ms' => (int)($t['duration_ms']??0),
                'popularity'  => (int)($t['popularity']??0),
            ];
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

// Search Spotify for a track URI by title+artist — returns most popular result
function spotSearchTrack($tok,$title,$artist) {
    // First try strict search: track: + artist:
    $cleanT = cleanTitle($title);
    $q = urlencode('track:'.$cleanT.($artist ? ' artist:'.$artist : ''));
    $ch=curl_init("https://api.spotify.com/v1/search?q=$q&type=track&limit=10");
    curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>["Authorization: Bearer $tok"],CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>false,CURLOPT_TIMEOUT=>10]);
    $d=json_decode(curl_exec($ch),true); curl_close($ch);
    $items = $d['tracks']['items'] ?? [];

    // If strict search returned nothing, fallback to free text
    if(empty($items)){
        $q2 = urlencode($cleanT.($artist ? ' '.$artist : ''));
        $ch=curl_init("https://api.spotify.com/v1/search?q=$q2&type=track&limit=10");
        curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>["Authorization: Bearer $tok"],CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>false,CURLOPT_TIMEOUT=>10]);
        $d=json_decode(curl_exec($ch),true); curl_close($ch);
        $items = $d['tracks']['items'] ?? [];
    }
    if(empty($items)) return null;

    // Prefer non-live, non-remaster versions; among those, pick highest popularity
    $preferred = array_filter($items, function($t){
        $name = strtolower($t['name']??'');
        return !preg_match('/live|ao vivo|remaster|remix|acoustic|version/i', $name);
    });
    $pool = count($preferred) ? array_values($preferred) : $items;
    usort($pool, function($a,$b){ return ($b['popularity']??0) <=> ($a['popularity']??0); });
    return $pool[0]['uri'] ?? null;
}

// Same as above but also returns full track metadata for enriching local song data
function spotSearchTrackFull($tok,$title,$artist) {
    $cleanT = cleanTitle($title);
    $q = urlencode('track:'.$cleanT.($artist ? ' artist:'.$artist : ''));
    $ch=curl_init("https://api.spotify.com/v1/search?q=$q&type=track&limit=10");
    curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>["Authorization: Bearer $tok"],CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>false,CURLOPT_TIMEOUT=>10]);
    $d=json_decode(curl_exec($ch),true); curl_close($ch);
    $items = $d['tracks']['items'] ?? [];
    if(empty($items)){
        $q2 = urlencode($cleanT.($artist ? ' '.$artist : ''));
        $ch=curl_init("https://api.spotify.com/v1/search?q=$q2&type=track&limit=10");
        curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>["Authorization: Bearer $tok"],CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>false,CURLOPT_TIMEOUT=>10]);
        $d=json_decode(curl_exec($ch),true); curl_close($ch);
        $items = $d['tracks']['items'] ?? [];
    }
    if(empty($items)) return null;
    $preferred = array_filter($items, function($t){
        return !preg_match('/live|ao vivo|remaster|remix|acoustic|version/i', $t['name']??'');
    });
    $pool = count($preferred) ? array_values($preferred) : $items;
    usort($pool, function($a,$b){ return ($b['popularity']??0) <=> ($a['popularity']??0); });
    $t = $pool[0];
    return [
        'uri'         => $t['uri'],
        'spotify_url' => $t['external_urls']['spotify'] ?? '',
        'artist'      => $t['artists'][0]['name'] ?? '',
        'duration_ms' => (int)($t['duration_ms'] ?? 0),
        'popularity'  => $t['popularity'] ?? 0,
    ];
}


// ── Utility: format milliseconds to m:ss ──────────────────────
function fmtMs($ms){
    if(!$ms) return '';
    // If value seems to be in seconds rather than ms (< 10000 = < 10 seconds as ms, unrealistic for a song)
    // A typical song is 180000-300000 ms. If the sum of 50 songs is ~700000 ms that's ~11666s = wrong data.
    // We trust the value as-is: divide by 1000 to get seconds.
    $totalSec = (int)round($ms/1000);
    $h = intdiv($totalSec, 3600);
    $m = intdiv($totalSec % 3600, 60);
    $s = $totalSec % 60;
    if($h > 0) return $h.'h '.str_pad($m,2,'0',STR_PAD_LEFT).'m';
    return $m.':'.str_pad($s,2,'0',STR_PAD_LEFT);
}

// ── Utility: normalised key for track matching ─────────────────
function trackMatchKey($title, $artist) {
    // Clean variant suffixes from title
    $title = cleanTitle($title ?? '');
    // Normalise: lowercase, remove accents, strip non-alphanumeric
    $norm = function($s) {
        $s = mb_strtolower($s, 'UTF-8');
        $map = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
                'é'=>'e','ê'=>'e','ë'=>'e','è'=>'e',
                'í'=>'i','î'=>'i','ï'=>'i','ì'=>'i',
                'ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ò'=>'o',
                'ú'=>'u','û'=>'u','ü'=>'u','ù'=>'u',
                'ç'=>'c','ñ'=>'n','ý'=>'y'];
        $s = strtr($s, $map);
        return preg_replace('/[^a-z0-9]/i', '', $s);
    };
    $tNorm = $norm($title);
    // Use only the first "word token" of the artist to survive
    // "Leviano" vs "Leviano, saboya, Qualywav1, OG Bahia"
    $artistFirst = preg_split('/[\s,;&\/]+/', trim($artist ?? ''), 2)[0];
    $aNorm = $norm($artistFirst);
    return $tNorm . '||' . $aNorm;
}

// Secondary key: title only (for cases where artist names diverge completely)
function trackMatchKeyTitleOnly($title) {
    $title = cleanTitle($title ?? '');
    $s = mb_strtolower($title, 'UTF-8');
    $map = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
            'é'=>'e','ê'=>'e','ë'=>'e','è'=>'e',
            'í'=>'i','î'=>'i','ï'=>'i','ì'=>'i',
            'ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ò'=>'o',
            'ú'=>'u','û'=>'u','ü'=>'u','ù'=>'u',
            'ç'=>'c','ñ'=>'n','ý'=>'y'];
    $s = strtr($s, $map);
    return preg_replace('/[^a-z0-9]/i', '', $s);
}

// ── Debug endpoint (remove after fixing) ──
if(isset($_GET['_debug'])){
    $pls = loadPlaylists();
    $info = [];
    foreach($pls as $p){
        $f = songsFile($p);
        $raw = file_exists($f) ? file_get_contents($f) : '';
        $decoded = json_decode($raw, true);
        $info[] = [
            'id'          => $p['id'],
            'name'        => $p['name'],
            'file'        => basename($f),
            'exists'      => file_exists($f),
            'size'        => strlen($raw),
            'json_error'  => $decoded === null ? json_last_error_msg() : 'ok',
            'count'       => is_array($decoded) ? count($decoded) : 0,
            'sample'      => is_array($decoded) && count($decoded) ? $decoded[0] : null,
        ];
    }
    jsonOut([
        'dir'          => __DIR__,
        'dir_writable' => is_writable(__DIR__),
        'php_version'  => PHP_VERSION,
        'playlists'    => $info,
    ]);
}


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

    // ── Copy song to another playlist ──
    if($act==='copy_song'){
        needAuth();
        $srcPl  = getActivePl();
        $songs  = loadSongs($srcPl);
        $i      = (int)($_POST['index']??-1);
        $destId = trim($_POST['dest_pl']??'');
        if($i<0||$i>=count($songs)||!$destId) jsonOut(['ok'=>false,'error'=>'Parâmetros inválidos.']);
        $pls = loadPlaylists();
        $destPl = null;
        foreach($pls as $p) if($p['id']===$destId){ $destPl=$p; break; }
        if(!$destPl) jsonOut(['ok'=>false,'error'=>'Lista destino não encontrada.']);
        $song = $songs[$i];
        $destSongs = loadSongs($destPl);
        // Check duplicate
        foreach($destSongs as $s){
            if(strtolower($s['title']??'')==strtolower($song['title']??'') &&
               strtolower($s['artist']??'')==strtolower($song['artist']??'')){
                jsonOut(['ok'=>false,'error'=>'A música já existe na lista destino.']);
            }
        }
        $destSongs[] = $song;
        saveSongs($destPl, $destSongs);
        jsonOut(['ok'=>true,'dest_name'=>$destPl['name'],'total'=>count($destSongs)]);
    }

    // ── Move song to another playlist ──
    if($act==='move_song'){
        needAuth();
        $srcPl  = getActivePl();
        $songs  = loadSongs($srcPl);
        $i      = (int)($_POST['index']??-1);
        $destId = trim($_POST['dest_pl']??'');
        if($i<0||$i>=count($songs)||!$destId) jsonOut(['ok'=>false,'error'=>'Parâmetros inválidos.']);
        $pls = loadPlaylists();
        $destPl = null;
        foreach($pls as $p) if($p['id']===$destId){ $destPl=$p; break; }
        if(!$destPl) jsonOut(['ok'=>false,'error'=>'Lista destino não encontrada.']);
        $song = $songs[$i];
        $destSongs = loadSongs($destPl);
        // Check duplicate
        $alreadyExists = false;
        foreach($destSongs as $s){
            if(strtolower($s['title']??'')==strtolower($song['title']??'') &&
               strtolower($s['artist']??'')==strtolower($song['artist']??'')){
                $alreadyExists = true; break;
            }
        }
        if(!$alreadyExists) $destSongs[] = $song;
        // Remove from source
        array_splice($songs,$i,1);
        saveSongs($srcPl,$songs);
        saveSongs($destPl,$destSongs);
        jsonOut(['ok'=>true,'dest_name'=>$destPl['name'],'src_total'=>count($songs),'already_existed'=>$alreadyExists]);
    }

    // ── Bulk copy songs to another playlist ──
    if($act==='copy_songs_bulk'){
        needAuth();
        $srcPl  = getActivePl();
        $songs  = loadSongs($srcPl);
        $destId = trim($_POST['dest_pl']??'');
        $indices = json_decode($_POST['indices']??'[]',true);
        if(!is_array($indices)||!$destId) jsonOut(['ok'=>false,'error'=>'Parâmetros inválidos.']);
        $pls=loadPlaylists(); $destPl=null;
        foreach($pls as $p) if($p['id']===$destId){ $destPl=$p; break; }
        if(!$destPl) jsonOut(['ok'=>false,'error'=>'Lista destino não encontrada.']);
        $destSongs=loadSongs($destPl);
        $added=0; $skipped=0;
        foreach($indices as $i){
            if(!isset($songs[(int)$i])) continue;
            $s=$songs[(int)$i];
            $dup=false;
            foreach($destSongs as $d)
                if(strtolower($d['title']??'')==strtolower($s['title']??'')&&strtolower($d['artist']??'')==strtolower($s['artist']??'')){$dup=true;break;}
            if($dup){$skipped++;continue;}
            $destSongs[]=$s; $added++;
        }
        saveSongs($destPl,$destSongs);
        jsonOut(['ok'=>true,'added'=>$added,'skipped'=>$skipped,'dest_name'=>$destPl['name']]);
    }

    // ── Bulk move songs to another playlist ──
    if($act==='move_songs_bulk'){
        needAuth();
        $srcPl  = getActivePl();
        $songs  = loadSongs($srcPl);
        $destId = trim($_POST['dest_pl']??'');
        $indices = json_decode($_POST['indices']??'[]',true);
        if(!is_array($indices)||!$destId) jsonOut(['ok'=>false,'error'=>'Parâmetros inválidos.']);
        $pls=loadPlaylists(); $destPl=null;
        foreach($pls as $p) if($p['id']===$destId){ $destPl=$p; break; }
        if(!$destPl) jsonOut(['ok'=>false,'error'=>'Lista destino não encontrada.']);
        $destSongs=loadSongs($destPl);
        $added=0; $skipped=0;
        $toRemove=[];
        foreach($indices as $i){
            $i=(int)$i;
            if(!isset($songs[$i])) continue;
            $s=$songs[$i];
            $dup=false;
            foreach($destSongs as $d)
                if(strtolower($d['title']??'')==strtolower($s['title']??'')&&strtolower($d['artist']??'')==strtolower($s['artist']??'')){$dup=true;break;}
            if(!$dup){$destSongs[]=$s;$added++;}else{$skipped++;}
            $toRemove[]=$i;
        }
        // Remove from source (reverse order to keep indices valid)
        rsort($toRemove);
        foreach($toRemove as $i) array_splice($songs,$i,1);
        saveSongs($srcPl,$songs);
        saveSongs($destPl,$destSongs);
        jsonOut(['ok'=>true,'added'=>$added,'skipped'=>$skipped,'dest_name'=>$destPl['name'],'removed'=>count($toRemove)]);
    }

    // ── Bulk delete songs ──
    if($act==='delete_songs_bulk'){
        needAuth();
        $pl = getActivePl();
        $songs = loadSongs($pl);
        $indices = json_decode($_POST['indices']??'[]',true);
        if(!is_array($indices)) jsonOut(['ok'=>false,'error'=>'Parâmetros inválidos.']);
        $toRemove = array_map('intval',$indices);
        rsort($toRemove);
        $removed = 0;
        foreach($toRemove as $i){
            if(isset($songs[$i])){ array_splice($songs,$i,1); $removed++; }
        }
        saveSongs($pl,$songs);
        jsonOut(['ok'=>true,'removed'=>$removed,'total'=>count($songs)]);
    }

    // ── Duplicate playlist ──
    if($act==='duplicate_pl'){
        needAuth();
        $pls=loadPlaylists();
        $srcId=trim($_POST['src_pl']??'');
        $newName=trim($_POST['name']??'');
        $srcPl=null;
        foreach($pls as $p) if($p['id']===$srcId){ $srcPl=$p; break; }
        if(!$srcPl) jsonOut(['ok'=>false,'error'=>'Lista não encontrada.']);
        if(!$newName) $newName=$srcPl['name'].' (cópia)';
        $newId=newPlId();
        $newPl=['id'=>$newId,'name'=>$newName,'spotify_id'=>'','is_default'=>false];
        $pls[]=$newPl;
        savePlaylists($pls);
        $songs=loadSongs($srcPl);
        saveSongs($newPl,$songs);
        jsonOut(['ok'=>true,'id'=>$newId,'name'=>$newName,'track_count'=>count($songs)]);
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
        $newTags=trim($_POST['tags']??'');
        $isEvent=($_POST['is_event']??'0')==='1';
        $eventName=trim($_POST['event_name']??'');
        $eventLocal=trim($_POST['event_local']??'');
        $eventDate=trim($_POST['event_date']??'');
        $tagsArr=$newTags!=='' ? array_values(array_filter(array_map('trim', explode(',', $newTags)))) : [];
        $extraMeta=['tags'=>$tagsArr,'is_event'=>$isEvent];
        if($isEvent){ $extraMeta['event_name']=$eventName; $extraMeta['event_local']=$eventLocal; $extraMeta['event_date']=$eventDate; }

        if($spotId){
            // With Spotify ID
            $tok=spotToken();
            if(!$tok) jsonOut(['ok'=>false,'error'=>'Credenciais Spotify não configuradas no .env']);
            $info=spotPlInfo($tok,$spotId);
            if(!$info) jsonOut(['ok'=>false,'error'=>'Playlist não encontrada ou privada']);
            $name=$manualName?:$info['name'];
            $pls=loadPlaylists();
            $slug=newPlId();
            $newPl=array_merge(['id'=>$slug,'name'=>$name,'spotify_id'=>$spotId,
                    'spotify_url'=>$info['url'],'is_default'=>count($pls)===0], $extraMeta);
            $pls[]=$newPl; savePlaylists($pls);
            $tracks=spotTracks($tok,$spotId);
            if($tracks) saveSongs($newPl,$tracks);
            jsonOut(['ok'=>true,'id'=>$slug,'name'=>$name,'spotify_url'=>$info['url'],
                     'track_count'=>count($tracks)]);
        } else {
            // Without Spotify — name is required
            if(!$manualName) jsonOut(['ok'=>false,'error'=>'O nome é obrigatório.']);
            $pls=loadPlaylists();
            $slug=newPlId();
            $newPl=array_merge(['id'=>$slug,'name'=>$manualName,'spotify_id'=>'','is_default'=>count($pls)===0], $extraMeta);
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
        $newTags=trim($_POST['tags']??'');
        $isEvent=($_POST['is_event']??'0')==='1';
        $eventName=trim($_POST['event_name']??'');
        $eventLocal=trim($_POST['event_local']??'');
        $eventDate=trim($_POST['event_date']??'');
        foreach($pls as &$p){
            if($p['id']!==$tid) continue;
            if($newName) $p['name']=$newName;
            // Tags: store as array
            if($newTags!==''){
                $tagsArr=array_values(array_filter(array_map('trim', explode(',', $newTags))));
                $p['tags']=$tagsArr;
            } else {
                $p['tags']=[];
            }
            // Event data
            $p['is_event']=$isEvent;
            if($isEvent){
                $p['event_name']=$eventName;
                $p['event_local']=$eventLocal;
                $p['event_date']=$eventDate;
            } else {
                unset($p['event_name'],$p['event_local'],$p['event_date']);
            }
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
            $keys=array_map(function($s){ return trackMatchKey($s['title'],$s['artist']); },$ex);
            foreach($tracks as $t) if(!in_array(trackMatchKey($t['title'],$t['artist']),$keys)) $ex[]=$t;
            saveSongs($targetPl,$ex); $cnt=count($ex);
        } else { saveSongs($targetPl,$tracks); $cnt=count($tracks); }
        jsonOut(['ok'=>true,'count'=>$cnt,'name'=>$targetPl['name'],'pl_id'=>$tid]);
    }
    if($act==='merge_playlists'){
        needAuth();
        $sourceIds = json_decode($_POST['source_ids']??'[]',true);
        $newName   = trim($_POST['name']??'');
        if(!$newName||empty($sourceIds)) jsonOut(['ok'=>false,'error'=>'Nome e listas de origem são obrigatórios.']);
        $pls = loadPlaylists();
        $slug = newPlId();
        $newPl = ['id'=>$slug,'name'=>$newName,'spotify_id'=>'','is_default'=>false];
        $merged = []; $seen = [];
        foreach($sourceIds as $sid){
            $srcPl=null; foreach($pls as $p) if($p['id']===$sid){$srcPl=$p;break;}
            if(!$srcPl) continue;
            foreach(loadSongs($srcPl) as $s){
                $key = trackMatchKey($s['title'],$s['artist']);
                if(!isset($seen[$key])){ $seen[$key]=true; $merged[]=$s; }
            }
        }
        $pls[]=$newPl; savePlaylists($pls);
        saveSongs($newPl,$merged);
        jsonOut(['ok'=>true,'id'=>$slug,'name'=>$newName,'count'=>count($merged)]);
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

        // Build local maps: full key AND title-only key → index
        $localKeyMap      = []; // "title||artistFirst" => idx
        $localTitleMap    = []; // "titleonly" => idx  (fallback)
        foreach($localSongs as $idx=>$s){
            $k  = trackMatchKey($s['title'], $s['artist']);
            $kt = trackMatchKeyTitleOnly($s['title']);
            if(!isset($localKeyMap[$k]))   $localKeyMap[$k]   = $idx;
            if(!isset($localTitleMap[$kt])) $localTitleMap[$kt] = $idx;
        }

        // Build Spotify maps
        $spotKeyMap   = []; // "title||artistFirst" => info+uri
        $spotTitleMap = []; // "titleonly" => info+uri  (fallback)
        foreach($spotTracks as $uri=>$info){
            $k  = trackMatchKey($info['name'], $info['artist']);
            $kt = trackMatchKeyTitleOnly($info['name']);
            if(!isset($spotKeyMap[$k]))   $spotKeyMap[$k]   = array_merge($info,['uri'=>$uri]);
            if(!isset($spotTitleMap[$kt])) $spotTitleMap[$kt] = array_merge($info,['uri'=>$uri]);
        }

        // Helper: find local index for a Spotify track (two-level)
        $findLocal = function($name,$artist) use($localKeyMap,$localTitleMap){
            $k  = trackMatchKey($name,$artist);
            if(isset($localKeyMap[$k])) return $localKeyMap[$k];
            $kt = trackMatchKeyTitleOnly($name);
            return $localTitleMap[$kt] ?? null;
        };
        // Helper: find Spotify info for a local track (two-level)
        $findSpot = function($title,$artist) use($spotKeyMap,$spotTitleMap){
            $k  = trackMatchKey($title,$artist);
            if(isset($spotKeyMap[$k])) return $spotKeyMap[$k];
            $kt = trackMatchKeyTitleOnly($title);
            return $spotTitleMap[$kt] ?? null;
        };

        // Only on Spotify
        $onlySpotify = [];
        foreach($spotTracks as $uri=>$info){
            if($findLocal($info['name'],$info['artist']) === null)
                $onlySpotify[] = array_merge($info,['uri'=>$uri]);
        }
        // Only local
        $onlyLocal = [];
        foreach($localSongs as $idx=>$s){
            if($findSpot($s['title'],$s['artist']) === null)
                $onlyLocal[] = array_merge($s,['_idx'=>$idx]);
        }
        // In both: missing spotify_url, or missing artist, or missing duration_ms
        $missingMeta = [];
        // Also: suggest swapping to a more popular version
        $suggestSwap = [];
        $appTok = spotToken(); // app token for search (no user scope needed)

        foreach($localSongs as $idx=>$s){
            $spotInfo = $findSpot($s['title'],$s['artist']);
            if(!$spotInfo) continue; // only in local, handled above
            $uri = $spotInfo['uri'];
            $trackId = preg_replace('/^spotify:track:/i','',$uri);
            $spotUrl = 'https://open.spotify.com/track/'.$trackId;
            $needsUrl      = empty($s['spotify_url']);
            $needsArtist   = empty($s['artist']);
            $needsDuration = empty($s['duration_ms']);
            if($needsUrl || $needsArtist || $needsDuration){
                $missing = [];
                if($needsUrl)      $missing[] = 'link';
                if($needsArtist)   $missing[] = 'artista';
                if($needsDuration) $missing[] = 'duração';
                $missingMeta[] = [
                    '_idx'        => $idx,
                    'title'       => $s['title'],
                    'artist'      => $s['artist'],
                    'uri'         => $uri,
                    'spotify_url' => $spotUrl,
                    'spot_artist' => $spotInfo['artist'],
                    'spot_duration_ms' => $spotInfo['duration_ms'] ?? 0,
                    'missing'     => $missing,
                ];
            }

            // Suggest swap only when the version in the Spotify playlist has very low popularity
            // (meaning it's likely an obscure cover/remix picked up by accident)
            $curPop = $spotInfo['popularity'] ?? 0;
            if($curPop <= 25){
                // Search by title + local artist hint (if we have one) to find the popular version
                $localArtistHint = $s['artist'] ?? '';
                $popular = spotSearchTrackFull($appTok, cleanTitle($s['title']), $localArtistHint);
                // If not found with artist, try title only
                if(!$popular || $popular['uri'] === $uri){
                    $popular = spotSearchTrackFull($appTok, cleanTitle($s['title']), '');
                }
                if($popular && $popular['uri'] !== $uri){
                    $newPop = $popular['popularity'] ?? 0;
                    // Only suggest if new version is significantly more popular
                    if($newPop - $curPop >= 20){
                        $suggestSwap[] = [
                            '_idx'           => $idx,
                            'title'          => $s['title'],
                            'cur_artist'     => $spotInfo['artist'],
                            'cur_uri'        => $uri,
                            'cur_popularity' => $curPop,
                            'new_artist'     => $popular['artist'],
                            'new_uri'        => $popular['uri'],
                            'new_spotify_url'=> $popular['spotify_url'],
                            'new_popularity' => $newPop,
                            'new_duration_ms'=> $popular['duration_ms'],
                        ];
                    }
                }
            }
        }

        jsonOut(['ok'=>true,
                 'only_spotify' =>$onlySpotify,
                 'only_local'   =>$onlyLocal,
                 'missing_meta' =>$missingMeta,
                 'suggest_swap' =>$suggestSwap,
                 'spotify_total'=>count($spotTracks),
                 'local_total'  =>count($localSongs)]);
    }

    // ── Spotify sync apply ──
    if($act==='spot_sync_apply'){
        needAuth();
        $pl = getActivePl();
        if(empty($pl['spotify_id'])) jsonOut(['ok'=>false,'error'=>'Esta lista não tem playlist Spotify associada.']);
        $tok = spotUserToken();
        if(!$tok) jsonOut(['ok'=>false,'error'=>'auth_required']);
        $addLocal       = json_decode($_POST['add_local']??'[]',true);         // Spotify URIs → add to local
        $removeLocalIdx = json_decode($_POST['remove_local_idx']??'[]',true);  // local indices → remove from local
        $addSpot        = json_decode($_POST['add_spotify']??'[]',true);       // title|artist → add to Spotify
        $removeSpot     = json_decode($_POST['remove_spotify']??'[]',true);    // Spotify URIs → remove from Spotify
        $fillMeta       = json_decode($_POST['fill_spotify_urls']??'[]',true); // [{idx,spotify_url,spotify_uri,spot_artist,spot_duration_ms}]
        $swapVersions   = json_decode($_POST['swap_versions']??'[]',true);     // [{idx,cur_uri,new_uri,new_spotify_url,new_artist,new_duration_ms}]
        $songs = loadSongs($pl);

        // Fill missing spotify_url / artist / duration_ms on existing local songs
        if($fillMeta){
            foreach($fillMeta as $item){
                $idx = (int)($item['idx']??-1);
                if($idx<0 || !isset($songs[$idx])) continue;
                if(!empty($item['spotify_url']))     $songs[$idx]['spotify_url']  = $item['spotify_url'];
                if(!empty($item['spotify_uri']))     $songs[$idx]['spotify_uri']  = $item['spotify_uri'];
                if(!empty($item['spot_artist']) && empty($songs[$idx]['artist']))
                    $songs[$idx]['artist'] = $item['spot_artist'];
                if(!empty($item['spot_duration_ms']) && empty($songs[$idx]['duration_ms']))
                    $songs[$idx]['duration_ms'] = (int)$item['spot_duration_ms'];
            }
        }
        // Add from Spotify to local
        if($addLocal){
            $appTok = spotToken();
            foreach($addLocal as $uri){
                if(preg_match('/track[\/:]([A-Za-z0-9]{10,})/',$uri,$m)){
                    $ch=curl_init("https://api.spotify.com/v1/tracks/{$m[1]}");
                    curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>["Authorization: Bearer $appTok"],CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>false,CURLOPT_TIMEOUT=>10]);
                    $d=json_decode(curl_exec($ch),true); curl_close($ch);
                    if(!empty($d['name'])){
                        $rawName=$d['name'];
                        $songs[]=['title'=>$rawName,'title_display'=>cleanTitle($rawName),
                                  'artist'=>$d['artists'][0]['name']??'','cifra_url'=>'N/A','cifra_source'=>'cifraclub',
                                  'duration_ms'=>(int)($d['duration_ms']??0),
                                  'spotify_url'=>$d['external_urls']['spotify']??'',
                                  'spotify_uri'=>$d['uri']??''];
                    }
                }
            }
        }
        // Remove from local: DISABLED — sync never deletes local songs
        // $removeLocalIdx is intentionally ignored to protect user data
        // Add to Spotify — and back-fill metadata on local songs
        if($addSpot){
            $appTok2 = spotToken();
            $urisToAdd = [];
            $localIndexByKey = [];
            foreach($songs as $idx=>$s) $localIndexByKey[$s['title'].'|'.$s['artist']] = $idx;
            foreach($addSpot as $ta){
                [$t,$a] = explode('|',$ta,2);
                $full = spotSearchTrackFull($appTok2,$t,$a);
                if($full){
                    $urisToAdd[] = $full['uri'];
                    $localIdx = $localIndexByKey[$ta] ?? null;
                    if($localIdx !== null){
                        if(empty($songs[$localIdx]['spotify_url']))  $songs[$localIdx]['spotify_url']  = $full['spotify_url'];
                        if(empty($songs[$localIdx]['spotify_uri']))  $songs[$localIdx]['spotify_uri']  = $full['uri'];
                        if(empty($songs[$localIdx]['artist']))       $songs[$localIdx]['artist']       = $full['artist'];
                        if(empty($songs[$localIdx]['duration_ms']))  $songs[$localIdx]['duration_ms']  = $full['duration_ms'];
                    }
                }
            }
            if($urisToAdd) spotAddTracks($tok,$pl['spotify_id'],$urisToAdd);
        }
        // Remove from Spotify
        if($removeSpot) spotRemoveTracks($tok,$pl['spotify_id'],$removeSpot);
        // Swap versions on Spotify + update local metadata
        if($swapVersions){
            foreach($swapVersions as $sw){
                $idx = (int)($sw['idx']??-1);
                $curUri = $sw['cur_uri']??'';
                $newUri = $sw['new_uri']??'';
                if(!$curUri || !$newUri) continue;
                spotRemoveTracks($tok,$pl['spotify_id'],[$curUri]);
                spotAddTracks($tok,$pl['spotify_id'],[$newUri]);
                if($idx>=0 && isset($songs[$idx])){
                    $songs[$idx]['spotify_url']  = $sw['new_spotify_url'] ?? '';
                    $songs[$idx]['spotify_uri']  = $newUri;
                    if(!empty($sw['new_artist']))      $songs[$idx]['artist']      = $sw['new_artist'];
                    if(!empty($sw['new_duration_ms'])) $songs[$idx]['duration_ms'] = (int)$sw['new_duration_ms'];
                }
            }
        }
        // Auto-fill duration_ms for any local songs still missing it
        $appTok3 = null;
        foreach($songs as $idx=>$s){
            if(!empty($s['duration_ms'])) continue;
            if(!$appTok3) $appTok3 = spotToken();
            if(!$appTok3) break;
            // Use existing spotify_uri if available, otherwise search
            $uri = $s['spotify_uri'] ?? '';
            if($uri && preg_match('/track[\/:]([A-Za-z0-9]{10,})/',$uri,$m)){
                $ch=curl_init("https://api.spotify.com/v1/tracks/{$m[1]}");
                curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>["Authorization: Bearer $appTok3"],CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>false,CURLOPT_TIMEOUT=>8]);
                $d=json_decode(curl_exec($ch),true); curl_close($ch);
                if(!empty($d['duration_ms'])) $songs[$idx]['duration_ms'] = (int)$d['duration_ms'];
            } else {
                $found = spotSearchTrackFull($appTok3, cleanTitle($s['title']), $s['artist']??'');
                if($found && !empty($found['duration_ms'])){
                    $songs[$idx]['duration_ms']  = $found['duration_ms'];
                    if(empty($s['spotify_url']))  $songs[$idx]['spotify_url']  = $found['spotify_url'];
                    if(empty($s['spotify_uri']))  $songs[$idx]['spotify_uri']  = $found['uri'];
                }
            }
        }
        // Single save at the end with all changes
        saveSongs($pl,$songs);
        $totalDur = array_sum(array_column($songs,'duration_ms'));
        jsonOut(['ok'=>true,'local_count'=>count($songs),'total_duration_ms'=>$totalDur]);
    }
    // -- Preview import: search Spotify for each parsed song --
    if($act==='import_preview'){
        $rawText = trim($_POST['text']??'');
        if(!$rawText) jsonOut(['ok'=>false,'error'=>'Texto vazio.']);
        if(function_exists('mb_detect_encoding')){
            $enc=mb_detect_encoding($rawText,['UTF-8','ISO-8859-1','Windows-1252'],true);
            if($enc&&$enc!=='UTF-8') $rawText=mb_convert_encoding($rawText,'UTF-8',$enc);
        }
        $lines=preg_split('/\r?\n/',$rawText); $parsed=[];
        foreach($lines as $line){
            $line=trim($line); if($line==='') continue;
            $line=preg_replace('/^\d+[\.\)]\s*/u','',$line);
            $line=preg_replace('/\s*[\x{2014}\x{2013}]\s*/u','|',$line);
            $line=preg_replace('/\s+--\s+/','|',$line);
            $line=preg_replace('/\s+-\s+/','|',$line);
            if(substr_count($line,'|')>=1){
                $parts=explode('|',$line,2); $t=trim($parts[0]); $a=trim($parts[1]);
                if($t) $parsed[]=['title'=>$t,'artist'=>$a];
            } else { if($line!=='') $parsed[]=['title'=>$line,'artist'=>'']; }
        }
        if(empty($parsed)) jsonOut(['ok'=>false,'error'=>'Nenhuma musica reconhecida.']);
        $tok=spotToken();
        if(!$tok){
            jsonOut(['ok'=>true,'preview'=>array_map(function($s){
                return ['input_title'=>$s['title'],'input_artist'=>$s['artist'],
                        'title'=>$s['title'],'artist'=>$s['artist'],
                        'spotify_url'=>'','duration_ms'=>0,'popularity'=>0,'found'=>false];
            },$parsed),'count'=>count($parsed)]);
        }
        $preview=[];
        foreach($parsed as $s){
            $bestTrack=null;
            foreach(['both','title_only'] as $mode){
                if($bestTrack&&($bestTrack['popularity']??0)>=60) break;
                if($mode==='both'&&!empty($s['artist'])){
                    $q='track:'.urlencode($s['title']).' artist:'.urlencode($s['artist']);
                } else { $q=urlencode($s['title']); }
                $ch=curl_init("https://api.spotify.com/v1/search?q=$q&type=track&limit=5&market=BR");
                curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>["Authorization: Bearer $tok"],CURLOPT_RETURNTRANSFER=>true,CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>false,CURLOPT_TIMEOUT=>8]);
                $d=json_decode(curl_exec($ch),true); curl_close($ch);
                $items=$d['tracks']['items']??[];
                usort($items,function($a,$b){return ($b['popularity']??0)-($a['popularity']??0);});
                if(!empty($items)){
                    $t=$items[0];
                    if(!$bestTrack||($t['popularity']??0)>($bestTrack['popularity']??0)) $bestTrack=$t;
                }
            }
            if($bestTrack){
                $preview[]=['input_title'=>$s['title'],'input_artist'=>$s['artist'],
                    'title'=>$bestTrack['name'],'artist'=>$bestTrack['artists'][0]['name']??'',
                    'spotify_url'=>$bestTrack['external_urls']['spotify']??'',
                    'duration_ms'=>(int)($bestTrack['duration_ms']??0),
                    'popularity'=>(int)($bestTrack['popularity']??0),'found'=>true];
            } else {
                $preview[]=['input_title'=>$s['title'],'input_artist'=>$s['artist'],
                    'title'=>$s['title'],'artist'=>$s['artist'],
                    'spotify_url'=>'','duration_ms'=>0,'popularity'=>0,'found'=>false];
            }
        }
        jsonOut(['ok'=>true,'preview'=>$preview,'count'=>count($preview)]);
    }

    // -- Import text list --
    if($act==='import_text'){
        needAuth();
        $rawText=trim($_POST['text']??'');
        $newName=trim($_POST['name']??'');
        $addToExisting=trim($_POST['target_id']??'');
        if(!$rawText) jsonOut(['ok'=>false,'error'=>'Texto vazio.']);
        if(function_exists('mb_detect_encoding')){
            $enc=mb_detect_encoding($rawText,['UTF-8','ISO-8859-1','Windows-1252'],true);
            if($enc&&$enc!=='UTF-8') $rawText=mb_convert_encoding($rawText,'UTF-8',$enc);
        }
        // Use confirmed songs if provided (from preview), else re-parse
        $confirmedJson=trim($_POST['confirmed']??'');
        if($confirmedJson){
            $parsed=json_decode($confirmedJson,true)??[];
        } else {
            $lines=preg_split('/\r?\n/',$rawText); $parsed=[];
            foreach($lines as $line){
                $line=trim($line); if($line==='') continue;
                $line=preg_replace('/^\d+[\.\)]\s*/u','',$line);
                $line=preg_replace('/\s*[\x{2014}\x{2013}]\s*/u','|',$line);
                $line=preg_replace('/\s+--\s+/','|',$line);
                $line=preg_replace('/\s+-\s+/','|',$line);
                if(substr_count($line,'|')>=1){
                    $parts=explode('|',$line,2); $t=trim($parts[0]); $a=trim($parts[1]);
                    if($t) $parsed[]=['title'=>$t,'artist'=>$a];
                } else { if($line!=='') $parsed[]=['title'=>$line,'artist'=>'']; }
            }
        }
        if(empty($parsed)) jsonOut(['ok'=>false,'error'=>'Nenhuma musica reconhecida.']);
        if($addToExisting){
            $pls=loadPlaylists(); $targetPl=null;
            foreach($pls as $p) if($p['id']===$addToExisting){$targetPl=$p;break;}
            if(!$targetPl) jsonOut(['ok'=>false,'error'=>'Lista nao encontrada.']);
            $existing=loadSongs($targetPl);
            $seen=[]; foreach($existing as $s) $seen[strtolower($s['title'].'|'.$s['artist'])]=true;
            $added=0;
            foreach($parsed as $s){
                $key=strtolower(($s['title']??'').'|'.($s['artist']??''));
                if(!isset($seen[$key])){
                    $existing[]=['title'=>$s['title']??'','artist'=>$s['artist']??'',
                        'cifra_url'=>'N/A','cifra_source'=>'cifraclub',
                        'duration_ms'=>(int)($s['duration_ms']??0),
                        'spotify_url'=>$s['spotify_url']??''];
                    $added++; $seen[$key]=true;
                }
            }
            $f=songsFile($targetPl);
            $wrote=file_put_contents($f,json_encode($existing,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
            jsonOut(['ok'=>$wrote!==false,'id'=>$addToExisting,'name'=>$targetPl['name'],'added'=>$added,'total'=>count($existing)]);
        } else {
            if(!$newName) jsonOut(['ok'=>false,'error'=>'Define um nome para a nova lista.']);
            $pls=loadPlaylists(); $slug=newPlId();
            $songs=array_map(function($s){
                return ['title'=>$s['title']??'','artist'=>$s['artist']??'',
                    'cifra_url'=>'N/A','cifra_source'=>'cifraclub',
                    'duration_ms'=>(int)($s['duration_ms']??0),'spotify_url'=>$s['spotify_url']??''];
            },$parsed);
            $newPl=['id'=>$slug,'name'=>$newName,'spotify_id'=>'','is_default'=>false];
            $pls[]=$newPl; savePlaylists($pls);
            $f=songsFile($newPl);
            $wrote=file_put_contents($f,json_encode($songs,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
            jsonOut(['ok'=>$wrote!==false,'id'=>$slug,'name'=>$newName,'added'=>count($songs),'total'=>count($songs)]);
        }
    }

    // ── Get songs for compare ──
    if($act==='get_songs_for_compare'){
        $pls = loadPlaylists();
        $tid = trim($_POST['pl_id']??'');
        $targetPl = null;
        foreach($pls as $p) if($p['id']===$tid){ $targetPl=$p; break; }
        if(!$targetPl) jsonOut(['ok'=>false,'error'=>'Lista não encontrada.']);
        $songs = loadSongs($targetPl);
        $out = array_map(function($s){
            return ['title'=>$s['title']??'','artist'=>$s['artist']??''];
        }, $songs);
        jsonOut(['ok'=>true,'songs'=>$out]);
    }

    // ── Merge playlists ──

    if($act==='create_spot_playlist'){
        needAuth();
        $pl = getActivePl();
        $tok = spotUserToken();
        if(!$tok) jsonOut(['ok'=>false,'error'=>'auth_required']);
        $userId = spotUserId($tok);
        if(!$userId) jsonOut(['ok'=>false,'error'=>'Não foi possível obter o utilizador Spotify.']);
        $plName = trim($_POST['name']??$pl['name']);
        $desc   = trim($_POST['desc']??'Criada via SetList');
        // Create playlist
        $ch=curl_init("https://api.spotify.com/v1/users/$userId/playlists");
        curl_setopt_array($ch,[CURLOPT_HTTPHEADER=>["Authorization: Bearer $tok","Content-Type: application/json"],CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(['name'=>$plName,'description'=>$desc,'public'=>false]),CURLOPT_SSL_VERIFYPEER=>false,CURLOPT_SSL_VERIFYHOST=>false,CURLOPT_TIMEOUT=>15]);
        $r=json_decode(curl_exec($ch),true); curl_close($ch);
        if(empty($r['id'])) jsonOut(['ok'=>false,'error'=>'Falha ao criar playlist no Spotify: '.($r['error']['message']??'erro desconhecido')]);
        $newSpotId = $r['id'];
        $spotUrl   = $r['external_urls']['spotify']??'';
        // Search & add tracks — and collect URI→song mapping to update local spotify_url
        $appTok = spotToken();
        $songs  = loadSongs($pl);
        $uris=[];
        $songUriMap = []; // index => uri
        foreach($songs as $idx=>$s){
            $uri = spotSearchTrack($appTok,$s['title'],$s['artist']);
            if($uri){ $uris[]=$uri; $songUriMap[$idx]=$uri; }
        }
        if($uris) spotAddTracks($tok,$newSpotId,$uris);
        // Update local songs with spotify_url and spotify_uri
        foreach($songUriMap as $idx=>$uri){
            $trackId = preg_replace('/^spotify:track:/i','',$uri);
            $songs[$idx]['spotify_url']  = 'https://open.spotify.com/track/'.$trackId;
            $songs[$idx]['spotify_uri']  = $uri;
        }
        saveSongs($pl,$songs);
        // Save spotify_id back to local playlist
        $pls=loadPlaylists();
        foreach($pls as &$p){ if($p['id']===$pl['id']){ $p['spotify_id']=$newSpotId; $p['spotify_url']=$spotUrl; break; } } unset($p);
        savePlaylists($pls);
        jsonOut(['ok'=>true,'spotify_id'=>$newSpotId,'spotify_url'=>$spotUrl,'tracks_added'=>count($uris),'tracks_total'=>count($songs)]);
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
<?php
  $metaName  = htmlspecialchars($activePl['name'] ?? 'SetList');
  $metaDesc  = "SetList — gestão de setlists musicais. {$totalSongs} músicas de {$artistCount} artistas" . ($durStr ? ", {$durStr} de duração" : '') . '.';
  $canonical = ((!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http').'://'.$_SERVER['HTTP_HOST'].strtok($_SERVER['REQUEST_URI'],'?');
?>
<meta name="description" content="<?= htmlspecialchars($metaDesc) ?>">
<meta name="robots" content="noindex, nofollow">
<link rel="canonical" href="<?= htmlspecialchars($canonical) ?>">
<meta property="og:type"        content="website">
<meta property="og:title"       content="<?= $metaName ?> · SetList">
<meta property="og:description" content="<?= htmlspecialchars($metaDesc) ?>">
<meta property="og:url"         content="<?= htmlspecialchars($canonical) ?>">
<meta name="twitter:card"        content="summary">
<meta name="twitter:title"       content="<?= $metaName ?> · SetList">
<meta name="twitter:description" content="<?= htmlspecialchars($metaDesc) ?>">
<script type="application/ld+json">
<?php
  $ldTracks = [];
  foreach(array_slice($songs, 0, 50) as $k => $s) {
    $ldTracks[] = ['@type'=>'MusicRecording','name'=>$s['title']??'','byArtist'=>['@type'=>'MusicGroup','name'=>$s['artist']??''],'position'=>$k+1];
  }
  echo json_encode(['@context'=>'https://schema.org','@type'=>'MusicPlaylist','name'=>$activePl['name']??'SetList','numTracks'=>$totalSongs,'track'=>$ldTracks], JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
?>
</script>
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
.pl-drag-handle{cursor:grab;color:var(--text3);padding:0 3px 0 2px;opacity:0;transition:opacity var(--tr);display:flex;align-items:center;flex-shrink:0}
.pl-drag-handle:active{cursor:grabbing}
.pl-item:hover .pl-drag-handle{opacity:1}
#plSortable .ui-sortable-helper{background:var(--bg3)!important;box-shadow:0 6px 24px rgba(0,0,0,.5);border-radius:var(--r);opacity:.97}
#plSortable .ui-sortable-placeholder{visibility:visible!important;background:var(--accent-dim)!important;border:1px dashed var(--accent-glow)!important;border-radius:var(--r);margin:1px 0}
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
.modal{background:var(--bg2);border:1px solid var(--border2);border-radius:var(--r2);width:100%;max-width:460px;transform:translateY(10px);transition:transform var(--tr);display:flex;flex-direction:column;max-height:calc(100vh - 48px);overflow:hidden}
.modal-title{font-family:'Playfair Display',serif;font-size:1.1rem;font-weight:700;margin-bottom:2px;padding:26px 26px 0;flex-shrink:0}
.modal-sub{font-size:.76rem;color:var(--text3);margin-bottom:0;padding:6px 26px 16px;flex-shrink:0}
.modal-body{overflow-y:auto;flex:1;min-height:0;padding:0 26px}
.modal-footer{display:flex;gap:8px;justify-content:flex-end;padding:16px 26px 22px;flex-shrink:0;border-top:1px solid var(--border)}
.modal-overlay.open .modal{transform:translateY(0)}
/* Modals without modal-body: direct children get side padding */
.modal > .fg,.modal > .alert,.modal > .print-opt,.modal > [class*="alert"],.modal > div:not(.modal-title):not(.modal-sub):not(.modal-footer):not(.modal-body):not(.modal-content){padding-left:26px;padding-right:26px}
.modal-content{padding:0 26px}

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
  .topbar #importTextBtn,
  .topbar #mergePlBtn,
  .topbar #syncBtn,
  .topbar #printBtn,
  .topbar #copyListBtn,
  .topbar #compareBtn{display:none}
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
  /* Force white on everything */
  *{background:#fff!important;color:#111!important;box-shadow:none!important;text-shadow:none!important}

  /* Hide chrome */
  .sidebar,.topbar,.search-row,.td-actions,.td-spot,
  .btn,.hamburger,.sb-backdrop,.modal-overlay,.stats-row,.ron,
  .cp-toast{display:none!important}
  th:nth-child(1),td:nth-child(1){display:none!important}
  .badge{display:none!important}
  /* Hide cifra column on print */
  .td-cifra{display:none!important}
  thead th.th-cifra-print{display:none!important}

  @page{margin:1.4cm 1.6cm;size:A4 portrait}
  body{font-family:'DM Sans',sans-serif!important}
  .main{margin-left:0!important}
  .content{padding:0!important}

  /* Print header */
  .print-header{display:block!important;margin-bottom:18px}
  .print-event-info{display:flex!important;gap:16px;flex-wrap:wrap;font-family:'DM Mono',monospace;font-size:.62rem;color:#555!important;margin:5px 0 10px;letter-spacing:.04em}

  /* Stat cards — only 2: Temas + Duração */
  .print-stats{display:flex!important;gap:10px;margin-bottom:18px;max-width:340px}
  .print-stat-card{
    flex:1;border:1.5px solid #ccc!important;border-radius:10px;
    padding:10px 14px 8px;text-align:left
  }
  .print-stat-num{font-family:'DM Mono',monospace;font-size:1.5rem;font-weight:700;color:#111!important;line-height:1;display:block}
  .print-stat-label{font-size:.55rem;letter-spacing:.13em;text-transform:uppercase;color:#888!important;margin-top:3px;display:block}

  /* Table */
  .table-wrap{border:none!important;border-radius:0!important}
  table{width:100%;border-collapse:collapse}
  thead th{
    color:#aaa!important;font-size:.55rem;letter-spacing:.13em;text-transform:uppercase;
    padding:5px 10px;border-bottom:1.5px solid #111!important;font-weight:600;border-top:none!important
  }
  thead th.th-num,tbody td.td-num{
    width:36px;text-align:right;padding-right:14px;
    font-family:'DM Mono',monospace;font-size:.75rem;color:#aaa!important
  }
  tbody td.td-title{font-size:.85rem;font-weight:500;padding:6px 10px}
  tbody td.td-artist{font-size:.78rem;color:#555!important;padding:6px 10px}
  .td-dur-print{display:table-cell!important}
  .th-dur-print{display:table-cell!important}
  tbody td.td-dur-print{font-family:'DM Mono',monospace;font-size:.72rem;color:#999!important;text-align:right;padding-right:4px;white-space:nowrap}
  thead th.th-dur-print{font-size:.55rem;text-align:right;padding-right:4px}
  tbody tr{border-bottom:1px solid #eee!important}
  tbody tr:last-child td{border-bottom:none!important}

  body.print-1page thead th{padding:3px 8px;font-size:.48rem}
  body.print-1page tbody td{font-size:.78rem;padding:3px 8px}
  body.print-1page .print-header h2{font-size:14pt}
  body.print-1page .print-stat-num{font-size:1.15rem}

  body.print-2page thead th{padding:5px 10px;font-size:.55rem}
  body.print-2page tbody td.td-title{font-size:.9rem;padding:7px 10px}
  body.print-2page tbody td.td-artist{font-size:.82rem;padding:7px 10px}
  body.print-2page .print-header h2{font-size:17pt}
}

/* print header (hidden on screen) */
.print-header{display:none}
.print-header h2{font-family:'Playfair Display',serif;font-size:1.4rem;font-weight:700;margin:0 0 3px 0;color:#111;letter-spacing:-.01em}
.print-header p{font-family:'DM Mono',monospace;font-size:.6rem;color:#888;margin:0 0 10px 0;letter-spacing:.07em;text-transform:uppercase}
.print-event-info{display:flex;gap:14px;flex-wrap:wrap;font-family:'DM Mono',monospace;font-size:.65rem;color:#555;margin:4px 0 8px;letter-spacing:.04em}
.print-stats{display:none}

/* pl tags */
.pl-tags-row{display:flex;flex-wrap:wrap;gap:3px;padding:0 8px 4px 26px}
.pl-tag{font-family:'DM Mono',monospace;font-size:.48rem;letter-spacing:.07em;text-transform:uppercase;background:var(--bg3);color:var(--text3);border:1px solid var(--border2);padding:1px 5px;border-radius:3px}
.pl-event-badge{font-family:'DM Mono',monospace;font-size:.48rem;letter-spacing:.08em;text-transform:uppercase;background:rgba(240,160,80,.13);color:#f0a050;border:1px solid rgba(240,160,80,.3);padding:1px 4px;border-radius:3px;flex-shrink:0}

/* print modal */
#printModal .modal{max-width:340px}
.print-opt{display:flex;gap:10px;margin-bottom:16px}
.print-opt-btn{flex:1;padding:14px 10px;border-radius:8px;border:2px solid var(--border2);background:var(--bg3);color:var(--text2);cursor:pointer;font-family:'DM Sans',sans-serif;font-size:.8rem;transition:all .15s;text-align:center}
.print-opt-btn:hover{border-color:var(--accent);color:var(--accent);background:var(--accent-dim)}
.print-opt-btn.selected{border-color:var(--accent);background:var(--accent-dim);color:var(--accent)}
.print-opt-btn .pob-icon{font-size:1.4rem;display:block;margin-bottom:4px}
.print-opt-btn .pob-label{font-weight:600;display:block}
.print-opt-btn .pob-sub{font-size:.68rem;color:var(--text3);display:block;margin-top:2px}

/* context menu removed — replaced by bulk selection bar */
.song-row { user-select: none; }
.song-row.is-selected { background: color-mix(in srgb, var(--accent) 10%, transparent)!important; }
.song-row.is-selected td { background: transparent!important; }
.song-check { opacity: .35; transition: opacity .15s; }
.song-row:hover .song-check, .song-check:checked { opacity: 1; }
#thCheckAll { opacity: .5; }
#thCheckAll:has(#checkAll:checked),
#thCheckAll:has(#checkAll:indeterminate) { opacity: 1; }
#bulkBar {
  display: none;
  position: fixed;
  bottom: 24px;
  left: 50%;
  transform: translateX(-50%);
  background: var(--bg2);
  border: 1px solid var(--border2);
  border-radius: 12px;
  box-shadow: 0 8px 32px rgba(0,0,0,.55);
  padding: 10px 12px;
  align-items: center;
  gap: 8px;
  z-index: 500;
  white-space: nowrap;
  font-size: .78rem;
  min-width: 360px;
  max-width: 92vw;
  animation: bulkSlideUp .2s ease;
}
#bulkBar.visible { display: flex; }
#bulkCount {
  font-family: 'DM Mono', monospace;
  font-weight: 700;
  color: var(--accent);
  min-width: 64px;
  flex-shrink: 0;
}
#bulkDestSel {
  flex: 1;
  background: var(--bg3);
  border: 1px solid var(--border2);
  color: var(--text);
  border-radius: 7px;
  padding: 5px 8px;
  font-size: .76rem;
  min-width: 0;
}
@keyframes bulkSlideUp { from { opacity:0; transform:translateX(-50%) translateY(14px); } to { opacity:1; transform:translateX(-50%) translateY(0); } }

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
          <span class="pl-drag-handle" title="Arrastar para reordenar">
            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="5" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="9" cy="19" r="1"/><circle cx="15" cy="5" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="15" cy="19" r="1"/></svg>
          </span>
          <button class="pl-name-btn" onclick="switchPl('<?= htmlspecialchars($pl['id'],ENT_QUOTES) ?>')">
            <span class="pl-dot"></span>
            <span class="pl-name-text"><?= htmlspecialchars($pl['name']) ?></span>
            <?php if($idx===0): ?><span class="pl-def-badge">padrão</span><?php endif; ?>
            <?php if(!empty($pl['is_event'])): ?><span class="pl-event-badge">evento</span><?php endif; ?>
          </button>
          <?php if(!empty($pl['tags'])): ?>
          <div class="pl-tags-row">
            <?php foreach($pl['tags'] as $tag): ?>
            <span class="pl-tag"><?= htmlspecialchars($tag) ?></span>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
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
            <span class="pl-act-btn" onclick="duplicatePl('<?= htmlspecialchars($pl['id'],ENT_QUOTES) ?>','<?= htmlspecialchars($pl['name'],ENT_QUOTES) ?>')" title="Duplicar lista">
              <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
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
          <?php if(!empty($activePl['is_event'])): ?>
            <?php if(!empty($activePl['event_date'])): ?> · <span style="color:#f0a050">📅 <?= htmlspecialchars($activePl['event_date']) ?></span><?php endif; ?>
            <?php if(!empty($activePl['event_local'])): ?> · <span style="color:var(--text2)">📍 <?= htmlspecialchars($activePl['event_local']) ?></span><?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="topbar-actions">
      <span class="saving" id="savingInd">Salvo ✓</span>

      <!-- Desktop: all buttons visible -->
      <button class="btn btn-outline" id="importTextBtn" title="Importar lista de texto">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        <span class="btn-lbl">Importar</span>
      </button>
      <button class="btn btn-outline" id="mergePlBtn" title="Merge de listas">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 7H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3"/><polyline points="15 3 12 0 9 3"/><line x1="12" y1="0" x2="12" y2="15"/></svg>
        <span class="btn-lbl">Merge</span>
      </button>
      <button class="btn btn-outline" id="compareBtn" title="Comparar listas">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="10" y2="6"/><line x1="3" y1="12" x2="10" y2="12"/><line x1="3" y1="18" x2="10" y2="18"/><line x1="14" y1="6" x2="21" y2="6"/><line x1="14" y1="12" x2="21" y2="12"/><line x1="14" y1="18" x2="21" y2="18"/></svg>
        <span class="btn-lbl">Comparar</span>
      </button>
      <?php if($hasSpot): ?>
      <button class="btn btn-outline" id="syncBtn" title="Sincronizar com Spotify">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><polyline points="23 20 23 14 17 14"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg>
        <span class="btn-lbl">Sync</span>
      </button>
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
        <button class="btn btn-outline" id="importTextBtnM">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
          Importar Texto
        </button>
        <button class="btn btn-outline" id="mergePlBtnM">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M8 7H5a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3"/><polyline points="15 3 12 0 9 3"/><line x1="12" y1="0" x2="12" y2="15"/></svg>
          Merge de Listas
        </button>
        <button class="btn btn-outline" id="compareBtnM">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="6" x2="10" y2="6"/><line x1="3" y1="12" x2="10" y2="12"/><line x1="3" y1="18" x2="10" y2="18"/><line x1="14" y1="6" x2="21" y2="6"/><line x1="14" y1="12" x2="21" y2="12"/><line x1="14" y1="18" x2="21" y2="18"/></svg>
          Comparar Listas
        </button>
        <?php if($hasSpot): ?>
        <button class="btn btn-outline" id="syncBtnM">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"/><polyline points="23 20 23 14 17 14"/><path d="M20.49 9A9 9 0 0 0 5.64 5.64L1 10m22 4l-4.64 4.36A9 9 0 0 1 3.51 15"/></svg>
          Sync Spotify
        </button>
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
      <?php if(!empty($activePl['is_event'])): ?>
      <div class="print-event-info">
        <?php if(!empty($activePl['event_name'])): ?><span><?= htmlspecialchars($activePl['event_name']) ?></span><?php endif; ?>
        <?php if(!empty($activePl['event_local'])): ?><span>📍 <?= htmlspecialchars($activePl['event_local']) ?></span><?php endif; ?>
        <?php if(!empty($activePl['event_date'])): ?><span>📅 <?= htmlspecialchars($activePl['event_date']) ?></span><?php endif; ?>
      </div>
      <?php endif; ?>
      <p><span id="printDateSpan"></span></p>
      <hr style="border:none;border-top:1.5px solid #111;margin-bottom:14px">
    </div>
    <!-- Stat cards — only shown on print -->
    <div class="print-stats" style="display:none" id="printStatsRow">
      <div class="print-stat-card">
        <span class="print-stat-num"><?= str_pad($totalSongs,2,'0',STR_PAD_LEFT) ?></span>
        <span class="print-stat-label">Temas</span>
      </div>
      <div class="print-stat-card">
        <span class="print-stat-num" style="font-size:1.1rem;letter-spacing:-.02em"><?= $durStr ?: '—' ?></span>
        <span class="print-stat-label">Duração</span>
      </div>
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
            <th style="width:28px;padding:0 4px 0 8px" id="thCheckAll" title="Selecionar todas">
              <input type="checkbox" id="checkAll" style="accent-color:var(--accent);cursor:pointer;margin:0">
            </th>
            <th class="td-num">#</th>
            <th>Título</th>
            <th>Artista</th>
            <th class="td-cifra th-cifra-print">Cifra</th>
            <th class="td-spot"></th>
            <th class="th-dur-print" style="display:none">Duração</th>
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
            <td style="padding:0 4px 0 8px">
              <input type="checkbox" class="song-check" data-i="<?= $i ?>" style="accent-color:var(--accent);cursor:pointer;margin:0">
            </td>
            <td class="td-num"><?= str_pad($i+1,2,'0',STR_PAD_LEFT) ?></td>
            <td class="td-title"><?= htmlspecialchars($song['title_display'] ?? cleanTitle($song['title'])) ?></td>
            <td class="td-artist">
              <?= htmlspecialchars($song['artist']) ?>

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
            <td class="td-dur-print" style="display:none"><?= !empty($song['duration_ms']) ? fmtMs((int)$song['duration_ms']) : '' ?></td>
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

<!-- Compare playlists modal -->
<div class="modal-overlay" id="compareModal">
  <div class="modal" style="max-width:620px">
    <div class="modal-title">Comparar Listas</div>
    <div class="modal-sub">Mostra o que existe numa lista e não na outra.</div>
    <div class="modal-body">
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:14px">
        <div style="flex:1;min-width:140px">
          <div style="font-size:.68rem;color:var(--text3);margin-bottom:4px">Lista A (atual)</div>
          <div style="font-size:.82rem;font-weight:600;color:var(--text1)" id="cmpNameA"></div>
        </div>
        <div style="font-size:1.1rem;color:var(--text3)">↔</div>
        <div style="flex:1;min-width:140px">
          <div style="font-size:.68rem;color:var(--text3);margin-bottom:4px">Lista B</div>
          <select class="fi" id="cmpSelB" style="padding:5px 8px;font-size:.8rem"></select>
        </div>
        <button class="btn btn-primary" id="cmpRunBtn" style="flex-shrink:0">Comparar</button>
      </div>

      <div id="cmpResults" style="display:none">
        <!-- Only in A -->
        <div id="cmpOnlyAWrap">
          <div style="font-size:.74rem;font-weight:600;color:var(--accent);margin-bottom:6px" id="cmpOnlyATitle"></div>
          <div id="cmpOnlyAList" style="max-height:200px;overflow-y:auto;display:flex;flex-direction:column;gap:2px;font-size:.8rem;margin-bottom:14px"></div>
        </div>
        <!-- Only in B -->
        <div id="cmpOnlyBWrap">
          <div style="font-size:.74rem;font-weight:600;color:#f0a050;margin-bottom:6px" id="cmpOnlyBTitle"></div>
          <div id="cmpOnlyBList" style="max-height:200px;overflow-y:auto;display:flex;flex-direction:column;gap:2px;font-size:.8rem;margin-bottom:14px"></div>
        </div>
        <!-- In both -->
        <div id="cmpBothWrap" style="display:none">
          <div style="font-size:.74rem;font-weight:600;color:var(--text3);margin-bottom:6px" id="cmpBothTitle"></div>
          <div id="cmpBothList" style="max-height:160px;overflow-y:auto;display:flex;flex-direction:column;gap:2px;font-size:.8rem"></div>
        </div>
        <div id="cmpNoChanges" style="display:none;text-align:center;padding:20px 0;font-size:.84rem;color:var(--text3)">✓ As duas listas têm exactamente as mesmas músicas!</div>
      </div>
      <div id="cmpLoading" style="display:none;text-align:center;padding:20px 0;font-size:.8rem;color:var(--text3)">A comparar…</div>
      <div id="cmpError" class="alert alert-err" style="display:none"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('compareModal')">Fechar</button>
      <label style="display:flex;align-items:center;gap:6px;font-size:.75rem;color:var(--text2);cursor:pointer">
        <input type="checkbox" id="cmpShowBoth" style="accent-color:var(--accent)"> Mostrar músicas em comum
      </label>
    </div>
  </div>
</div>


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
    <div class="fg">
      <label class="fl">Tags <span style="font-size:.65rem;color:var(--text3)">(separadas por vírgula)</span></label>
      <input class="fi" type="text" id="plTags" placeholder="Ex: rock, acústico, casamento" autocomplete="off">
    </div>
    <div class="fg">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.82rem;margin-bottom:10px">
        <input type="checkbox" id="plIsEvent" style="accent-color:var(--accent)">
        <span>Esta lista é um <strong>evento</strong></span>
      </label>
      <div id="plEventFields" style="display:none;flex-direction:column;gap:10px">
        <input class="fi" type="text" id="plEventName" placeholder="Nome do evento (ex: Casamento João & Maria)">
        <input class="fi" type="text" id="plEventLocal" placeholder="Local (ex: Quinta da Ribeira, Aveiro)">
        <input class="fi" type="date" id="plEventDate">
      </div>
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
    <div class="fg">
      <label class="fl">Tags <span style="font-size:.65rem;color:var(--text3)">(separadas por vírgula)</span></label>
      <input class="fi" type="text" id="editPlTags" placeholder="Ex: rock, acústico, casamento">
    </div>
    <div class="fg">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.82rem;margin-bottom:10px">
        <input type="checkbox" id="editPlIsEvent" style="accent-color:var(--accent)">
        <span>Esta lista é um <strong>evento</strong></span>
      </label>
      <div id="editPlEventFields" style="display:none;flex-direction:column;gap:10px">
        <input class="fi" type="text" id="editPlEventName" placeholder="Nome do evento">
        <input class="fi" type="text" id="editPlEventLocal" placeholder="Local">
        <input class="fi" type="date" id="editPlEventDate">
      </div>
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

<!-- Import from text modal -->
<div class="modal-overlay" id="importTextModal">
  <div class="modal" style="max-width:560px">
    <div class="modal-title">Importar Lista de Texto</div>
    <div class="modal-sub">Cola a tua lista (um por linha, número opcional). Formato: <code style="font-family:'DM Mono',monospace;font-size:.72rem;background:var(--bg3);padding:1px 5px;border-radius:4px">Título — Artista</code> ou só <code style="font-family:'DM Mono',monospace;font-size:.72rem;background:var(--bg3);padding:1px 5px;border-radius:4px">Título</code>.</div>
    <div class="modal-body">

    <!-- Destination -->
    <div class="fg">
      <label class="fl">Destino</label>
      <div style="display:flex;flex-direction:column;gap:6px">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.8rem">
          <input type="radio" name="importDest" value="new" id="importDestNew" style="accent-color:var(--accent)">
          Criar nova lista
        </label>
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:.8rem">
          <input type="radio" name="importDest" value="existing" id="importDestExisting" checked style="accent-color:var(--accent)">
          Adicionar à lista actual (<strong id="importDestCurrentName"></strong>)
        </label>
      </div>
    </div>

    <div class="fg" id="importNewNameFg">
      <label class="fl">Nome da nova lista <span style="color:var(--danger)">*</span></label>
      <input class="fi" type="text" id="importNewName" placeholder="Ex: Setlist Casamento">
    </div>

    <div class="fg">
      <label class="fl" style="display:flex;align-items:center;justify-content:space-between">
        Lista de músicas
        <span id="importLineCount" style="font-family:'DM Mono',monospace;font-size:.65rem;color:var(--text3)">0 linhas</span>
      </label>
      <textarea class="fi" id="importTextArea" rows="10" placeholder="1. Stand By Me — John Lennon&#10;2. Crazy Little Thing Called Love — Queen&#10;Bizarre Love Triangle&#10;Imagine&#10;..." style="resize:vertical;font-family:'DM Mono',monospace;font-size:.72rem;line-height:1.6"></textarea>
    </div>

    <div id="importTextError" class="alert alert-err" style="display:none"></div>
    <div id="importTextResult" class="alert alert-ok" style="display:none"></div>


    <div id="importDebugWrap" style="display:none;margin-top:8px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
        <span style="font-size:.68rem;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.05em">🐛 Debug</span>
        <button onclick="$('#importDebugLog').text('');$('#importDebugWrap').hide();" style="background:none;border:none;color:var(--text3);cursor:pointer;font-size:.7rem;padding:0">limpar</button>
      </div>
      <pre id="importDebugLog" style="background:var(--bg3);border:1px solid var(--border2);border-radius:var(--r);padding:8px;font-family:'DM Mono',monospace;font-size:.65rem;line-height:1.5;color:var(--text2);white-space:pre-wrap;word-break:break-all;max-height:180px;overflow-y:auto;margin:0"></pre>
    </div>
    </div><!-- /modal-body -->
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('importTextModal')">Fechar</button>
      <button class="btn btn-outline" id="importToggleDebugBtn" style="font-size:.72rem;padding:4px 10px" title="Mostrar/esconder debug">🐛</button>
      <button class="btn btn-primary" id="importTextDoBtn">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        Importar
      </button>
    </div>
  </div>
</div>


<div class="modal-overlay" id="mergeModal">
  <div class="modal" style="max-width:500px">
    <div class="modal-title">Merge de Listas</div>
    <div class="modal-sub">Selecciona duas ou mais listas para fundir numa nova setlist.</div>
    <div class="modal-body">
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
    </div><!-- /modal-body -->
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


<!-- Sync with Spotify modal -->
<div class="modal-overlay" id="syncModal">
  <div class="modal" style="max-width:560px">
    <div class="modal-title">Spotify</div>
    <div class="modal-body">
    <?php if(!$hasSpot): ?>
    <div class="alert alert-err">Credenciais Spotify não configuradas.</div>
    <?php elseif(!$hasSpotUser): ?>
    <div style="text-align:center;padding:16px 0">
      <div style="font-size:.82rem;color:var(--text2);margin-bottom:14px">Para usar esta função é necessário ligar a tua conta Spotify.</div>
      <?php if($spotOAuthLink): ?>
      <a href="<?= htmlspecialchars($spotOAuthLink) ?>" class="btn btn-primary" style="display:inline-flex">
        <svg viewBox="0 0 24 24" fill="currentColor" style="width:14px;height:14px"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.586 14.424a.623.623 0 0 1-.857.207c-2.348-1.435-5.304-1.76-8.785-.964a.623.623 0 1 1-.277-1.215c3.809-.87 7.076-.496 9.712 1.115a.623.623 0 0 1 .207.857zm1.223-2.722a.78.78 0 0 1-1.072.257c-2.687-1.652-6.785-2.131-9.965-1.166a.78.78 0 1 1-.453-1.492c3.633-1.102 8.147-.568 11.233 1.329a.78.78 0 0 1 .257 1.072zm.105-2.835C14.692 8.95 9.375 8.775 6.297 9.71a.937.937 0 1 1-.543-1.793c3.521-1.068 9.376-.862 13.066 1.346a.937.937 0 0 1-.906 1.604z"/></svg>
        Ligar conta Spotify
      </a>
      <?php endif; ?>
    </div>
    <?php else: ?>
    <div style="font-size:.72rem;color:var(--text3);display:flex;align-items:center;gap:6px;margin-bottom:14px">
      <svg viewBox="0 0 24 24" fill="currentColor" style="width:12px;height:12px;color:var(--accent)"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.586 14.424a.623.623 0 0 1-.857.207c-2.348-1.435-5.304-1.76-8.785-.964a.623.623 0 1 1-.277-1.215c3.809-.87 7.076-.496 9.712 1.115a.623.623 0 0 1 .207.857zm1.223-2.722a.78.78 0 0 1-1.072.257c-2.687-1.652-6.785-2.131-9.965-1.166a.78.78 0 1 1-.453-1.492c3.633-1.102 8.147-.568 11.233 1.329a.78.78 0 0 1 .257 1.072zm.105-2.835C14.692 8.95 9.375 8.775 6.297 9.71a.937.937 0 1 1-.543-1.793c3.521-1.068 9.376-.862 13.066 1.346a.937.937 0 0 1-.906 1.604z"/></svg>
      Conta ligada &nbsp;·&nbsp; <a href="?pl=<?= urlencode($plId) ?>&spot_logout=1" style="color:var(--text3);text-decoration:none">desligar</a>
    </div>

    <?php if(empty($activePl['spotify_id'])): ?>
    <!-- No Spotify playlist linked: offer to create one -->
    <div style="border:1px solid var(--border2);border-radius:var(--r);padding:14px;margin-bottom:12px">
      <div style="font-size:.82rem;font-weight:600;margin-bottom:4px">Esta lista não tem playlist Spotify associada</div>
      <div style="font-size:.74rem;color:var(--text3);margin-bottom:12px">Cria uma playlist no Spotify com todas as músicas desta lista, pela ordem actual.</div>
      <button class="btn btn-primary" id="syncCreateSpotBtn" style="font-size:.78rem">
        <svg viewBox="0 0 24 24" fill="currentColor" style="width:13px;height:13px"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.586 14.424a.623.623 0 0 1-.857.207c-2.348-1.435-5.304-1.76-8.785-.964a.623.623 0 1 1-.277-1.215c3.809-.87 7.076-.496 9.712 1.115a.623.623 0 0 1 .207.857zm1.223-2.722a.78.78 0 0 1-1.072.257c-2.687-1.652-6.785-2.131-9.965-1.166a.78.78 0 1 1-.453-1.492c3.633-1.102 8.147-.568 11.233 1.329a.78.78 0 0 1 .257 1.072zm.105-2.835C14.692 8.95 9.375 8.775 6.297 9.71a.937.937 0 1 1-.543-1.793c3.521-1.068 9.376-.862 13.066 1.346a.937.937 0 0 1-.906 1.604z"/></svg>
        Criar Playlist no Spotify
      </button>
    </div>
    <div id="syncResult" class="alert alert-ok" style="display:none;margin-top:6px"></div>

    <?php else: ?>
    <!-- Has Spotify playlist: sync + reorder -->
    <div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap">
      <button class="btn btn-outline" id="syncAnalyseBtn" style="flex:1;min-width:120px">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        Analisar diferenças
      </button>
      <button class="btn btn-outline" id="syncReorderBtn" style="flex:1;min-width:120px" title="Reordena a playlist Spotify para ficar igual à lista local">
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
        Aplicar ordem local
      </button>
    </div>
    <div id="syncLoadingWrap" style="text-align:center;padding:18px 0;display:none">
      <div style="font-family:'DM Mono',monospace;font-size:.72rem;color:var(--text3)" id="syncLoadingMsg">A analisar diferenças…</div>
    </div>
    <div id="syncDiffWrap" style="display:none">
      <div id="syncStats" style="font-family:'DM Mono',monospace;font-size:.68rem;color:var(--text3);margin-bottom:10px"></div>

      <!-- Only on Spotify → add to local OR remove from Spotify -->
      <div id="syncOnlySpotWrap">
        <div style="font-size:.76rem;font-weight:600;color:var(--accent);margin-bottom:4px">📥 Só no Spotify</div>
        <div style="font-size:.68rem;color:var(--text3);margin-bottom:6px">Escolhe o que fazer com cada música:</div>
        <div id="syncOnlySpotList" style="max-height:160px;overflow-y:auto;font-size:.78rem;display:flex;flex-direction:column;gap:3px"></div>
        <div style="margin-top:6px;display:flex;gap:12px;flex-wrap:wrap">
          <label style="display:flex;align-items:center;gap:5px;cursor:pointer;font-size:.72rem;color:var(--text2)">
            <input type="checkbox" id="syncAddLocalAll" style="accent-color:var(--accent)"> Adicionar todas à lista local
          </label>
          <label style="display:flex;align-items:center;gap:5px;cursor:pointer;font-size:.72rem;color:var(--danger)">
            <input type="checkbox" id="syncRemoveSpotAll" style="accent-color:var(--danger)"> Remover todas do Spotify
          </label>
        </div>
      </div>
      <hr style="border:none;border-top:1px solid var(--border);margin:10px 0">

      <!-- Only local → add to Spotify OR remove from local -->
      <div id="syncOnlyLocalWrap">
        <div style="font-size:.76rem;font-weight:600;color:#f0a050;margin-bottom:4px">📤 Só na lista local</div>
        <div style="font-size:.68rem;color:var(--text3);margin-bottom:6px">Escolhe o que fazer com cada música:</div>
        <div id="syncOnlyLocalList" style="max-height:160px;overflow-y:auto;font-size:.78rem;display:flex;flex-direction:column;gap:3px"></div>
        <div style="margin-top:6px;display:flex;gap:12px;flex-wrap:wrap">
          <label style="display:flex;align-items:center;gap:5px;cursor:pointer;font-size:.72rem;color:var(--text2)">
            <input type="checkbox" id="syncAddSpotAll" style="accent-color:var(--accent)"> Adicionar todas ao Spotify
          </label>

        </div>
      </div>
      <hr style="border:none;border-top:1px solid var(--border);margin:10px 0">

      <!-- Suggest swapping to more popular version -->
      <div id="syncSwapWrap" style="display:none">
        <div style="font-size:.76rem;font-weight:600;color:#a78bfa;margin-bottom:4px">🔀 Versão mais popular disponível</div>
        <div style="font-size:.68rem;color:var(--text3);margin-bottom:6px">O Spotify tem uma versão muito mais ouvida destas músicas. Queres substituir?</div>
        <div id="syncSwapList" style="max-height:160px;overflow-y:auto;font-size:.78rem;display:flex;flex-direction:column;gap:3px"></div>
        <div style="margin-top:6px">
          <label style="display:flex;align-items:center;gap:5px;cursor:pointer;font-size:.72rem;color:var(--text2)">
            <input type="checkbox" id="syncSwapAll" style="accent-color:#a78bfa"> Substituir todas
          </label>
        </div>
      </div>
      <hr id="syncSwapHr" style="display:none;border:none;border-top:1px solid var(--border);margin:10px 0">

      <!-- Missing metadata (link / artist / duration) -->
      <div id="syncMissingUrlWrap" style="display:none">
        <div style="font-size:.76rem;font-weight:600;color:var(--text2);margin-bottom:4px">🔗 Metadados em falta</div>
        <div style="font-size:.68rem;color:var(--text3);margin-bottom:6px">Estas músicas existem em ambos mas faltam dados localmente (link Spotify, artista ou duração).</div>
        <div id="syncMissingUrlList" style="max-height:120px;overflow-y:auto;font-size:.78rem;display:flex;flex-direction:column;gap:3px"></div>
        <div style="margin-top:6px">
          <label style="display:flex;align-items:center;gap:5px;cursor:pointer;font-size:.72rem;color:var(--text2)">
            <input type="checkbox" id="syncFillUrlAll" checked style="accent-color:var(--accent)"> Preencher todos os metadados em falta
          </label>
        </div>
      </div>

      <div id="syncNoChanges" style="display:none;text-align:center;padding:16px 0;font-size:.82rem;color:var(--text3)">✓ Lista local e Spotify estão sincronizados!</div>
    </div>
    <div id="syncResult" class="alert alert-ok" style="display:none;margin-top:10px"></div>
    <?php endif; ?>
    <?php endif; ?>
    </div><!-- /modal-body -->
    <div class="modal-footer">
      <button class="btn btn-outline" onclick="closeModal('syncModal')">Fechar</button>
      <button class="btn btn-primary" id="syncApplyBtn" style="display:none">Aplicar Selecção</button>
    </div>
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

<!-- Bulk action bar (floating, appears when songs are selected) -->
<div id="bulkBar">
  <span id="bulkCount">0</span>
  <select id="bulkDestSel">
    <option value="">— Lista destino —</option>
  </select>
  <button id="bulkCopyBtn" class="btn btn-outline" style="font-size:.72rem;padding:5px 11px;flex-shrink:0">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:11px;height:11px"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
    Copiar
  </button>
  <button id="bulkMoveBtn" class="btn btn-outline" style="font-size:.72rem;padding:5px 11px;flex-shrink:0">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:11px;height:11px"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
    Mover
  </button>
  <div style="width:1px;height:20px;background:var(--border2);flex-shrink:0"></div>
  <button id="bulkDeleteBtn" class="btn btn-danger" style="font-size:.72rem;padding:5px 11px;flex-shrink:0">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:11px;height:11px"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
    Excluir
  </button>
  <button id="bulkCancelBtn" style="background:none;border:none;color:var(--text3);cursor:pointer;font-size:.9rem;padding:4px 8px;flex-shrink:0;line-height:1" title="Cancelar seleção">✕</button>
</div>

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

// ── Sidebar playlist drag reorder ──────────────────────────────
$(function(){
  $('#plSortable').sortable({
    handle: '.pl-drag-handle',
    axis: 'y',
    tolerance: 'pointer',
    placeholder: 'ui-sortable-placeholder',
    start: function(e, ui){
      if(LOCKED && !AUTHED){
        $('#plSortable').sortable('cancel');
        guardedAction(function(){});
        return false;
      }
      // Fix placeholder height to match dragged item
      ui.placeholder.height(ui.item.height());
    },
    update: function(){
      var ids = $('#plSortable li').map(function(){ return $(this).data('id'); }).get();
      $.post('?pl='+PL_ID, {_action:'reorder_pls', order:JSON.stringify(ids), pl:PL_ID}, function(r){
        if(r.ok){
          // Update padrão badge: only first item gets it
          $('#plSortable li').each(function(i){
            $(this).find('.pl-def-badge').remove();
            if(i===0){
              $(this).find('.pl-name-text').after('<span class="pl-def-badge">padrão</span>');
            }
          });
        }
      }, 'json');
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
        +'<td style="padding:0 4px 0 8px"><input type="checkbox" class="song-check" data-i="'+r.index+'" style="accent-color:var(--accent);cursor:pointer;margin:0"></td>'
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
    $('#plTags').val('');
    $('#plIsEvent').prop('checked',false);
    $('#plEventFields').hide();
    $('#plEventName,#plEventLocal,#plEventDate').val('');
    $('#plLookupStatus').text('').attr('class','ls');
    $('#plAddError').hide();
    _lookupOk=false; _lookupId=null;
    openModal('addPlModal');
    setTimeout(function(){ $('#plName').focus(); },80);
  });
});

$('#plIsEvent').on('change',function(){
  $('#plEventFields').toggle(this.checked).css('display', this.checked ? 'flex' : 'none');
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
  var tags=$('#plTags').val().trim();
  var isEvent=$('#plIsEvent').prop('checked')?'1':'0';
  var eventName=$('#plEventName').val().trim();
  var eventLocal=$('#plEventLocal').val().trim();
  var eventDate=$('#plEventDate').val().trim();
  $('#plAddError').hide();
  var btn=$('#plAddBtn');
  btn.prop('disabled',true).text('A criar…');
  $.post('?pl='+PL_ID,{_action:'add_pl',name:name,spotify_id:spotId,pl:PL_ID,
    tags:tags,is_event:isEvent,event_name:eventName,event_local:eventLocal,event_date:eventDate},function(r){
    btn.prop('disabled',false).text('Criar Lista');
    if(r.ok){ window.location.href='?pl='+encodeURIComponent(r.id); }
    else { $('#plAddError').text(r.error||'Erro').show(); }
  },'json');
}
$('#plAddBtn').on('click', doCreatePl);

// ── Edit playlist ──────────────────────────────────────────────
var _editPlMeta = {};
function openEditPlModal(id,spotId,name){
  guardedAction(function(){
    _editPlId=id;
    // Find pl meta from ALL_PLS_META
    var meta = ALL_PLS_META[id] || {};
    $('#editPlSub').text('A editar: '+name);
    $('#editPlName').val(name);
    $('#editPlSpotId').val(spotId);
    // Tags
    var tagsStr = (meta.tags||[]).join(', ');
    $('#editPlTags').val(tagsStr);
    // Event
    var isEv = !!meta.is_event;
    $('#editPlIsEvent').prop('checked', isEv);
    $('#editPlEventFields').css('display', isEv ? 'flex' : 'none');
    $('#editPlEventName').val(meta.event_name||'');
    $('#editPlEventLocal').val(meta.event_local||'');
    $('#editPlEventDate').val(meta.event_date||'');
    openModal('editPlModal');
    setTimeout(function(){ $('#editPlName').focus(); },80);
  });
}

$('#editPlIsEvent').on('change',function(){
  $('#editPlEventFields').css('display', this.checked ? 'flex' : 'none');
});

$('#editPlSaveBtn').on('click',function(){
  var newSpotId=$('#editPlSpotId').val().trim();
  var newName=$('#editPlName').val().trim();
  var tags=$('#editPlTags').val().trim();
  var isEvent=$('#editPlIsEvent').prop('checked')?'1':'0';
  var eventName=$('#editPlEventName').val().trim();
  var eventLocal=$('#editPlEventLocal').val().trim();
  var eventDate=$('#editPlEventDate').val().trim();
  $(this).prop('disabled',true).text('…');
  $.post('?pl='+PL_ID,{_action:'edit_pl',target_id:_editPlId,spotify_id:newSpotId,name:newName,pl:PL_ID,
    tags:tags,is_event:isEvent,event_name:eventName,event_local:eventLocal,event_date:eventDate},function(r){
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
    $('#importTextResult').hide(); $('#impReplace').prop('checked',true);
    openModal('importModal');
  });
}
$('#importDoBtn').on('click',function(){
  var mode=$('input[name="imp_mode"]:checked').val();
  $(this).prop('disabled',true).text('A importar…');
  $.post('?pl='+PL_ID,{_action:'import',target_id:_importPlId,mode:mode,pl:PL_ID},function(r){
    $('#importDoBtn').prop('disabled',false).html('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><polyline points="8 17 12 21 16 17"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.88 18.09A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.29"/></svg> Importar');
    if(r.ok){
      $('#importTextResult').text(r.count+' músicas importadas para "'+r.name+'".').show();
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
  // Show print-only stat cards and duration column
  $('#printStatsRow').show();
  // Apply body class for page sizing
  $('body').removeClass('print-1page print-2page').addClass('print-'+_printPages+'page');
  closeModal('printModal');
  setTimeout(function(){
    window.print();
    // Clean up after print dialog closes
    setTimeout(function(){
      $('body').removeClass('print-1page print-2page');
      $('#printStatsRow').hide();
    }, 500);
  }, 200);
}

var HAS_SPOT_USER = <?= $hasSpotUser?'true':'false' ?>;
var ALL_PLS = <?= json_encode(array_map(function($p){ return ['id'=>$p['id'],'name'=>$p['name']]; }, $playlists), JSON_UNESCAPED_UNICODE) ?>;
var ALL_PLS_META = <?= json_encode(array_reduce($playlists, function($carry,$p){
    $carry[$p['id']] = [
        'tags'        => $p['tags'] ?? [],
        'is_event'    => !empty($p['is_event']),
        'event_name'  => $p['event_name'] ?? '',
        'event_local' => $p['event_local'] ?? '',
        'event_date'  => $p['event_date'] ?? '',
    ];
    return $carry;
}, []), JSON_UNESCAPED_UNICODE) ?>;

// ── CSS for sync diff rows ─────────────────────────────────────
(function(){
  var s=document.createElement('style');
  s.textContent=
    '.sync-row{display:flex;align-items:center;gap:7px;padding:4px 7px;border-radius:5px;background:var(--bg3);transition:background .15s}'
   +'.sync-row:hover{background:var(--border)}'
   +'.sync-row label{flex:1;display:flex;align-items:center;gap:7px;cursor:pointer;font-size:.78rem}'
   +'.sync-row .sr-title{font-weight:500;color:var(--text)}'
   +'.sync-row .sr-artist{color:var(--text3);font-size:.68rem}'
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



// ── Sync with Spotify ──────────────────────────────────────────
var _syncDiff = null;

// ── Create Spotify playlist from sync modal ───────────────────
$('#syncModal').on('click','#syncCreateSpotBtn',function(){
  var btn=$(this);
  btn.prop('disabled',true).text('A criar…');
  $('#syncResult').hide();
  $.post('?pl='+PL_ID,{_action:'create_spot_playlist',pl:PL_ID,name:<?= json_encode($activePl['name']??'') ?>,desc:'Criada via SetList'},function(r){
    btn.prop('disabled',false).html('<svg viewBox="0 0 24 24" fill="currentColor" style="width:13px;height:13px"><path d="M12 2C6.477 2 2 6.477 2 12s4.477 10 10 10 10-4.477 10-10S17.523 2 12 2zm4.586 14.424a.623.623 0 0 1-.857.207c-2.348-1.435-5.304-1.76-8.785-.964a.623.623 0 1 1-.277-1.215c3.809-.87 7.076-.496 9.712 1.115a.623.623 0 0 1 .207.857zm1.223-2.722a.78.78 0 0 1-1.072.257c-2.687-1.652-6.785-2.131-9.965-1.166a.78.78 0 1 1-.453-1.492c3.633-1.102 8.147-.568 11.233 1.329a.78.78 0 0 1 .257 1.072zm.105-2.835C14.692 8.95 9.375 8.775 6.297 9.71a.937.937 0 1 1-.543-1.793c3.521-1.068 9.376-.862 13.066 1.346a.937.937 0 0 1-.906 1.604z"/></svg> Criar Playlist no Spotify');
    if(r.ok){
      var spotUrl = r.spotify_url ? ' <a href="'+r.spotify_url+'" target="_blank" style="color:var(--accent);text-decoration:none">Abrir ↗</a>' : '';
      $('#syncResult').removeClass('alert-err').addClass('alert-ok')
        .html('✓ Playlist criada! '+r.tracks_added+'/'+r.tracks_total+' músicas adicionadas.'+spotUrl).show();
      btn.prop('disabled',true).text('Criada ✓');
      setTimeout(function(){ window.location.reload(); }, 2500);
    } else if(r.error==='auth_required'){
      $('#syncResult').removeClass('alert-ok').addClass('alert-err').text('Sessão Spotify expirada. Recarrega a página.').show();
    } else {
      $('#syncResult').removeClass('alert-ok').addClass('alert-err').text(r.error||'Erro ao criar.').show();
      btn.prop('disabled',false);
    }
  },'json').fail(function(){
    btn.prop('disabled',false).text('Criar Playlist no Spotify');
    $('#syncResult').removeClass('alert-ok').addClass('alert-err').text('Erro de rede.').show();
  });
});

// ── Reorder Spotify to match local order ─────────────────────
$('#syncReorderBtn').on('click', function(){
  if(!confirm('Isto vai reordenar a playlist Spotify para ficar igual à lista local.\nA operação substitui a ordem actual do Spotify. Continuar?')) return;
  var btn=$(this);
  btn.prop('disabled',true).text('A reordenar…');
  $('#syncLoadingMsg').text('A reordenar playlist no Spotify…');
  $('#syncLoadingWrap').show();
  $('#syncDiffWrap,#syncResult').hide();
  $.post('?pl='+PL_ID,{_action:'spot_reorder',pl:PL_ID},function(r){
    btn.prop('disabled',false).html('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg> Aplicar ordem local');
    $('#syncLoadingWrap').hide();
    if(r.ok){
      $('#syncResult').removeClass('alert-err').addClass('alert-ok')
        .text('✓ Playlist reordenada! '+r.tracks_reordered+'/'+r.tracks_total+' músicas posicionadas.').show();
    } else if(r.error==='auth_required'){
      $('#syncResult').removeClass('alert-ok').addClass('alert-err').text('Sessão Spotify expirada. Recarrega a página.').show();
    } else {
      $('#syncResult').removeClass('alert-ok').addClass('alert-err').text(r.error||'Erro ao reordenar.').show();
    }
  },'json').fail(function(){
    btn.prop('disabled',false).text('Aplicar ordem local');
    $('#syncLoadingWrap').hide();
    $('#syncResult').removeClass('alert-ok').addClass('alert-err').text('Erro de rede.').show();
  });
});

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
  var onlySpot   = r.only_spotify||[];
  var onlyLocal  = r.only_local||[];
  var missingMeta = r.missing_meta||[];
  var suggestSwap = r.suggest_swap||[];

  var diffs = onlySpot.length + onlyLocal.length + missingMeta.length + suggestSwap.length;
  $('#syncStats').text('Spotify: '+r.spotify_total+' músicas · Local: '+r.local_total+' músicas · Diferenças: '+diffs);

  // ── Only on Spotify ──
  var spotList = $('#syncOnlySpotList').empty();
  if(onlySpot.length){
    $('#syncOnlySpotWrap').show();
    onlySpot.forEach(function(t, i){
      var idAdd = 'cbs_add_'+i, idRem = 'cbs_rem_'+i;
      var row = $('<div class="sync-row" style="justify-content:space-between">'
        +'<span style="flex:1;min-width:0"><span class="sr-title">'+escH(t.name)+'</span> <span class="sr-artist">— '+escH(t.artist)+'</span></span>'
        +'<span style="display:flex;gap:10px;flex-shrink:0;margin-left:8px">'
          +'<label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:.7rem;color:var(--accent)" title="Adicionar à lista local">'
            +'<input type="checkbox" id="'+idAdd+'" class="sync-add-local" data-uri="'+escH(t.uri)+'" style="accent-color:var(--accent)" checked> +local'
          +'</label>'
          +'<label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:.7rem;color:var(--danger)" title="Remover do Spotify">'
            +'<input type="checkbox" id="'+idRem+'" class="sync-rem-spot" data-uri="'+escH(t.uri)+'" style="accent-color:var(--danger)"> −Spotify'
          +'</label>'
        +'</span>'
        +'</div>');
      spotList.append(row);
    });
  } else {
    $('#syncOnlySpotWrap').hide();
  }

  // ── Only local ──
  var localList = $('#syncOnlyLocalList').empty();
  if(onlyLocal.length){
    $('#syncOnlyLocalWrap').show();
    onlyLocal.forEach(function(s, i){
      var idAdd = 'cbl_add_'+i, idRem = 'cbl_rem_'+i;
      var key = s.title+'|'+s.artist;
      var idx = s._idx !== undefined ? s._idx : -1;
      var row = $('<div class="sync-row" style="justify-content:space-between">'
        +'<span style="flex:1;min-width:0"><span class="sr-title">'+escH(s.title)+'</span> <span class="sr-artist">— '+escH(s.artist)+'</span></span>'
        +'<span style="display:flex;gap:10px;flex-shrink:0;margin-left:8px">'
          +'<label style="display:flex;align-items:center;gap:4px;cursor:pointer;font-size:.7rem;color:var(--accent)" title="Adicionar ao Spotify">'
            +'<input type="checkbox" id="'+idAdd+'" class="sync-add-spot" data-key="'+escH(key)+'" style="accent-color:var(--accent)" checked> +Spotify'
          +'</label>'
        +'</span>'
        +'</div>');
      localList.append(row);
    });
  } else {
    $('#syncOnlyLocalWrap').hide();
  }

  // ── Missing metadata (link / artist / duration) ──
  var urlList = $('#syncMissingUrlList').empty();
  if(missingMeta.length){
    $('#syncMissingUrlWrap').show();
    missingMeta.forEach(function(s){
      var badges = (s.missing||[]).map(function(m){
        return '<span style="background:var(--surface2);border-radius:3px;padding:1px 5px;font-size:.65rem;color:var(--text3)">'+escH(m)+'</span>';
      }).join(' ');
      var row = $('<div class="sync-row">'
        +'<label style="display:flex;align-items:center;gap:5px;cursor:pointer;flex:1;flex-wrap:wrap">'
          +'<input type="checkbox" class="sync-fill-url"'
            +' data-idx="'+s._idx+'"'
            +' data-spotify-url="'+escH(s.spotify_url)+'"'
            +' data-spotify-uri="'+escH(s.uri)+'"'
            +' data-spot-artist="'+escH(s.spot_artist||'')+'"'
            +' data-spot-duration="'+(s.spot_duration_ms||0)+'"'
            +' style="accent-color:var(--accent)" checked>'
          +'<span style="flex:1"><span class="sr-title">'+escH(s.title)+'</span>'
            +(s.artist ? ' <span class="sr-artist">— '+escH(s.artist)+'</span>' : ' <span class="sr-artist" style="color:var(--danger)">(sem artista)</span>')
            +' '+badges
          +'</span>'
        +'</label>'
        +'</div>');
      urlList.append(row);
    });
  } else {
    $('#syncMissingUrlWrap').hide();
  }

  // ── Suggest swap to more popular version ──
  var swapList = $('#syncSwapList').empty();
  if(suggestSwap.length){
    $('#syncSwapWrap,#syncSwapHr').show();
    suggestSwap.forEach(function(s){
      var row = $('<div class="sync-row" style="flex-direction:column;align-items:flex-start;gap:4px">'
        +'<label style="display:flex;align-items:center;gap:6px;cursor:pointer;width:100%">'
          +'<input type="checkbox" class="sync-swap" style="accent-color:#a78bfa;flex-shrink:0"'
            +' data-idx="'+s._idx+'"'
            +' data-cur-uri="'+escH(s.cur_uri)+'"'
            +' data-new-uri="'+escH(s.new_uri)+'"'
            +' data-new-url="'+escH(s.new_spotify_url)+'"'
            +' data-new-artist="'+escH(s.new_artist)+'"'
            +' data-new-duration="'+(s.new_duration_ms||0)+'">'
          +'<span style="flex:1">'
            +'<span class="sr-title">'+escH(s.title)+'</span>'
            +'<div style="font-size:.68rem;margin-top:2px;display:flex;gap:8px;flex-wrap:wrap">'
              +'<span style="color:var(--danger)">❌ '+escH(s.cur_artist)+' <span style="opacity:.6">(pop. '+s.cur_popularity+')</span></span>'
              +'<span style="color:#a78bfa">→ ✅ '+escH(s.new_artist)+' <span style="opacity:.6">(pop. '+s.new_popularity+')</span></span>'
            +'</div>'
          +'</span>'
        +'</label>'
        +'</div>');
      swapList.append(row);
    });
  } else {
    $('#syncSwapWrap,#syncSwapHr').hide();
  }

  var anyDiff = onlySpot.length || onlyLocal.length || missingMeta.length || suggestSwap.length;
  if(!anyDiff){
    $('#syncNoChanges').show();
    $('#syncOnlySpotWrap,#syncOnlyLocalWrap,#syncMissingUrlWrap').hide();
    $('#syncApplyBtn').hide();
  } else {
    $('#syncNoChanges').hide();
    $('#syncApplyBtn').show();
  }
  $('#syncDiffWrap').show();
}

// Select-all helpers
$('#syncAddLocalAll').on('change', function(){ $('.sync-add-local').prop('checked', this.checked); });
$('#syncRemoveSpotAll').on('change', function(){ $('.sync-rem-spot').prop('checked', this.checked); });
$('#syncAddSpotAll').on('change', function(){ $('.sync-add-spot').prop('checked', this.checked); });
$('#syncSwapAll').on('change', function(){ $('.sync-swap').prop('checked', this.checked); });

// Mutual exclusion: adding and removing the same track makes no sense
$(document).on('change','.sync-add-local',function(){
  if(this.checked){ $(this).closest('.sync-row').find('.sync-rem-spot').prop('checked',false); }
});
$(document).on('change','.sync-rem-spot',function(){
  if(this.checked){ $(this).closest('.sync-row').find('.sync-add-local').prop('checked',false); }
});


$('#syncApplyBtn').on('click', function(){
  var addLocal      = [];
  var removeSpot    = [];
  var addSpotify    = [];
  var fillUrls      = [];
  var swapVersions  = [];

  $('.sync-add-local:checked').each(function(){ addLocal.push($(this).data('uri')); });
  $('.sync-rem-spot:checked').each(function(){ removeSpot.push($(this).data('uri')); });
  $('.sync-add-spot:checked').each(function(){ addSpotify.push($(this).data('key')); });
  $('.sync-fill-url:checked').each(function(){
    fillUrls.push({
      idx          : parseInt($(this).data('idx')),
      spotify_url  : $(this).data('spotify-url'),
      spotify_uri  : $(this).data('spotify-uri'),
      spot_artist  : $(this).data('spot-artist') || '',
      spot_duration_ms: parseInt($(this).data('spot-duration')) || 0
    });
  });
  $('.sync-swap:checked').each(function(){
    swapVersions.push({
      idx          : parseInt($(this).data('idx')),
      cur_uri      : $(this).data('cur-uri'),
      new_uri      : $(this).data('new-uri'),
      new_spotify_url: $(this).data('new-url'),
      new_artist   : $(this).data('new-artist'),
      new_duration_ms: parseInt($(this).data('new-duration')) || 0
    });
  });

  if(!addLocal.length && !removeSpot.length && !addSpotify.length && !fillUrls.length && !swapVersions.length){
    toast('Nenhuma opção seleccionada.');
    return;
  }

  var msgs = [];
  if(addLocal.length)     msgs.push('+ '+addLocal.length+' música(s) à lista local');
  if(addSpotify.length)   msgs.push('+ '+addSpotify.length+' música(s) ao Spotify');
  if(removeSpot.length)   msgs.push('− '+removeSpot.length+' música(s) do Spotify');
  if(fillUrls.length)     msgs.push('🔗 Preencher '+fillUrls.length+' metadado(s) em falta');
  if(swapVersions.length) msgs.push('🔀 Substituir '+swapVersions.length+' versão(ões) pela mais popular');
  if(!confirm('Confirmas as seguintes alterações?\n\n• '+msgs.join('\n• '))) return;

  var btn = $(this);
  btn.prop('disabled',true).text('A sincronizar…');

  $.post('?pl='+PL_ID, {
    _action          :'spot_sync_apply',
    pl               :PL_ID,
    add_local        :JSON.stringify(addLocal),
    remove_local_idx :'[]',
    add_spotify      :JSON.stringify(addSpotify),
    remove_spotify   :JSON.stringify(removeSpot),
    fill_spotify_urls:JSON.stringify(fillUrls),
    swap_versions    :JSON.stringify(swapVersions)
  }, function(r){
    btn.prop('disabled',false).text('Aplicar Selecção');
    if(r.ok){
      function fmtMsJs(ms){
        if(!ms) return '';
        var s=Math.round(ms/1000), h=Math.floor(s/3600), m=Math.floor((s%3600)/60);
        return h>0 ? h+'h '+String(m).padStart(2,'0')+'m' : m+':'+ String(s%60).padStart(2,'0');
      }
      var dur = r.total_duration_ms ? ' · '+fmtMsJs(r.total_duration_ms) : '';
      $('#syncResult').removeClass('alert-err').addClass('alert-ok')
        .text('✓ Sincronização aplicada! '+r.local_count+' músicas'+dur+'.').show();
      $('#syncApplyBtn').hide();
      setTimeout(function(){ window.location.reload(); }, 2200);
    } else {
      $('#syncResult').removeClass('alert-ok').addClass('alert-err').text(r.error||'Erro ao sincronizar.').show();
    }
  }, 'json').fail(function(){
    btn.prop('disabled',false).text('Aplicar Selecção');
    $('#syncResult').removeClass('alert-ok').addClass('alert-err').text('Erro de rede.').show();
  });
});

// ── Import from text ──────────────────────────────────────────
// ── Import debug helper ───────────────────────────────────────
function importDebug(msg) {
  var ts = new Date().toLocaleTimeString('pt-PT', {hour12:false,hour:'2-digit',minute:'2-digit',second:'2-digit'});
  var log = $('#importDebugLog');
  log.text(log.text() + '['+ts+'] ' + msg + '\n');
  log.scrollTop(log[0].scrollHeight);
  $('#importDebugWrap').show();
  console.log('[ImportDebug]', msg);
}

$('#importTextBtn').on('click', function(){
  console.log('[ImportDebug] Botão importar clicado. LOCKED='+LOCKED+' AUTHED='+AUTHED);
  guardedAction(function(){
    $('#importTextArea').val('');
    $('#importNewName').val('');
    $('#importTextError,#importTextResult').hide();
    $('#importTextDoBtn').prop('disabled',false).html('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="width:13px;height:13px"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg> Importar');
    $('#importDestExisting').prop('checked',true);
    $('#importNewNameFg').hide();
    $('#importDestCurrentName').text(<?= json_encode($activePl['name']??'') ?>);
    $('#importLineCount').text('0 linhas');
    openModal('importTextModal');
    setTimeout(function(){ $('#importTextArea').focus(); },80);
  });
});

// Debug toggle button
$('#importToggleDebugBtn').on('click', function(){
  if($('#importDebugWrap').is(':visible')) $('#importDebugWrap').hide();
  else $('#importDebugWrap').show();
});

// Destination toggle
$('input[name="importDest"]').on('change', function(){
  if($(this).val()==='new') $('#importNewNameFg').show();
  else $('#importNewNameFg').hide();
});

// Live line counter
$('#importTextArea').on('input', function(){
  var lines = $(this).val().split('\n').filter(function(l){ return l.trim()!==''; });
  $('#importLineCount').text(lines.length+' linha'+(lines.length===1?'':'s'));
});

// ── Import text: step 1 = preview, step 2 = confirm + import ──
$('#importTextDoBtn').on('click', function(){
  var txt  = $('#importTextArea').val().trim();
  var dest = $('input[name="importDest"]:checked').val();
  var name = $('#importNewName').val().trim();
  if(!txt){ $('#importTextError').text('Cola a lista primeiro.').show(); return; }
  if(dest==='new' && !name){ $('#importTextError').text('Define um nome para a nova lista.').show(); return; }
  $('#importTextError').hide();
  var btn=$(this);
  btn.prop('disabled',true).html('A importar…');

  var payload = { _action:'import_text', pl:PL_ID, text:txt };
  if(dest==='new'){ payload.name = name; }
  else { payload.target_id = PL_ID; }

  $.post('?pl='+PL_ID, payload, function(r){
    btn.prop('disabled',false).html('Importar');
    if(r.ok){
      closeModal('importTextModal');
      if(dest==='new' && r.id){
        window.location.href = '?pl=' + encodeURIComponent(r.id);
      } else {
        window.location.reload();
      }
    } else {
      $('#importTextError').text(r.error||'Erro ao importar.').show();
    }
  }, 'json').fail(function(xhr){
    btn.prop('disabled',false).html('Importar');
    $('#importTextError').text('Erro de rede ('+xhr.status+').').show();
  });
});

// ── Create Spotify playlist from imported list ────────────────

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
$('#importTextBtnM').on('click', function(){ closeOverflow(); $('#importTextBtn').trigger('click'); });
$('#mergePlBtnM').on('click', function(){ closeOverflow(); $('#mergePlBtn').trigger('click'); });

$('#syncBtnM').on('click', function(){ closeOverflow(); $('#syncBtn').trigger('click'); });
$('#copyListBtnM').on('click', function(){ closeOverflow(); $('#copyListBtn').trigger('click'); });

// ── Utility ────────────────────────────────────────────────────
function escH(s){ return $('<span>').text(s).html(); }

// ═══════════════════════════════════════════════════════════════
// BULK SELECTION — checkbox select + copy/move/delete in bulk
// ═══════════════════════════════════════════════════════════════
function getSelectedIndices(){
  return $('.song-check:checked').map(function(){ return parseInt($(this).data('i')); }).get();
}

function updateBulkBar(){
  var sel = getSelectedIndices();
  var n = sel.length;
  if(n === 0){
    $('#bulkBar').removeClass('visible');
    $('#checkAll').prop('checked',false).prop('indeterminate',false);
  } else {
    var total = $('.song-check').length;
    $('#checkAll').prop('indeterminate', n>0 && n<total).prop('checked', n===total);
    $('#bulkCount').text(n + (n===1?' música':' músicas'));
    $('#bulkBar').addClass('visible');
  }
}

function populateBulkDest(){
  var otherPls = ALL_PLS.filter(function(p){ return p.id !== PL_ID; });
  var html = '<option value="">— Lista destino —</option>';
  otherPls.forEach(function(p){
    html += '<option value="'+escH(p.id)+'">'+escH(p.name)+'</option>';
  });
  $('#bulkDestSel').html(html);
}

function bulkAction(action){
  var indices = getSelectedIndices();
  var destId  = $('#bulkDestSel').val();
  if(!indices.length){ toast('Seleciona pelo menos uma música.'); return; }
  if(!destId){ toast('Escolhe a lista destino.'); $('#bulkDestSel').focus(); return; }
  var destName = $('#bulkDestSel option:selected').text();
  guardedAction(function(){
    var btn = action==='copy' ? $('#bulkCopyBtn') : $('#bulkMoveBtn');
    btn.prop('disabled',true);
    $.post('?pl='+PL_ID, {
      _action: action==='copy' ? 'copy_songs_bulk' : 'move_songs_bulk',
      dest_pl: destId,
      indices: JSON.stringify(indices)
    }, function(r){
      btn.prop('disabled',false);
      if(r.ok){
        var msg = action==='copy'
          ? r.added+' música'+(r.added===1?' copiada':' copiadas')+' para "'+destName+'"'+(r.skipped?' ('+r.skipped+' já existiam)':'')+'.'
          : r.added+' música'+(r.added===1?' movida':' movidas')+' para "'+destName+'"'+(r.skipped?' ('+r.skipped+' já existiam)':'')+'.' ;
        toast(msg);
        setTimeout(function(){ window.location.reload(); }, 900);
      } else { alert(r.error||'Erro.'); }
    },'json').fail(function(){ btn.prop('disabled',false); alert('Erro de rede.'); });
  });
}

$(function(){
  // Init destination select after DOM ready
  populateBulkDest();

  $('#checkAll').on('change', function(){
    var checked = this.checked;
    $('.song-check').prop('checked', checked);
    $('.song-row').toggleClass('is-selected', checked);
    updateBulkBar();
  });

  $(document).on('change', '.song-check', function(){
    $(this).closest('.song-row').toggleClass('is-selected', this.checked);
    updateBulkBar();
  });

  // Click anywhere on checkbox cell to toggle
  $(document).on('click', '.song-row td:nth-child(2)', function(e){
    if($(e.target).is('input')) return;
    var cb = $(this).find('.song-check');
    cb.prop('checked', !cb.prop('checked')).trigger('change');
  });

  $('#bulkCancelBtn').on('click', function(){
    $('.song-check').prop('checked', false);
    $('.song-row').removeClass('is-selected');
    $('#checkAll').prop('checked',false).prop('indeterminate',false);
    updateBulkBar();
  });

  $(document).on('keydown', function(e){
    if(e.key==='Escape' && $('#bulkBar').hasClass('visible')) $('#bulkCancelBtn').trigger('click');
  });

  $('#bulkCopyBtn').on('click', function(){ bulkAction('copy'); });
  $('#bulkMoveBtn').on('click', function(){ bulkAction('move'); });

  $('#bulkDeleteBtn').on('click', function(){
    var indices = getSelectedIndices();
    if(!indices.length) return;
    if(!confirm('Excluir ' + indices.length + ' música' + (indices.length===1?'':'s') + ' desta lista?')) return;
    guardedAction(function(){
      $('#bulkDeleteBtn').prop('disabled',true);
      $.post('?pl='+PL_ID, {
        _action: 'delete_songs_bulk',
        indices: JSON.stringify(indices)
      }, function(r){
        $('#bulkDeleteBtn').prop('disabled',false);
        if(r.ok){
          toast(r.removed + ' música'+(r.removed===1?' excluída':' excluídas')+'.');
          setTimeout(function(){ window.location.reload(); }, 700);
        } else { alert(r.error||'Erro ao excluir.'); }
      },'json').fail(function(){ $('#bulkDeleteBtn').prop('disabled',false); alert('Erro de rede.'); });
    });
  });
});

// ═══════════════════════════════════════════════════════════════
// DUPLICATE PLAYLIST
// ═══════════════════════════════════════════════════════════════
function duplicatePl(id, name) {
  guardedAction(function(){
    var newName = prompt('Nome da lista duplicada:', name + ' (cópia)');
    if(newName === null) return; // cancelled
    if(!newName.trim()) newName = name + ' (cópia)';
    $.post('?pl='+PL_ID, {_action:'duplicate_pl', src_pl:id, name:newName.trim()}, function(r){
      if(r.ok){
        toast('Lista "'+r.name+'" criada com '+r.track_count+' músicas.');
        setTimeout(function(){ window.location.reload(); }, 900);
      } else {
        alert(r.error||'Erro ao duplicar.');
      }
    }, 'json').fail(function(){ alert('Erro de rede.'); });
  });
}




// ═══════════════════════════════════════════════════════════════
// COMPARE PLAYLISTS
// ═══════════════════════════════════════════════════════════════
function openCompareModal() {
  var other = ALL_PLS.filter(function(p){ return p.id !== PL_ID; });
  if(!other.length){ alert('Precisas de pelo menos duas listas para comparar.'); return; }
  var html = '';
  other.forEach(function(p){ html += '<option value="'+escH(p.id)+'">'+escH(p.name)+'</option>'; });
  $('#cmpSelB').html(html);
  $('#cmpNameA').text(<?= json_encode($activePl['name']??'') ?>);
  $('#cmpResults,#cmpLoading,#cmpError').hide();
  openModal('compareModal');
}

$('#compareBtn').on('click', openCompareModal);
$('#compareBtnM').on('click', function(){ closeOverflow(); openCompareModal(); });

$('#cmpShowBoth').on('change', function(){
  if(this.checked) $('#cmpBothWrap').show();
  else $('#cmpBothWrap').hide();
});

function cmpNorm(s) {
  return (s||'').toLowerCase()
    .replace(/[áàãâä]/g,'a').replace(/[éêëè]/g,'e').replace(/[íîïì]/g,'i')
    .replace(/[óôõöò]/g,'o').replace(/[úûüù]/g,'u').replace(/ç/g,'c').replace(/ñ/g,'n')
    .replace(/[^a-z0-9]/g,'');
}
function cmpKey(title, artist) {
  return cmpNorm(title) + '||' + cmpNorm((artist||'').split(/[\s,;&\/]+/)[0]);
}

$('#cmpRunBtn').on('click', function(){
  var bId = $('#cmpSelB').val();
  if(!bId){ $('#cmpError').text('Escolhe a lista B.').show(); return; }
  $('#cmpError').hide();
  $('#cmpResults').hide();
  $('#cmpLoading').show();

  $.post('?pl='+PL_ID, { _action:'get_songs_for_compare', pl_id: bId }, function(r){
    $('#cmpLoading').hide();
    if(!r.ok){ $('#cmpError').text(r.error||'Erro.').show(); return; }

    var songsA = SONGS;  // current playlist already in memory
    var songsB = r.songs;
    var bName  = $('#cmpSelB option:selected').text();

    // Build key sets
    var keysA = {}, keysB = {};
    songsA.forEach(function(s){ var k=cmpKey(s.title,s.artist); keysA[k] = s; });
    songsB.forEach(function(s){ var k=cmpKey(s.title,s.artist); keysB[k] = s; });

    var onlyA = [], onlyB = [], both = [];
    Object.keys(keysA).forEach(function(k){ if(keysB[k]) both.push(keysA[k]); else onlyA.push(keysA[k]); });
    Object.keys(keysB).forEach(function(k){ if(!keysA[k]) onlyB.push(keysB[k]); });

    function renderList(songs, container) {
      var html = '';
      songs.forEach(function(s){
        html += '<div style="padding:4px 8px;border-radius:5px;background:var(--surface2)">'
          + '<span style="font-weight:500">'+escH(s.title)+'</span>'
          + (s.artist ? ' <span style="color:var(--text3);font-size:.72rem">— '+escH(s.artist)+'</span>' : '')
          + '</div>';
      });
      $(container).html(html || '<div style="color:var(--text3);font-size:.75rem;padding:4px 8px">—</div>');
    }

    var anyDiff = onlyA.length || onlyB.length;

    if(!anyDiff){
      $('#cmpNoChanges').show();
      $('#cmpOnlyAWrap,#cmpOnlyBWrap').hide();
    } else {
      $('#cmpNoChanges').hide();
      if(onlyA.length){
        $('#cmpOnlyATitle').text('Só em "' + <?= json_encode($activePl['name']??'') ?> + '" (' + onlyA.length + ')');
        renderList(onlyA, '#cmpOnlyAList');
        $('#cmpOnlyAWrap').show();
      } else { $('#cmpOnlyAWrap').hide(); }

      if(onlyB.length){
        $('#cmpOnlyBTitle').text('Só em "' + bName + '" (' + onlyB.length + ')');
        renderList(onlyB, '#cmpOnlyBList');
        $('#cmpOnlyBWrap').show();
      } else { $('#cmpOnlyBWrap').hide(); }
    }

    if(both.length){
      $('#cmpBothTitle').text('Em ambas (' + both.length + ')');
      renderList(both, '#cmpBothList');
    }
    $('#cmpBothWrap').toggle($('#cmpShowBoth').prop('checked') && both.length > 0);

    $('#cmpResults').show();
  }, 'json').fail(function(){
    $('#cmpLoading').hide();
    $('#cmpError').text('Erro de rede.').show();
  });
});

</script>



</body>
</html>
