<?php
session_start();

// ============================================================
// Eigene ID3v2-Cover-Klasse (ohne externe Bibliothek)
// ============================================================
class MP3CoverReader {
    private string $filePath;

    public function __construct(string $filePath) {
        $this->filePath = $filePath;
    }

    public function getTags(): array {
        $result = [
            'title'    => basename($this->filePath, '.mp3'),
            'artist'   => 'Unbekannt',
            'album'    => 'Unbekannt',
            'cover'    => null,
            'duration' => '',
        ];

        if (!file_exists($this->filePath)) return $result;
        $fh = fopen($this->filePath, 'rb');
        if (!$fh) return $result;

        $header = fread($fh, 10);
        if (strlen($header) < 10 || substr($header, 0, 3) !== 'ID3') {
            fclose($fh);
            return $result;
        }

        $version   = ord($header[3]);
        $flags     = ord($header[5]);
        $sizeBytes = substr($header, 6, 4);
        $tagSize   = 0;
        for ($i = 0; $i < 4; $i++) $tagSize = ($tagSize << 7) | (ord($sizeBytes[$i]) & 0x7F);

        if ($flags & 0x40) {
            $extHeader = fread($fh, 4);
            $extSize   = 0;
            for ($i = 0; $i < 4; $i++) $extSize = ($extSize << 7) | (ord($extHeader[$i]) & 0x7F);
            fseek($fh, $extSize - 4, SEEK_CUR);
        }

        $endPos = 10 + $tagSize;
        $pos    = ftell($fh);

        while ($pos < $endPos - 10) {
            if ($version >= 3) {
                $fh2 = fread($fh, 10);
                if (strlen($fh2) < 10) break;
                $frameId   = substr($fh2, 0, 4);
                $frameSize = unpack('N', substr($fh2, 4, 4))[1];
            } else {
                $fh2 = fread($fh, 6);
                if (strlen($fh2) < 6) break;
                $frameId   = substr($fh2, 0, 3);
                $b         = array_values(unpack('C3', substr($fh2, 3, 3)));
                $frameSize = ($b[0] << 16) | ($b[1] << 8) | $b[2];
            }

            if ($frameSize <= 0 || $frameId === "\x00\x00\x00\x00") break;
            $data = fread($fh, $frameSize);

            $textFrames = ['TIT2'=>'title','TPE1'=>'artist','TALB'=>'album',
                           'TT2' =>'title','TP1' =>'artist','TAL' =>'album'];

            if (isset($textFrames[$frameId])) {
                $enc  = ord($data[0]);
                $text = substr($data, 1);
                $text = ($enc === 1 || $enc === 2)
                    ? mb_convert_encoding($text, 'UTF-8', 'UTF-16')
                    : mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1');
                $result[$textFrames[$frameId]] = trim($text, "\x00");
            }

            if ($frameId === 'APIC' || $frameId === 'PIC') {
                $enc     = ord($data[0]);
                $rest    = substr($data, 1);
                $nullPos = strpos($rest, "\x00");
                $mime    = substr($rest, 0, $nullPos);
                $rest    = substr($rest, $nullPos + 2); // skip mime + picture type byte
                if ($enc === 1 || $enc === 2) {
                    $nullPos = strpos($rest, "\x00\x00");
                    $rest    = substr($rest, $nullPos !== false ? $nullPos + 2 : 1);
                } else {
                    $nullPos = strpos($rest, "\x00");
                    $rest    = substr($rest, $nullPos !== false ? $nullPos + 1 : 1);
                }
                if (!empty($mime) && strlen($rest) > 0) {
                    $result['cover'] = 'data:'.$mime.';base64,'.base64_encode($rest);
                }
            }

            $pos = ftell($fh);
        }
        fclose($fh);
        return $result;
    }
}

// ============================================================
// Hilfsfunktionen
// ============================================================
function getAudioFiles(string $dir, array $extensions): array {
    $files = [];
    if (!is_dir($dir)) return $files;
    foreach ($extensions as $ext) {
        foreach (glob($dir . '*.' . $ext) ?: [] as $fp) {
            $reader = new MP3CoverReader($fp);
            $files[] = ['path' => $fp, 'tags' => $reader->getTags()];
        }
    }
    return $files;
}

// ============================================================
// Konfiguration & Session
// ============================================================
$audioDir          = 'audio/';
$originalFiles     = getAudioFiles($audioDir, ['mp3']);

if (!isset($_SESSION['playlist'])) {
    $_SESSION['playlist']      = $originalFiles;
    $_SESSION['current_index'] = 0;
    $_SESSION['shuffle']       = false;
    $_SESSION['repeat']        = false;
}
if (count($_SESSION['playlist']) !== count($originalFiles)) {
    $_SESSION['playlist'] = $originalFiles;
}

// Kommandos
if (isset($_GET['command'])) {
    $pl    = &$_SESSION['playlist'];
    $idx   = &$_SESSION['current_index'];
    $cnt   = count($pl);

    switch ($_GET['command']) {
        case 'shuffle':
            $_SESSION['shuffle'] = !$_SESSION['shuffle'];
            $cp = $pl[$idx]['path'];
            if ($_SESSION['shuffle']) { shuffle($pl); }
            else { $_SESSION['playlist'] = $originalFiles; }
            foreach ($_SESSION['playlist'] as $i => $f) {
                if ($f['path'] === $cp) { $idx = $i; break; }
            }
            break;
        case 'repeat':
            $_SESSION['repeat'] = !$_SESSION['repeat'];
            break;
        case 'prev':
            $idx = ($idx - 1 + $cnt) % $cnt;
            break;
        case 'next':
            $idx = ($idx + 1) % $cnt;
            break;
        case 'play':
            $ri = (int)($_GET['index'] ?? 0);
            if ($ri >= 0 && $ri < $cnt) $idx = $ri;
            break;
    }
}

$playlist    = $_SESSION['playlist'];
$count       = count($playlist);
$idx         = $_SESSION['current_index'];
$currentFile = $playlist[$idx]['path'] ?? '';
$currentTags = $playlist[$idx]['tags'] ?? [];
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($currentTags['title'] ?? 'Player') ?> — ILLUSION</title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;700;800&family=Syne+Mono&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

:root {
    --bg:      #080810;
    --s1:      rgba(255,255,255,0.03);
    --s2:      rgba(255,255,255,0.07);
    --border:  rgba(255,255,255,0.08);
    --text:    #ddddef;
    --muted:   rgba(221,221,239,0.38);
    --accent:  #b8ff57;
    --hot:     #ff4fae;
    --mono:    'Syne Mono', monospace;
    --sans:    'Syne', sans-serif;
}

body {
    font-family: var(--mono);
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    overflow-x: hidden;
}

/* ── Animated BG ─────────────────────────── */
.bg-wrap {
    position: fixed;
    inset: 0;
    z-index: 0;
    overflow: hidden;
}
.bg-img {
    position: absolute;
    inset: -60px;
    background-size: cover;
    background-position: center;
    filter: blur(70px) brightness(0.14) saturate(2.5);
    transition: opacity 1.2s ease;
    opacity: 0;
}
.bg-img.active { opacity: 1; }
.bg-grain {
    position: absolute;
    inset: 0;
    background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.75' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.045'/%3E%3C/svg%3E");
    pointer-events: none;
}
.bg-vignette {
    position: absolute;
    inset: 0;
    background: radial-gradient(ellipse at center, transparent 30%, rgba(8,8,16,0.85) 100%);
}

/* ── Layout ──────────────────────────────── */
.layout {
    position: relative;
    z-index: 1;
    display: grid;
    grid-template-columns: 420px 1fr;
    min-height: 100vh;
}

/* ── Player Panel ────────────────────────── */
.player-panel {
    padding: 2.5rem 2rem 2rem;
    display: flex;
    flex-direction: column;
    gap: 1.8rem;
    border-right: 1px solid var(--border);
    background: rgba(8,8,16,0.75);
    backdrop-filter: blur(24px);
}

.logo {
    font-family: var(--sans);
    font-weight: 800;
    font-size: 0.6rem;
    letter-spacing: 0.45em;
    text-transform: uppercase;
    color: var(--muted);
}
.logo em { color: var(--accent); font-style: normal; }

/* ── Disc ────────────────────────────────── */
.disc-scene {
    display: flex;
    justify-content: center;
    align-items: center;
    position: relative;
    height: 280px;
}
/* Glow ring behind disc */
.disc-scene::before {
    content: '';
    position: absolute;
    width: 220px;
    height: 220px;
    border-radius: 50%;
    background: var(--accent);
    filter: blur(55px);
    opacity: 0;
    transition: opacity 0.8s;
    z-index: 0;
}
.disc-scene.playing::before { opacity: 0.08; }

.disc {
    position: relative;
    width: 240px;
    height: 240px;
    border-radius: 50%;
    overflow: hidden;
    box-shadow:
        0 0 0 4px rgba(255,255,255,0.06),
        0 20px 60px rgba(0,0,0,0.7),
        inset 0 0 0 32px rgba(0,0,0,0.55);
    animation: spin 12s linear infinite;
    animation-play-state: paused;
    z-index: 1;
    transform-origin: center;
    flex-shrink: 0;
}
.disc.playing { animation-play-state: running; }

@keyframes spin {
    to { transform: rotate(360deg); }
}

.disc-cover {
    width: 100%;
    height: 100%;
    object-fit: cover;
    border-radius: 50%;
    display: block;
}
.disc-no-cover {
    width: 100%;
    height: 100%;
    background: conic-gradient(from 0deg, #1a1a2e, #16213e, #0f3460, #1a1a2e);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3.5rem;
}
/* Center spindle hole */
.disc-hole {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
}
.disc-hole::after {
    content: '';
    width: 22px;
    height: 22px;
    border-radius: 50%;
    background: var(--bg);
    border: 2px solid rgba(255,255,255,0.15);
    box-shadow: 0 2px 8px rgba(0,0,0,0.8);
}
/* Tone arm */
.tone-arm {
    position: absolute;
    right: 14px;
    top: 10px;
    width: 90px;
    height: 4px;
    background: linear-gradient(90deg, rgba(255,255,255,0.7), rgba(255,255,255,0.2));
    border-radius: 2px;
    transform-origin: right center;
    transform: rotate(-28deg);
    transition: transform 0.8s cubic-bezier(0.34,1.56,0.64,1);
    z-index: 2;
    box-shadow: 0 2px 8px rgba(0,0,0,0.5);
}
.tone-arm::before {
    content: '';
    position: absolute;
    right: -6px;
    top: -6px;
    width: 16px;
    height: 16px;
    border-radius: 50%;
    background: rgba(255,255,255,0.5);
    border: 2px solid rgba(255,255,255,0.8);
}
.tone-arm::after {
    content: '';
    position: absolute;
    left: -4px;
    top: -3px;
    width: 10px;
    height: 10px;
    background: var(--accent);
    border-radius: 1px;
    transform: rotate(40deg);
}
.disc-scene.playing .tone-arm { transform: rotate(-18deg); }

/* ── Track Info ──────────────────────────── */
.track-info {
    text-align: center;
    overflow: hidden;
}
.track-title {
    font-family: var(--sans);
    font-size: 1.1rem;
    font-weight: 700;
    margin-bottom: 0.3rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
/* Marquee for long titles */
.track-title.long {
    animation: marquee 10s linear infinite;
    display: inline-block;
    white-space: nowrap;
}
@keyframes marquee {
    0%,15%   { transform: translateX(0); }
    85%,100% { transform: translateX(-50%); }
}
.track-artist {
    font-size: 0.72rem;
    color: var(--muted);
    letter-spacing: 0.08em;
}
.track-artist strong { color: var(--hot); font-weight: 400; }

/* ── Waveform Canvas ─────────────────────── */
.visualizer-wrap {
    position: relative;
    height: 52px;
    border-radius: 6px;
    overflow: hidden;
    background: var(--s1);
    border: 1px solid var(--border);
}
#waveCanvas {
    width: 100%;
    height: 100%;
    display: block;
}

/* ── Seek Bar (native hidden, custom) ────── */
.seek-row {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    font-size: 0.65rem;
    color: var(--muted);
}
.seek-bar {
    flex: 1;
    -webkit-appearance: none;
    appearance: none;
    height: 3px;
    border-radius: 3px;
    background: var(--border);
    cursor: pointer;
    accent-color: var(--accent);
    transition: height 0.15s;
}
.seek-bar:hover { height: 5px; }
.seek-bar::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: var(--accent);
    cursor: pointer;
    box-shadow: 0 0 8px var(--accent);
}

/* ── Volume Row ──────────────────────────── */
.vol-row {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    font-size: 0.8rem;
    color: var(--muted);
}
.vol-row input[type=range] {
    flex: 1;
    -webkit-appearance: none;
    height: 3px;
    border-radius: 3px;
    background: var(--border);
    accent-color: var(--accent);
    cursor: pointer;
}
.vol-row input[type=range]::-webkit-slider-thumb {
    -webkit-appearance: none;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: var(--accent);
    cursor: pointer;
}

/* ── SVG Control Buttons ─────────────────── */
.controls {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.8rem;
}
.cbtn {
    background: none;
    border: none;
    cursor: pointer;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    transition: transform 0.15s, filter 0.15s;
    color: var(--text);
    text-decoration: none;
    position: relative;
}
.cbtn:hover { transform: scale(1.12); filter: brightness(1.3); }
.cbtn:active { transform: scale(0.95); }

.cbtn svg { display: block; }

.cbtn.small svg  { width: 26px; height: 26px; }
.cbtn.medium svg { width: 34px; height: 34px; }

/* Play button special */
.cbtn.play-btn {
    background: var(--accent);
    color: #080810;
    width: 68px;
    height: 68px;
    border-radius: 50%;
    box-shadow: 0 0 0 0 rgba(184,255,87,0.4);
}
.cbtn.play-btn svg { width: 30px; height: 30px; }
.cbtn.play-btn.playing {
    animation: pulse-ring 1.8s cubic-bezier(0.215, 0.61, 0.355, 1) infinite;
}
@keyframes pulse-ring {
    0%   { box-shadow: 0 0 0 0    rgba(184,255,87,0.5); }
    70%  { box-shadow: 0 0 0 18px rgba(184,255,87,0);   }
    100% { box-shadow: 0 0 0 0    rgba(184,255,87,0);   }
}
.cbtn.play-btn:hover { transform: scale(1.07); filter: brightness(1.08); }

/* Toggle buttons (shuffle/repeat) */
.cbtn.toggle     { opacity: 0.45; transition: opacity 0.2s, transform 0.15s; }
.cbtn.toggle.on  { opacity: 1; color: var(--accent); }

/* Kbd hints */
.kbd-hints {
    display: flex;
    gap: 0.8rem;
    flex-wrap: wrap;
    font-size: 0.6rem;
    color: var(--muted);
    justify-content: center;
}
.kbd-hints kbd {
    background: var(--s2);
    border: 1px solid var(--border);
    border-radius: 3px;
    padding: 1px 5px;
    font-family: var(--mono);
}

/* ── Playlist Panel ──────────────────────── */
.playlist-panel {
    display: flex;
    flex-direction: column;
    background: rgba(8,8,16,0.5);
    backdrop-filter: blur(12px);
}
.pl-header {
    padding: 1.8rem 2rem 1rem;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.pl-title {
    font-family: var(--sans);
    font-size: 0.6rem;
    font-weight: 700;
    letter-spacing: 0.4em;
    text-transform: uppercase;
    color: var(--muted);
}
.pl-count {
    font-size: 0.65rem;
    color: var(--accent);
    background: rgba(184,255,87,0.1);
    padding: 2px 8px;
    border-radius: 20px;
}

.pl-scroll {
    flex: 1;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: var(--border) transparent;
}
.pl-scroll::-webkit-scrollbar { width: 3px; }
.pl-scroll::-webkit-scrollbar-thumb { background: var(--border); border-radius: 2px; }

.pl-item {
    display: grid;
    grid-template-columns: 2.2rem 52px 1fr auto;
    align-items: center;
    gap: 1rem;
    padding: 0.85rem 2rem;
    border-bottom: 1px solid rgba(255,255,255,0.04);
    cursor: pointer;
    text-decoration: none;
    color: var(--text);
    transition: background 0.15s;
    position: relative;
    animation: fadeIn 0.3s ease both;
}
.pl-item:hover { background: rgba(255,255,255,0.03); }
.pl-item.current {
    background: rgba(184,255,87,0.04);
}
.pl-item.current::before {
    content: '';
    position: absolute;
    left: 0; top: 0; bottom: 0;
    width: 3px;
    background: linear-gradient(to bottom, var(--accent), var(--hot));
    border-radius: 0 2px 2px 0;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(6px); }
    to   { opacity: 1; transform: translateY(0); }
}

.pl-num {
    font-size: 0.68rem;
    color: var(--muted);
    text-align: right;
    font-variant-numeric: tabular-nums;
    line-height: 1;
}
.pl-item.current .pl-num { color: var(--accent); }

/* Equalizer bars for current track */
.eq-bars {
    display: flex;
    align-items: flex-end;
    gap: 2px;
    height: 14px;
}
.eq-bars span {
    display: block;
    width: 3px;
    background: var(--accent);
    border-radius: 1px;
    animation: eqBar 0.5s ease-in-out infinite alternate;
}
.eq-bars span:nth-child(1) { animation-duration: 0.4s; animation-delay: 0s;    }
.eq-bars span:nth-child(2) { animation-duration: 0.6s; animation-delay: 0.1s;  }
.eq-bars span:nth-child(3) { animation-duration: 0.35s; animation-delay: 0.05s; }
@keyframes eqBar {
    from { height: 3px; }
    to   { height: 14px; }
}

.pl-thumb {
    width: 52px;
    height: 52px;
    border-radius: 4px;
    overflow: hidden;
    flex-shrink: 0;
}
.pl-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.pl-thumb-empty {
    width: 52px;
    height: 52px;
    background: var(--s1);
    border: 1px solid var(--border);
    border-radius: 4px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
}

.pl-text { overflow: hidden; }
.pl-name {
    font-family: var(--sans);
    font-size: 0.78rem;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    margin-bottom: 0.2rem;
}
.pl-item.current .pl-name { color: var(--accent); }
.pl-sub {
    font-size: 0.65rem;
    color: var(--muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.pl-dur {
    font-size: 0.65rem;
    color: var(--muted);
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

/* ── Hidden native audio ─────────────────── */
#audioEl { display: none; }

/* ── Empty state ─────────────────────────── */
.empty {
    padding: 4rem 2rem;
    text-align: center;
    color: var(--muted);
}
.empty .icon { font-size: 3rem; margin-bottom: 1rem; display: block; }
.empty p { font-size: 0.78rem; line-height: 2; }

/* ── Responsive ──────────────────────────── */
@media (max-width: 720px) {
    .layout { grid-template-columns: 1fr; }
    .player-panel { border-right: none; border-bottom: 1px solid var(--border); }
    .pl-item { grid-template-columns: 2rem 44px 1fr auto; padding: 0.8rem 1rem; }
}
</style>
</head>
<body>

<!-- Animated background -->
<div class="bg-wrap">
    <div class="bg-img <?= $currentTags['cover'] ? 'active' : '' ?>" id="bgA"
         <?= $currentTags['cover'] ? "style=\"background-image:url('{$currentTags['cover']}')\"" : '' ?>>
    </div>
    <div class="bg-img" id="bgB"></div>
    <div class="bg-grain"></div>
    <div class="bg-vignette"></div>
</div>

<div class="layout">

<!-- ═══ PLAYER PANEL ══════════════════════════════ -->
<div class="player-panel">

    <div class="logo"><em>Illusion</em> // Player</div>

    <?php if (empty($playlist)): ?>
    <div class="empty">
        <span class="icon">📂</span>
        <p>Keine MP3-Dateien in <strong><?= htmlspecialchars($audioDir) ?></strong> gefunden.</p>
    </div>
    <?php else: ?>

    <!-- Disc -->
    <div class="disc-scene" id="discScene">
        <div class="disc" id="disc">
            <?php if ($currentTags['cover']): ?>
                <img src="<?= $currentTags['cover'] ?>" alt="" class="disc-cover" id="discImg">
            <?php else: ?>
                <div class="disc-no-cover" id="discImg">♪</div>
            <?php endif; ?>
            <div class="disc-hole"></div>
        </div>
        <div class="tone-arm" id="toneArm"></div>
    </div>

    <!-- Track info -->
    <div class="track-info">
        <div class="track-title" id="trackTitle"><?= htmlspecialchars($currentTags['title']) ?></div>
        <div class="track-artist">
            <strong id="trackArtist"><?= htmlspecialchars($currentTags['artist']) ?></strong>
            &nbsp;·&nbsp;<span id="trackAlbum"><?= htmlspecialchars($currentTags['album']) ?></span>
        </div>
    </div>

    <!-- Waveform visualizer -->
    <div class="visualizer-wrap">
        <canvas id="waveCanvas"></canvas>
    </div>

    <!-- Seek bar -->
    <div class="seek-row">
        <span id="timeNow">0:00</span>
        <input type="range" class="seek-bar" id="seekBar" value="0" min="0" max="100" step="0.1">
        <span id="timeDur">0:00</span>
    </div>

    <!-- Volume -->
    <div class="vol-row">
        <span id="volIcon">🔊</span>
        <input type="range" id="volBar" min="0" max="1" step="0.01" value="1">
    </div>

    <!-- SVG Controls -->
    <div class="controls">

        <!-- Shuffle -->
        <a href="?command=shuffle" class="cbtn small toggle <?= $_SESSION['shuffle'] ? 'on' : '' ?>" title="Shuffle [S]">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="16 3 21 3 21 8"/>
                <line x1="4" y1="20" x2="21" y2="3"/>
                <polyline points="21 16 21 21 16 21"/>
                <line x1="15" y1="15" x2="21" y2="21"/>
            </svg>
        </a>

        <!-- Prev -->
        <a href="?command=prev" class="cbtn medium" title="Zurück [←]">
            <svg viewBox="0 0 24 24" fill="currentColor">
                <path d="M6 6h2v12H6zm3.5 6 8.5 6V6z"/>
            </svg>
        </a>

        <!-- Play/Pause (JS-controlled) -->
        <button class="cbtn play-btn" id="playBtn" onclick="togglePlay()" title="Play/Pause [Space]">
            <svg id="iconPlay" viewBox="0 0 24 24" fill="currentColor">
                <path d="M8 5v14l11-7z"/>
            </svg>
            <svg id="iconPause" viewBox="0 0 24 24" fill="currentColor" style="display:none">
                <path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/>
            </svg>
        </button>

        <!-- Next -->
        <a href="?command=next" class="cbtn medium" title="Weiter [→]">
            <svg viewBox="0 0 24 24" fill="currentColor">
                <path d="M6 18l8.5-6L6 6v12zm2-8.14L11.03 12 8 14.14V9.86zM16 6h2v12h-2z"/>
            </svg>
        </a>

        <!-- Repeat -->
        <a href="?command=repeat" class="cbtn small toggle <?= $_SESSION['repeat'] ? 'on' : '' ?>" title="Repeat [R]">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="17 1 21 5 17 9"/>
                <path d="M3 11V9a4 4 0 0 1 4-4h14"/>
                <polyline points="7 23 3 19 7 15"/>
                <path d="M21 13v2a4 4 0 0 1-4 4H3"/>
            </svg>
        </a>

    </div>

    <!-- Keyboard hints -->
    <div class="kbd-hints">
        <span><kbd>Space</kbd> Play/Pause</span>
        <span><kbd>←</kbd><kbd>→</kbd> Track</span>
        <span><kbd>S</kbd> Shuffle</span>
        <span><kbd>R</kbd> Repeat</span>
        <span><kbd>M</kbd> Mute</span>
    </div>

    <?php endif; ?>
</div>

<!-- ═══ PLAYLIST PANEL ════════════════════════════ -->
<div class="playlist-panel">
    <div class="pl-header">
        <span class="pl-title">Playlist</span>
        <span class="pl-count"><?= $count ?> Tracks</span>
    </div>

    <div class="pl-scroll">
        <?php if (empty($playlist)): ?>
            <div class="empty"><p>Playlist leer.</p></div>
        <?php else: ?>
            <?php foreach ($playlist as $i => $file): ?>
            <?php $t = $file['tags']; $cur = ($i === $idx); ?>
            <a href="?command=play&index=<?= $i ?>" class="pl-item <?= $cur ? 'current' : '' ?>">

                <span class="pl-num">
                    <?php if ($cur): ?>
                        <span class="eq-bars">
                            <span></span><span></span><span></span>
                        </span>
                    <?php else: ?>
                        <?= $i + 1 ?>
                    <?php endif; ?>
                </span>

                <?php if ($t['cover']): ?>
                    <div class="pl-thumb"><img src="<?= $t['cover'] ?>" alt=""></div>
                <?php else: ?>
                    <div class="pl-thumb-empty">♪</div>
                <?php endif; ?>

                <div class="pl-text">
                    <div class="pl-name"><?= htmlspecialchars($t['title']) ?></div>
                    <div class="pl-sub">
                        <?= htmlspecialchars($t['artist']) ?> · <?= htmlspecialchars($t['album']) ?>
                    </div>
                </div>

                <div class="pl-dur"><?= htmlspecialchars($t['duration'] ?? '') ?></div>
            </a>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

</div><!-- .layout -->

<!-- Hidden audio element -->
<audio id="audioEl" preload="auto">
    <source src="<?= htmlspecialchars($currentFile) ?>" type="audio/mpeg">
</audio>

<script>
// ────────────────────────────────────────────
// Config from PHP
// ────────────────────────────────────────────
const REPEAT_MODE  = <?= json_encode($_SESSION['repeat']) ?>;
const CURRENT_FILE = <?= json_encode(urlencode($currentFile)) ?>;
const COVER_SRC    = <?= json_encode($currentTags['cover'] ?? '') ?>;

// ────────────────────────────────────────────
// Elements
// ────────────────────────────────────────────
const audio      = document.getElementById('audioEl');
const disc       = document.getElementById('disc');
const discScene  = document.getElementById('discScene');
const toneArm    = document.getElementById('toneArm');
const playBtn    = document.getElementById('playBtn');
const iconPlay   = document.getElementById('iconPlay');
const iconPause  = document.getElementById('iconPause');
const seekBar    = document.getElementById('seekBar');
const volBar     = document.getElementById('volBar');
const volIcon    = document.getElementById('volIcon');
const timeNow    = document.getElementById('timeNow');
const timeDur    = document.getElementById('timeDur');
const bgA        = document.getElementById('bgA');
const bgB        = document.getElementById('bgB');
const canvas     = document.getElementById('waveCanvas');
const ctx2d      = canvas.getContext('2d');

// ────────────────────────────────────────────
// Background crossfade
// ────────────────────────────────────────────
function setCover(src) {
    const current = bgA.classList.contains('active') ? bgA : bgB;
    const next    = current === bgA ? bgB : bgA;
    if (src) {
        next.style.backgroundImage = `url('${src}')`;
        next.classList.add('active');
        setTimeout(() => current.classList.remove('active'), 1200);
    }
}
if (COVER_SRC) setCover(COVER_SRC);

// ────────────────────────────────────────────
// Play / Pause UI sync
// ────────────────────────────────────────────
function setPlayState(playing) {
    if (playing) {
        disc.classList.add('playing');
        discScene.classList.add('playing');
        playBtn.classList.add('playing');
        iconPlay.style.display  = 'none';
        iconPause.style.display = 'block';
    } else {
        disc.classList.remove('playing');
        discScene.classList.remove('playing');
        playBtn.classList.remove('playing');
        iconPlay.style.display  = 'block';
        iconPause.style.display = 'none';
    }
}

function togglePlay() {
    audio.paused ? audio.play() : audio.pause();
}

audio.addEventListener('play',  () => setPlayState(true));
audio.addEventListener('pause', () => setPlayState(false));

// ────────────────────────────────────────────
// Seek bar
// ────────────────────────────────────────────
function fmtTime(s) {
    if (!s || isNaN(s)) return '0:00';
    const m = Math.floor(s / 60);
    const sec = Math.floor(s % 60).toString().padStart(2, '0');
    return `${m}:${sec}`;
}

audio.addEventListener('timeupdate', () => {
    if (!audio.duration) return;
    seekBar.value = (audio.currentTime / audio.duration) * 100;
    timeNow.textContent = fmtTime(audio.currentTime);
});
audio.addEventListener('durationchange', () => {
    timeDur.textContent = fmtTime(audio.duration);
});
seekBar.addEventListener('input', () => {
    audio.currentTime = (seekBar.value / 100) * audio.duration;
});

// ────────────────────────────────────────────
// Volume
// ────────────────────────────────────────────
volBar.addEventListener('input', () => {
    audio.volume = volBar.value;
    volIcon.textContent = audio.volume === 0 ? '🔇' : audio.volume < 0.4 ? '🔉' : '🔊';
});

document.addEventListener('keydown', e => {
    if (e.target.tagName === 'INPUT') return;
    switch (e.code) {
        case 'Space': e.preventDefault(); togglePlay(); break;
        case 'ArrowRight': window.location.href = '?command=next'; break;
        case 'ArrowLeft':  window.location.href = '?command=prev'; break;
        case 'KeyS': window.location.href = '?command=shuffle'; break;
        case 'KeyR': window.location.href = '?command=repeat'; break;
        case 'KeyM':
            audio.muted = !audio.muted;
            volIcon.textContent = audio.muted ? '🔇' : '🔊';
            break;
    }
});

// ────────────────────────────────────────────
// Track end
// ────────────────────────────────────────────
audio.addEventListener('ended', () => {
    if (REPEAT_MODE) {
        audio.currentTime = 0;
        audio.play();
    } else {
        window.location.href = '?command=next';
    }
});

// ────────────────────────────────────────────
// Waveform Visualizer (Web Audio API)
// ────────────────────────────────────────────
let analyser, dataArray, audioCtx, source;
let vizActive = false;

function initVisualizer() {
    if (vizActive) return;
    try {
        audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        analyser = audioCtx.createAnalyser();
        analyser.fftSize = 128;
        source = audioCtx.createMediaElementSource(audio);
        source.connect(analyser);
        analyser.connect(audioCtx.destination);
        dataArray = new Uint8Array(analyser.frequencyBinCount);
        vizActive = true;
        drawViz();
    } catch(e) {
        console.warn('Visualizer nicht verfügbar:', e);
    }
}

function resizeCanvas() {
    const wrap = canvas.parentElement;
    canvas.width  = wrap.clientWidth * window.devicePixelRatio;
    canvas.height = wrap.clientHeight * window.devicePixelRatio;
}
resizeCanvas();
window.addEventListener('resize', resizeCanvas);

function drawViz() {
    requestAnimationFrame(drawViz);
    if (!analyser) return;

    analyser.getByteFrequencyData(dataArray);
    const W = canvas.width, H = canvas.height;
    ctx2d.clearRect(0, 0, W, H);

    const bars    = dataArray.length;
    const barW    = W / bars;
    const accentR = 184, accentG = 255, accentB = 87;
    const hotR    = 255, hotG = 79, hotB = 174;

    for (let i = 0; i < bars; i++) {
        const v   = dataArray[i] / 255;
        const bH  = v * H;
        const t   = i / bars;

        const r = Math.round(accentR + t * (hotR - accentR));
        const g = Math.round(accentG + t * (hotG - accentG));
        const b = Math.round(accentB + t * (hotB - accentB));

        ctx2d.fillStyle = `rgba(${r},${g},${b},${0.6 + v * 0.4})`;
        ctx2d.fillRect(i * barW, H - bH, barW - 1, bH);
    }
}

// Idle wave animation (before audio starts)
function drawIdleWave() {
    if (vizActive) return;
    requestAnimationFrame(drawIdleWave);
    const W = canvas.width, H = canvas.height;
    ctx2d.clearRect(0, 0, W, H);
    const t = Date.now() / 1000;
    for (let x = 0; x < W; x++) {
        const v = 0.12 + 0.08 * Math.sin(x / 18 + t * 2) + 0.04 * Math.sin(x / 7 + t * 3.7);
        ctx2d.fillStyle = `rgba(184,255,87,${v * 0.5})`;
        ctx2d.fillRect(x, H * (0.5 - v / 2), 1, H * v);
    }
}
drawIdleWave();

audio.addEventListener('play', () => {
    initVisualizer();
    if (audioCtx && audioCtx.state === 'suspended') audioCtx.resume();
});

// ────────────────────────────────────────────
// Restore position
// ────────────────────────────────────────────
window.addEventListener('load', () => {
    const savedFile = localStorage.getItem('audioFile');
    const savedPos  = parseFloat(localStorage.getItem('audioPos') || '0');
    if (savedFile === CURRENT_FILE && savedPos > 1) {
        audio.addEventListener('canplay', () => {
            audio.currentTime = savedPos;
        }, { once: true });
    }
    audio.play().catch(() => {});
});

audio.addEventListener('timeupdate', () => {
    localStorage.setItem('audioPos',  audio.currentTime);
    localStorage.setItem('audioFile', CURRENT_FILE);
});

// Title marquee for long titles
const titleEl = document.getElementById('trackTitle');
if (titleEl && titleEl.scrollWidth > titleEl.clientWidth + 10) {
    titleEl.classList.add('long');
    titleEl.innerHTML = titleEl.textContent + ' &nbsp;&nbsp;&nbsp; ' + titleEl.textContent;
}
</script>
</body>
</html>
