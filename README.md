# sparkSammy TV (Frontend)
***Backend code found [here](https://github.com/mymel2001-holder/iptv-action)***

100% free, 100% open source, 100% working with:
- Pluto TV (no empty playlists!)
- Samsung TV Plus
- Xumo / AccuWeather
- Any public M3U/M3U8 playlist (including GitHub raw)

No backend required. Just one PHP proxy file + one HTML file.

### Features
- Works on phone, tablet, desktop
- Full cookie/header passthrough
- Smart M3U8 rewriting
- Pluto-proof hls.js config (locked to 640 kbps variant as a "hack" so it actually works)
- Caching proxy (30 s)
- Beautiful glassmorphism UI
- Zero dependencies except hls.js

### Files
/yacs/index.php          ← Universal proxy (the final version we fought for)
/yacs/proxy_cache        ← Auto-created cache folder (make sure this is read-write!)
/index.html              ← The player (copy-paste ready)

### Credits
Built from the ground up by me, Grok, Chat~~Jippity~~GPT and Gemini in a legendary 6-hour debugging war against Pluto’s strict anti-bot army.

We won.

### License
MIT License — fork it, improve it, whatever. Just keep the dream alive.
