<?php

use App\Http\Controllers\HtmlRunnerController;
use App\Http\Controllers\PdfDocumentController;
use App\Http\Controllers\SvgStudioController;
use App\Http\Controllers\ThreeDStudioController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::get('/svg-studio', [SvgStudioController::class, 'index'])->name('svg.studio');
Route::post('/svg-studio/generate', [SvgStudioController::class, 'generate'])->name('svg.generate');

Route::get('/3d-studio', [ThreeDStudioController::class, 'index'])->name('three_d.studio');
Route::post('/3d-studio/generate', [ThreeDStudioController::class, 'generate'])->name('three_d.generate');

Route::get('/tab-demo', [HtmlRunnerController::class, 'index'])->name('html.runner');

Route::get('/tab-demo/preview', function (Request $request) {
    $time = now()->format('Y-m-d H:i:s');
    $title = $request->query('title', 'Server-Rendered HTML Preview');

    return response(<<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>body { font-family: 'Plus Jakarta Sans', sans-serif; }</style>
</head>
<body class="bg-slate-950 text-slate-100 min-h-screen flex items-center justify-center p-6">
    <div class="max-w-lg w-full bg-slate-900 border border-cyan-500/30 rounded-2xl p-8 shadow-2xl shadow-cyan-950/40 text-center">
        <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-cyan-500/10 text-cyan-400 mb-5 border border-cyan-500/20">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
            </svg>
        </div>
        <h1 class="text-2xl font-bold text-white mb-2">{$title}</h1>
        <p class="text-slate-400 text-sm mb-6">
            This page was rendered by Laravel at <span class="text-cyan-400 font-mono">{$time}</span> and loaded inside this new browser tab.
        </p>
        <div class="p-4 rounded-xl bg-slate-950/60 border border-slate-800 text-left mb-6 font-mono text-xs text-slate-300">
            <div><span class="text-slate-500">Route:</span> /tab-demo/preview</div>
            <div><span class="text-slate-500">Target:</span> _blank</div>
            <div><span class="text-slate-500">Status:</span> 200 OK</div>
        </div>
        <button
            onclick="window.close()"
            class="px-5 py-2.5 bg-slate-800 hover:bg-slate-700 text-slate-200 font-medium text-sm rounded-lg transition"
        >
            Close Tab
        </button>
    </div>
</body>
</html>
HTML)->header('Content-Type', 'text/html');
})->name('demo.preview');

Route::get('/tab-demo/lens-optics', function () {
    $filePath = public_path('demos/lens-optics.html');
    if (! file_exists($filePath)) {
        abort(404, 'Optics demo file not found');
    }

    return response(file_get_contents($filePath))->header('Content-Type', 'text/html');
})->name('demo.lens-optics');

Route::get('pdfs', [PdfDocumentController::class, 'index'])->name('pdfs.index');
Route::get('pdfs/library', [PdfDocumentController::class, 'library'])->name('pdfs.library');
Route::post('pdfs', [PdfDocumentController::class, 'store'])->name('pdfs.store');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

Route::middleware(['auth'])->group(function () {
    Route::inertia('setup-character', 'auth/SetupCharacter')->name('character.setup');

    Route::post('setup-character', function (Request $request) {
        $validated = $request->validate([
            'body' => 'required|string',
            'hairstyle' => 'required|string',
            'outfit' => 'required|string',
        ]);

        $request->user()->update([
            'avatar_body' => $validated['body'],
            'avatar_hairstyle' => $validated['hairstyle'],
            'avatar_outfit' => $validated['outfit'],
            'has_setup_character' => true,
        ]);

        return redirect()->route('dashboard');
    });
});

require __DIR__.'/settings.php';
