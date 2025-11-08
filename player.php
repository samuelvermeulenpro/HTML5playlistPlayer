<?php

//error_reporting(E_ALL);
require_once __DIR__ . '/lib/getid3/getid3.php';
require __DIR__ . '/conf/config.inc.php';

$directory = $audio_dir;
$files = glob($directory . '*.mp3');
$getID3 = new GetID3;

$tracks = [];

foreach ($files as $file) {
    $tags = $getID3->analyze($file);
    getid3_lib::CopyTagsToComments($tags);

    $title = $tags['comments_html']['title'][0] ?? basename($file);
    $artist = $tags['comments_html']['artist'][0] ?? 'Artiste non-renseigné';
    $album = $tags['comments_html']['album'][0] ?? 'Album non-renseigné';
    $cover = null;

    if (!empty($tags['id3v2']['APIC'][0]['data'])) {
        $img_data = $tags['id3v2']['APIC'][0]['data'];
        $mime = $tags['id3v2']['APIC'][0]['image_mime'];
        $cover = 'data:' . $mime . ';base64,' . base64_encode($img_data);
    }

    $tracks[] = [
        'file' => $file,
        'title' => $title,
        'artist' => $artist,
	'album' => $album,
        'cover' => $cover,
    ];
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?></title>
    <link rel="stylesheet" href="assets/style.css" media="all">
</head>
<body>
<div align="center">
<h2><?= $player_title ?></h2>
</div>
<div id="audioContainer">
<table border="0" width="100%">
	<tr>
		<td class="cover-player"><span id="trackcover"></span></td>
		<td><h3><span id="trackinfo">Chargment des pistes, veuillez patienter...</h3></td>
	</tr>
</table>
	<!-- Progress bar -->
        <div class="progressBar" id="progressBar">
                <div class="progressFill" id="progressFill"></div>
        </div>
	<div class="controls">
		<button id="playPauseBtn">▶️</button>
		<div id="trackTime" style="margin: 8px 0; font-weight: bold;">
    		⏱️ <span id="currentTime">00:00</span> / <span id="duration">00:00</span>
		</div>
	</div>

	<audio id="audioPlayer">
    	Votre navigateur ne supporte pas l'élément audio.
	</audio>
</div>
<div id="playlist">
    <?php foreach ($tracks as $index => $track): ?>
        <div class="track" data-src="<?= htmlspecialchars($track['file']) ?>" 
		data-index="<?= $index ?>" data-cover="<?= $track['cover'] ?>" 
		data-artist="<?= htmlspecialchars($track['artist']) ?>" 
		data-album="<?= htmlspecialchars($track['album']) ?>" 
		data-title="<?= htmlspecialchars($track['title']) ?>">
            <?php if ($track['cover']): ?>
                <img class="cover" src="<?= $track['cover'] ?>" alt="Cover">
            <?php else: ?>
                <div class="cover" style="background:#ccc;"></div>
            <?php endif; ?>
            <div class="info">
                <strong><?= $track['title'] ?></strong>
                <small><?= $track['artist'] ?></small>
		<small><?= '<i>('.$track['album'].')</i>' ?></small>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script>
    const player = document.getElementById('audioPlayer');
    const tracks = document.querySelectorAll('.track');
    const playPauseBtn = document.getElementById('playPauseBtn');
    let current = 0;

    function playTrack(index) {
        tracks.forEach(t => t.classList.remove('active'));
        const track = tracks[index];
        track.classList.add('active');
        player.src = track.getAttribute('data-src');
	trackcover.innerHTML = "<img class='cover-player' src='" + track.getAttribute('data-cover') + "'>";
	trackinfo.innerHTML = track.getAttribute('data-artist') + " - " + track.getAttribute('data-title') + "<br />" + "<i>(" + track.getAttribute('data-album') + ")</div>";
	player.play();
        current = index;
    }

    tracks.forEach((track, index) => {
        track.addEventListener('click', () => playTrack(index));
    });

    if (tracks.length > 0) {
        playTrack(0);
    }

    player.addEventListener('ended', () => {
        let next = current + 1;
        if (next < tracks.length) {
            playTrack(next);
        }
    });


// Timing
    const currentTimeEl = document.getElementById('currentTime');
    const durationEl = document.getElementById('duration');
    const progressBar = document.getElementById('progressBar');
    const progressFill = document.getElementById('progressFill');
    let playing = false;

    function formatTime(seconds) {
        const m = Math.floor(seconds / 60);
        const s = Math.floor(seconds % 60);
        return `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
    }
    function updateProgress() {
        const percent = (player.currentTime / player.duration) * 100;
        progressFill.style.width = percent + '%';
        currentTimeEl.textContent = formatTime(player.currentTime);
    }
    // Click pour avancer
    progressBar.addEventListener('click', e => {
        const rect = progressBar.getBoundingClientRect();
        const percent = (e.clientX - rect.left) / rect.width;
        player.currentTime = percent * player.duration;
    });

    // Met à jour la durée une fois que les métadonnées sont chargées
    player.addEventListener('loadedmetadata', () => {
        durationEl.textContent = formatTime(player.duration);
        currentTimeEl.textContent = formatTime(player.currentTime);
    });

    // Met à jour le temps courant pendant la lecture
    player.addEventListener('timeupdate', updateProgress);
    //player.addEventListener('timeupdate', () => {
    //    currentTimeEl.textContent = formatTime(player.currentTime);
    //});

    // Remet à zéro si on change de piste
    player.addEventListener('emptied', () => {
        durationEl.textContent = "00:00";
        currentTimeEl.textContent = "00:00";
    });

    // Controls
    playPauseBtn.addEventListener('click', () => {
        if (player.src === "") {
            alert("Aucune piste chargée.");
            return;
        }

        if (player.paused) {
            player.play();
        } else {
            player.pause();
        }
    });
    player.addEventListener('play', () => {
        playPauseBtn.textContent = '⏸️';
        playing = true;
    });

    player.addEventListener('pause', () => {
        playPauseBtn.textContent = '▶️';
        playing = false;
    });
</script>

</body>
</html>
