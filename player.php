<?php
declare(strict_types=1);

require __DIR__ . '/conf/config.inc.php';
require __DIR__ . '/lib/library.php';

$cfg = [
    'audio_dir' => $audio_dir    ?? 'mp3/',
    'audio_ext' => $audio_ext    ?? ['mp3', 'm4a', 'ogg', 'oga', 'opus', 'flac', 'wav'],
    'sort_by'   => $sort_by      ?? 'filename',
];
$page_title   = $page_title   ?? 'Player';
$player_title = $player_title ?? '🎵 Playlist';

$tracks = pl_library($cfg);

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fmt_time(float $sec): string
{
    $sec = (int) round($sec);
    $hms = $sec >= 3600 ? sprintf('%d:%02d:%02d', intdiv($sec, 3600), intdiv($sec % 3600, 60), $sec % 60)
                        : sprintf('%d:%02d', intdiv($sec, 60), $sec % 60);
    return $hms;
}

$total = array_sum(array_column($tracks, 'duration'));
$asset = static fn(string $f): string => 'assets/' . $f . '?v=' . @filemtime(__DIR__ . '/assets/' . $f);

$json = json_encode(array_map(static fn($t) => [
    'id'       => $t['id'],
    'src'      => $t['src'],
    'title'    => $t['title'],
    'artist'   => $t['artist'],
    'album'    => $t['album'],
    'duration' => $t['duration'],
    'cover'    => $t['cover'],
], $tracks), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title><?= h($page_title) ?></title>
    <meta name="color-scheme" content="light dark">
    <link rel="stylesheet" href="<?= h($asset('style.css')) ?>">
    <script src="<?= h($asset('player.js')) ?>" defer></script>
</head>
<body>

<svg xmlns="http://www.w3.org/2000/svg" style="display:none">
    <symbol id="i-play" viewBox="0 0 24 24"><path d="M8 5.14v13.72a1 1 0 0 0 1.5.86l11-6.86a1 1 0 0 0 0-1.72l-11-6.86A1 1 0 0 0 8 5.14z"/></symbol>
    <symbol id="i-pause" viewBox="0 0 24 24"><rect x="6" y="4.5" width="4" height="15" rx="1.2"/><rect x="14" y="4.5" width="4" height="15" rx="1.2"/></symbol>
    <symbol id="i-prev" viewBox="0 0 24 24"><rect x="4" y="5" width="2.6" height="14" rx="1"/><path d="M19.5 5.9v12.2a1 1 0 0 1-1.53.85L9.2 13.05a1.2 1.2 0 0 1 0-2.1l8.77-5.9a1 1 0 0 1 1.53.85z"/></symbol>
    <symbol id="i-next" viewBox="0 0 24 24"><rect x="17.4" y="5" width="2.6" height="14" rx="1"/><path d="M4.5 5.9v12.2a1 1 0 0 0 1.53.85l8.77-5.9a1.2 1.2 0 0 0 0-2.1L6.03 5.05A1 1 0 0 0 4.5 5.9z"/></symbol>
    <symbol id="i-shuffle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 3h5v5M4 20 21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/></symbol>
    <symbol id="i-repeat" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14"/><path d="m7 22-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/></symbol>
    <symbol id="i-vol" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4V5z" fill="currentColor"/><path d="M15.5 8.5a5 5 0 0 1 0 7M19 5a10 10 0 0 1 0 14"/></symbol>
    <symbol id="i-mute" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 5 6 9H2v6h4l5 4V5z" fill="currentColor"/><path d="m22 9-6 6M16 9l6 6"/></symbol>
    <symbol id="i-note" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></symbol>
    <symbol id="i-search" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></symbol>
</svg>

<div class="app">

    <!-- ===== Player (toujours visible) ===== -->
    <section class="player" id="player" aria-label="Lecteur">
        <h1 class="player-title"><?= h($player_title) ?></h1>

        <div class="now">
            <div class="now-cover" id="nowCover">
                <svg class="ph" aria-hidden="true"><use href="#i-note"/></svg>
            </div>
            <div class="now-meta" aria-live="polite">
                <div class="now-title" id="nowTitle"><?= $tracks ? 'Choisissez une piste' : 'Aucune piste' ?></div>
                <div class="now-artist" id="nowArtist"></div>
                <div class="now-album" id="nowAlbum"></div>
            </div>
        </div>

        <div class="seek">
            <input type="range" id="seek" min="0" max="0" step="0.1" value="0" aria-label="Position de lecture">
            <div class="times"><span id="curTime">0:00</span><span id="durTime">0:00</span></div>
        </div>

        <div class="controls">
            <button type="button" class="btn btn-sm" id="shuffleBtn" title="Lecture aléatoire" aria-pressed="false">
                <svg><use href="#i-shuffle"/></svg>
            </button>
            <button type="button" class="btn" id="prevBtn" title="Précédent (P)"><svg><use href="#i-prev"/></svg></button>
            <button type="button" class="btn btn-play" id="playBtn" title="Lecture / Pause (Espace)" aria-label="Lecture">
                <svg><use href="#i-play"/></svg>
            </button>
            <button type="button" class="btn" id="nextBtn" title="Suivant (N)"><svg><use href="#i-next"/></svg></button>
            <button type="button" class="btn btn-sm" id="repeatBtn" title="Répéter : non" data-mode="off">
                <svg><use href="#i-repeat"/></svg><span class="badge">1</span>
            </button>
        </div>

        <div class="volume">
            <button type="button" class="btn btn-sm" id="muteBtn" title="Muet (M)"><svg><use href="#i-vol"/></svg></button>
            <input type="range" id="volume" min="0" max="1" step="0.01" value="1" aria-label="Volume">
        </div>

        <audio id="audio" preload="metadata"></audio>
    </section>

    <!-- ===== Playlist (défile séparément) ===== -->
    <section class="list" aria-label="Playlist">
        <div class="list-head">
            <label class="search">
                <svg aria-hidden="true"><use href="#i-search"/></svg>
                <input type="search" id="filter" placeholder="Rechercher un titre, un artiste, un album…" autocomplete="off">
            </label>
            <div class="list-info" id="listInfo">
                <?= count($tracks) ?> piste<?= count($tracks) > 1 ? 's' : '' ?> · <?= fmt_time($total) ?>
            </div>
        </div>

        <ol class="playlist" id="playlist">
            <?php foreach ($tracks as $i => $t): ?>
            <li class="track" data-i="<?= $i ?>">
                <button type="button" class="track-btn">
                    <span class="track-cover">
                        <?php if ($t['cover']): ?>
                            <img src="<?= h($t['cover']) ?>" alt="" loading="lazy" decoding="async" width="56" height="56">
                        <?php else: ?>
                            <svg class="ph" aria-hidden="true"><use href="#i-note"/></svg>
                        <?php endif; ?>
                        <span class="eq" aria-hidden="true"><i></i><i></i><i></i></span>
                    </span>
                    <span class="track-info">
                        <strong><?= h($t['title']) ?></strong>
                        <small><?= h($t['artist'] ?: 'Artiste non renseigné') ?><?= $t['album'] !== '' ? ' · <em>' . h($t['album']) . '</em>' : '' ?></small>
                    </span>
                    <span class="track-dur"><?= $t['duration'] ? fmt_time($t['duration']) : '' ?></span>
                </button>
            </li>
            <?php endforeach; ?>
        </ol>

        <?php if (!$tracks): ?>
            <p class="empty">Aucun fichier audio trouvé dans <code><?= h($cfg['audio_dir']) ?></code>.</p>
        <?php endif; ?>
        <p class="empty" id="noMatch" hidden>Aucun résultat.</p>
    </section>
</div>

<script type="application/json" id="tracks-data"><?= $json ?></script>
</body>
</html>
