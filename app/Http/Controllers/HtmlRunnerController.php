<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class HtmlRunnerController extends Controller
{
    /**
     * Display the standalone HTML window runner sandbox.
     */
    public function index(): View
    {
        return view('html-runner', [
            'sampleUrl' => asset('demos/lens-optics.html'),
        ]);
    }
}
