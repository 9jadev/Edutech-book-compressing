<?php

namespace App\Http\Controllers;

use App\Services\ThreeDPageGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ThreeDStudioController extends Controller
{
    public function __construct(protected ThreeDPageGeneratorService $generatorService)
    {
        //
    }

    /**
     * Render the 3D Web & Diorama Studio UI.
     */
    public function index(Request $request): Response
    {
        @set_time_limit(120);

        $initialTopic = $request->query('topic', 'Harmonic Oscillator');
        $initialData = $this->generatorService->generate((string) $initialTopic);

        return Inertia::render('ThreeDStudio', [
            'initialData' => $initialData,
            'initialTopic' => $initialTopic,
            'defaultProvider' => config('ai.default', 'gemini'),
            'availableProviders' => [
                ['id' => 'gemini', 'name' => 'Google Gemini', 'models' => ['gemini-3.5-flash-lite', 'gemini-3.5-flash']],
                ['id' => 'deepseek', 'name' => 'DeepSeek', 'models' => ['deepseek-chat', 'deepseek-reasoner']],
                ['id' => 'openai', 'name' => 'OpenAI', 'models' => ['gpt-4o', 'gpt-4o-mini']],
                ['id' => 'anthropic', 'name' => 'Anthropic Claude', 'models' => ['claude-3-7-sonnet', 'claude-3-5-haiku']],
                ['id' => 'groq', 'name' => 'Groq (Llama 3.3)', 'models' => ['llama-3.3-70b-versatile']],
                ['id' => 'ollama', 'name' => 'Ollama (Local)', 'models' => ['llama3.2', 'qwen2.5-coder']],
            ],
            'sampleTopics' => [
                [
                    'title' => 'Harmonic Oscillator',
                    'category' => 'Classical Mechanics',
                    'prompt' => 'Mass-spring-damper oscillator with Hooke force, damping attenuation, and energy conservation',
                    'icon' => 'activity',
                ],
                [
                    'title' => 'Geometric Lens Optics',
                    'category' => 'Optics & Wave Physics',
                    'prompt' => 'Thin lens ray optics with real and virtual focal points, image refraction, and magnification calculations',
                    'icon' => 'eye',
                ],
                [
                    'title' => 'Solar System Mechanics',
                    'category' => 'Gravitation & Astronomy',
                    'prompt' => 'Sun orb with ringed gas giant and orbiting lunar satellites demonstrating Kepler orbital velocities',
                    'icon' => 'sun',
                ],
                [
                    'title' => 'Quantum Particle Box',
                    'category' => 'Quantum Mechanics',
                    'prompt' => '1D infinite potential well with stationary wave probability densities and discrete energy levels',
                    'icon' => 'cpu',
                ],
                [
                    'title' => 'Cyberpunk Neon City',
                    'category' => 'Sci-Fi Architecture',
                    'prompt' => 'Dense futuristic cyberpunk rooftop diorama with glowing neon skyscrapers, skybridges, and hovering drones',
                    'icon' => 'building',
                ],
                [
                    'title' => 'Mars Research Outpost',
                    'category' => 'Space Exploration',
                    'prompt' => 'Pressurized biosphere colony on red Martian regolith with solar arrays, geodesic domes, and communications dish',
                    'icon' => 'globe',
                ],
            ],
        ]);
    }

    /**
     * Generate a new 3D spatial scene via the AI agent.
     */
    public function generate(Request $request): JsonResponse
    {
        @set_time_limit(180);

        $validated = $request->validate([
            'topic' => 'required|string|min:2|max:300',
            'provider' => 'nullable|string',
            'api_key' => 'nullable|string',
        ]);

        try {
            $result = $this->generatorService->generate(
                $validated['topic'],
                $validated['provider'] ?? null,
                $validated['api_key'] ?? null
            );

            return response()->json([
                'success' => true,
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            Log::error('ThreeDStudioController generate error: '.$e->getMessage(), [
                'topic' => $validated['topic'],
                'exception' => $e,
            ]);

            $fallback = $this->generatorService->getCuratedFallback(
                Str::lower($validated['topic']),
                $validated['topic'],
                'AI network connection interrupted. Loaded resilient scientific simulation model.'
            );

            return response()->json([
                'success' => true,
                'data' => $fallback,
            ]);
        }
    }
}
