# Player HTML5 + playlist

## Installation / mise à jour
1. Copier tous les fichiers sur le serveur (remplacer `player.php`, `index.html`, `assets/` et `lib/`).
2. Garder votre `conf/config.inc.php` : il reste compatible. Les nouvelles options
   (`$audio_ext`, `$sort_by`) sont facultatives, voir `conf/config.inc.php.sample`.
3. Le dossier `cache/` doit être accessible en écriture par PHP
   (ex. `chown www-data cache` ou `chmod 775 cache`).
   Sans cela le player fonctionne, mais relit tous les tags à chaque visite.

## Fonctionnement du cache
- Au premier affichage, les tags sont lus (getID3) et les pochettes extraites dans `cache/covers/`.
- Ensuite, seuls les fichiers ajoutés/modifiés sont relus. Les fichiers supprimés sont retirés automatiquement.
- Pour tout forcer à se reconstruire : supprimer `cache/library.json`.
- Une image `cover.jpg` ou `folder.jpg` placée dans le dossier audio sert de pochette par défaut.

## Raccourcis clavier
Espace / K : lecture-pause · ← → (J / L) : -10 s / +10 s · N / P : suivant / précédent ·
M : muet · / : rechercher · Échap : effacer la recherche
