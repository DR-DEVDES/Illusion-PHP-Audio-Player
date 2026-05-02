# 🎵 Aural-illusion — PHP Audio Player

A self-contained, dependency-free PHP audio player with a custom ID3v2 tag reader, animated vinyl disc, waveform visualizer, and a dark editorial UI.

![PHP](https://img.shields.io/badge/PHP-8.0%2B-777BB4?style=flat-square&logo=php)
![No dependencies](https://img.shields.io/badge/dependencies-none-brightgreen?style=flat-square)
![License](https://img.shields.io/badge/license-MIT-blue?style=flat-square)

---

## ✨ Features

- **Zero external dependencies** — no Composer, no getID3, no npm
- **Custom `MP3CoverReader` class** — reads ID3v2.2 / v2.3 tags directly (title, artist, album, cover art) via binary parsing
- **Animated vinyl disc** — spins while playing, tone arm moves in/out
- **Waveform visualizer** — real-time frequency bars via Web Audio API
- **Blur background crossfade** — album cover blurred behind the UI, fades on track change
- **Playlist** with animated EQ bars for the current track
- **Shuffle & Repeat** — session-based state
- **Custom seek bar & volume slider**
- **Keyboard shortcuts** — Space, ←/→, S, R, M
- **Playback position restore** — via `localStorage`
- **Fully responsive** — works on mobile

---

## 🚀 Setup

### Requirements

- PHP 8.0+ (CLI or web server with PHP)
- A web server (Apache, Nginx, or PHP's built-in server)

### Installation

```bash
git clone https://github.com/YOUR_USERNAME/aural-illusion.git
cd aural-illusion
```

Drop your `.mp3` files into the `audio/` folder:

```
aural-illusion/
├── index.php
├── audio/
│   ├── track01.mp3
│   ├── track02.mp3
│   └── ...
└── README.md
```

Start the PHP built-in server:

```bash
php -S localhost:8000
```

Open [http://localhost:8000](http://localhost:8000) in your browser.

### Apache / Nginx

Just point your document root at the project folder. No `.htaccess` needed.

---

## 🎛 Keyboard Shortcuts

| Key     | Action         |
| ------- | -------------- |
| `Space` | Play / Pause   |
| `←`     | Previous track |
| `→`     | Next track     |
| `S`     | Toggle shuffle |
| `R`     | Toggle repeat  |
| `M`     | Toggle mute    |

---

## 🧠 How the ID3 Reader Works

The `MP3CoverReader` class opens the `.mp3` file in binary mode and manually parses the ID3v2 header:

1. Checks for the `ID3` magic bytes
2. Decodes the **syncsafe integer** tag size
3. Iterates over all frames, reading 10-byte (v2.3) or 6-byte (v2.2) headers
4. Extracts `TIT2` / `TPE1` / `TALB` text frames with UTF-16→UTF-8 conversion
5. Extracts `APIC` / `PIC` frames (cover art), returning them as a `data:image/...;base64,...` URI

No `getID3`, no Composer package — just raw binary reads with `fread()`.

---

## 📁 Project Structure

```
aural-illusion/
├── index.php          # Main player (all-in-one: PHP + HTML + CSS + JS)
├── audio/             # Drop your MP3 files here (gitignored)
├── docs/              # Screenshots / demo assets
└── README.md
```

---

## 🖼 Screenshots

> Add screenshots to `docs/` and link them here.

---

## ⚙️ Configuration

At the top of `index.php`:

```php
$audioDir          = 'audio/';   // Folder to scan for MP3 files
$allowedExtensions = ['mp3'];    // File types to include
```

---

## 🔧 Extending

**Add more formats (e.g. OGG, FLAC):**

```php
$allowedExtensions = ['mp3', 'ogg', 'flac'];
```

Note: ID3 tag reading only works for MP3. For other formats the filename will be used as the title.

**Change the scan directory:**

```php
$audioDir = '/var/music/';
```

---

## 📄 License

MIT — do whatever you want, attribution appreciated.

---

## 🙏 Credits

Built with vanilla PHP, CSS animations, SVG icons, and the Web Audio API.  
No frameworks were harmed in the making of this player.
