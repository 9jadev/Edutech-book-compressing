<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HTML Window Runner — {{ config('app.name', 'GameBoxLive') }}</title>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; }
        .font-code { font-family: 'JetBrains Mono', monospace; }
    </style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex flex-col items-center justify-center p-4 sm:p-8 antialiased">
    <div class="max-w-4xl w-full bg-slate-900/90 border border-slate-800 rounded-2xl shadow-2xl backdrop-blur-xl overflow-hidden flex flex-col">
        <!-- Header -->
        <div class="px-6 py-4 border-b border-slate-800/80 flex items-center justify-between bg-slate-900/50">
            <div class="flex items-center gap-3">
                <div class="w-3 h-3 rounded-full bg-emerald-400 shadow-sm shadow-emerald-400/50 animate-pulse"></div>
                <h1 class="font-bold text-lg text-white tracking-tight">HTML Window Runner</h1>
                <span class="text-xs px-2.5 py-0.5 rounded-full bg-cyan-500/10 text-cyan-400 border border-cyan-500/20 font-medium">Chromeless Mode</span>
            </div>
            
            <div class="flex items-center gap-2">
                <button 
                    type="button" 
                    onclick="loadSample()" 
                    class="text-xs px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 font-medium transition active:scale-95 border border-slate-700/60"
                >
                    Paste Sample (Lens Optics)
                </button>
                <button 
                    type="button" 
                    onclick="clearEditor()" 
                    class="text-xs px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-400 hover:text-red-400 font-medium transition active:scale-95 border border-slate-700/60"
                >
                    Clear
                </button>
            </div>
        </div>

        <!-- Editor Area -->
        <div class="p-6 flex flex-col flex-grow">
            <div class="flex items-center justify-between mb-2">
                <label for="html-input" class="text-xs font-semibold uppercase tracking-wider text-slate-400">
                    Paste your HTML code below
                </label>
                <span class="text-xs text-slate-500">
                    Shortcut: <kbd class="px-1.5 py-0.5 rounded bg-slate-800 border border-slate-700 text-slate-300 font-mono text-[11px]">⌘/Ctrl + Enter</kbd> to launch
                </span>
            </div>

            <textarea 
                id="html-input" 
                rows="16" 
                placeholder="<!DOCTYPE html>&#10;<html>&#10;<head><title>My Window</title></head>&#10;<body>&#10;  <!-- Paste your HTML, CSS, & Three.js/JS here -->&#10;</body>&#10;</html>"
                class="w-full bg-slate-950 border border-slate-800 focus:border-cyan-500 focus:ring-1 focus:ring-cyan-500 rounded-xl p-4 text-slate-200 font-code text-xs leading-relaxed focus:outline-none resize-y transition shadow-inner font-normal"
                spellcheck="false"
            ></textarea>

            <!-- Bottom Action Bar -->
            <div class="mt-5 flex flex-wrap items-center justify-between gap-4 pt-4 border-t border-slate-800/80">
                <!-- Dimension Preset Selector -->
                <div class="flex items-center gap-3 text-xs text-slate-400">
                    <span>Window Size:</span>
                    <select id="window-size" class="bg-slate-800 border border-slate-700 rounded-lg px-2.5 py-1.5 text-slate-200 text-xs focus:outline-none focus:border-cyan-500">
                        <option value="1100x750" selected>1100 × 750 (Recommended for 3D)</option>
                        <option value="1280x800">1280 × 800 (HD Widescreen)</option>
                        <option value="900x650">900 × 650 (Standard)</option>
                        <option value="600x800">600 × 800 (Portrait / Mobile)</option>
                    </select>
                </div>

                <!-- Launch Button -->
                <button 
                    type="button" 
                    onclick="launchHtmlWindow()" 
                    class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-cyan-500 to-blue-600 hover:from-cyan-400 hover:to-blue-500 text-white font-bold text-sm transition active:scale-95 shadow-lg shadow-cyan-500/25 flex items-center gap-2"
                >
                    <span>Launch Window</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <script>
        const editor = document.getElementById('html-input');
        const sampleUrl = @json($sampleUrl);

        // Allow Cmd+Enter or Ctrl+Enter to launch immediately
        document.addEventListener('keydown', (e) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
                launchHtmlWindow();
            }
        });

        function clearEditor() {
            editor.value = '';
            editor.focus();
        }

        // Quick fetch of the Lens Optics demo so you can test with 1 click
        async function loadSample() {
            try {
                const res = await fetch(sampleUrl);
                if (res.ok) {
                    editor.value = await res.text();
                } else {
                    editor.value = `<!DOCTYPE html>\n<html>\n<body style="background:#090d16;color:white;display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;text-align:center;">\n  <div>\n    <h1 style="color:#38bdf8;">🎉 Sample Window</h1>\n    <p style="color:#94a3b8;">Paste any HTML here to test!</p>\n  </div>\n</body>\n</html>`;
                }
            } catch (err) {
                editor.value = `<!DOCTYPE html>\n<html>\n<body style="background:#090d16;color:white;display:flex;align-items:center;justify-content:center;height:100vh;font-family:sans-serif;text-align:center;">\n  <div>\n    <h1 style="color:#38bdf8;">🎉 Sample Window</h1>\n    <p style="color:#94a3b8;">Paste any HTML here to test!</p>\n  </div>\n</body>\n</html>`;
            }
        }

        function launchHtmlWindow() {
            const rawHtml = editor.value.trim();
            if (!rawHtml) {
                alert('Please paste some HTML code first!');
                editor.focus();
                return;
            }

            const [width, height] = document.getElementById('window-size').value.split('x').map(Number);
            const left = Math.round((screen.width - width) / 2);
            const top = Math.round((screen.height - height) / 2);

            const features = [
                `width=${width}`,
                `height=${height}`,
                `left=${left}`,
                `top=${top}`,
                'popup=yes',        // Strips tabs & address bar in modern browsers
                'menubar=no',
                'toolbar=no',
                'location=no',
                'status=no',
                'resizable=yes',
                'scrollbars=yes'
            ].join(',');

            // In-memory Blob so raw HTML runs immediately without server roundtrip
            const blob = new Blob([rawHtml], { type: 'text/html' });
            const blobUrl = URL.createObjectURL(blob);
            const winName = 'RunnerWindow_' + Date.now();

            window.open(blobUrl, winName, features);
        }

        // Preload sample on initial visit if empty
        window.addEventListener('DOMContentLoaded', () => {
            if (!editor.value) {
                loadSample();
            }
        });
    </script>
</body>
</html>
