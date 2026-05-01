<?php
echo "PHP OK: " . phpversion();
echo "<br>curl: " . (function_exists('curl_init') ? 'disponível' : 'BLOQUEADO');
echo "<br>file_put_contents: " . (is_writable('playlists.json') ? 'gravável' : 'SEM PERMISSÃO');