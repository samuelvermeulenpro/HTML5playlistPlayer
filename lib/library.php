<?php
/**
 * Lecture de la bibliothèque audio avec cache.
 *
 * - Les tags (getID3) ne sont lus que pour les fichiers nouveaux ou modifiés
 *   (comparaison taille + date de modification), le reste vient de cache/library.json.
 * - Les pochettes sont extraites une seule fois dans cache/covers/ et dédoublonnées
 *   (un album = une seule image), au lieu d'être injectées en base64 dans la page.
 * - Si le dossier cache n'est pas inscriptible, tout fonctionne quand même
 *   (pochettes en data URI, sans cache) : c'est juste plus lent.
 */

const PL_CACHE_VERSION = 3;

function pl_root(): string
{
    return dirname(__DIR__);
}

/** Chemin disque du dossier audio, à partir de $audio_dir (relatif au dossier du player ou absolu). */
function pl_audio_path(string $audio_dir): string
{
    $dir = rtrim($audio_dir, '/\\') . '/';
    if ($dir[0] === '/' || preg_match('~^[A-Za-z]:[\\\\/]~', $dir)) {
        return $dir;
    }
    return pl_root() . '/' . $dir;
}

/** URL (relative à player.php) d'un fichier audio. */
function pl_audio_url(string $audio_dir, string $name): string
{
    $dir = rtrim($audio_dir, '/') . '/';
    return $dir . rawurlencode($name);
}

function pl_cache_writable(string $cache): bool
{
    if (!is_dir($cache . 'covers')) {
        @mkdir($cache . 'covers', 0775, true);
    }
    return is_dir($cache . 'covers') && is_writable($cache) && is_writable($cache . 'covers');
}

/**
 * Retourne la liste des pistes :
 * [ ['id','src','title','artist','album','duration','cover'], ... ]
 */
function pl_library(array $cfg): array
{
    $audio_dir = $cfg['audio_dir'];
    $exts      = array_map('strtolower', $cfg['audio_ext']);
    $sort_by   = $cfg['sort_by'];
    $path      = pl_audio_path($audio_dir);
    $cache     = pl_root() . '/cache/';
    $useCache  = pl_cache_writable($cache);
    $cacheFile = $cache . 'library.json';

    // 1. Liste des fichiers audio
    $files = [];
    foreach (is_dir($path) ? (scandir($path) ?: []) : [] as $name) {
        if ($name[0] === '.') continue;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, $exts, true) && is_file($path . $name)) {
            $files[] = $name;
        }
    }
    natcasesort($files);

    // 2. Cache existant
    $db = ['v' => PL_CACHE_VERSION, 'files' => []];
    if ($useCache && is_file($cacheFile)) {
        $json = json_decode((string) file_get_contents($cacheFile), true);
        if (is_array($json) && ($json['v'] ?? 0) === PL_CACHE_VERSION) {
            $db = $json;
        }
    }

    // Pochette par défaut du dossier (cover.jpg, folder.jpg...)
    $folderCover = null;
    foreach (['cover.jpg', 'cover.png', 'folder.jpg', 'folder.png', 'cover.webp'] as $c) {
        if (is_file($path . $c)) { $folderCover = pl_audio_url($audio_dir, $c); break; }
    }

    $getID3  = null;
    $changed = false;
    $tracks  = [];
    $seen    = [];

    foreach ($files as $name) {
        $full  = $path . $name;
        $stat  = @stat($full);
        $key   = $name;
        $entry = $db['files'][$key] ?? null;
        $seen[$key] = true;

        if (!$entry || $entry['mtime'] !== $stat['mtime'] || $entry['size'] !== $stat['size'] || !$useCache) {
            if ($getID3 === null) {
                require_once __DIR__ . '/getid3/getid3.php';
                @set_time_limit(0);
                $getID3 = new getID3();
                $getID3->setOption(['encoding' => 'UTF-8', 'option_md5_data' => false]);
            }
            $entry = pl_read_tags($getID3, $full, $name, $cache, $useCache);
            $entry['mtime'] = $stat['mtime'];
            $entry['size']  = $stat['size'];
            $db['files'][$key] = $entry;
            $changed = true;
        }

        $tracks[] = [
            'id'       => substr(sha1($name), 0, 12),
            'src'      => pl_audio_url($audio_dir, $name),
            'file'     => $name,
            'title'    => $entry['title'],
            'artist'   => $entry['artist'],
            'album'    => $entry['album'],
            'track'    => $entry['track'],
            'duration' => $entry['duration'],
            'cover'    => $entry['cover'] ?? $folderCover,
        ];
    }

    // 3. Nettoyage des fichiers supprimés + sauvegarde du cache
    foreach (array_keys($db['files']) as $key) {
        if (!isset($seen[$key])) { unset($db['files'][$key]); $changed = true; }
    }
    if ($useCache && $changed) {
        $tmp = $cacheFile . '.' . getmypid() . '.tmp';
        if (file_put_contents($tmp, json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) !== false) {
            @rename($tmp, $cacheFile);
        }
        pl_clean_covers($cache, $db['files']);
    }

    // 4. Tri
    if ($sort_by !== 'filename') {
        usort($tracks, function ($a, $b) use ($sort_by) {
            $order = [
                'title'  => ['title'],
                'artist' => ['artist', 'album', 'track', 'title'],
                'album'  => ['album', 'track', 'title'],
            ][$sort_by] ?? ['file'];
            foreach ($order as $f) {
                $r = is_int($a[$f]) ? $a[$f] <=> $b[$f] : strnatcasecmp((string) $a[$f], (string) $b[$f]);
                if ($r !== 0) return $r;
            }
            return 0;
        });
    }

    return $tracks;
}

function pl_read_tags(getID3 $getID3, string $full, string $name, string $cache, bool $useCache): array
{
    $info = @$getID3->analyze($full);
    getid3_lib::CopyTagsToComments($info);
    $c = $info['comments'] ?? [];

    $first = static function ($k) use ($c) {
        $v = $c[$k][0] ?? '';
        return is_string($v) ? trim($v) : '';
    };

    $title = $first('title');
    if ($title === '') {
        $title = pathinfo($name, PATHINFO_FILENAME);
    }

    $entry = [
        'title'    => $title,
        'artist'   => $first('artist'),
        'album'    => $first('album'),
        'track'    => (int) $first('track_number') ?: (int) $first('tracknumber') ?: (int) $first('track'),
        'duration' => isset($info['playtime_seconds']) ? round((float) $info['playtime_seconds'], 1) : 0,
        'cover'    => null,
    ];

    $pic = $c['picture'][0] ?? ($info['id3v2']['APIC'][0] ?? null);
    if (!empty($pic['data'])) {
        $mime = $pic['image_mime'] ?? 'image/jpeg';
        $ext  = ['image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime] ?? 'jpg';
        if ($useCache) {
            $file = 'covers/' . sha1($pic['data']) . '.' . $ext;   // même image = même fichier
            if (!is_file($cache . $file)) {
                file_put_contents($cache . $file, $pic['data']);
            }
            $entry['cover'] = 'cache/' . $file;
        } else {
            $entry['cover'] = 'data:' . $mime . ';base64,' . base64_encode($pic['data']);
        }
    }
    return $entry;
}

/** Supprime les pochettes qui ne sont plus utilisées par aucune piste. */
function pl_clean_covers(string $cache, array $files): void
{
    $used = [];
    foreach ($files as $f) {
        if (!empty($f['cover'])) $used[basename($f['cover'])] = true;
    }
    foreach (glob($cache . 'covers/*') ?: [] as $img) {
        if (!isset($used[basename($img)])) @unlink($img);
    }
}
