<?php

namespace App\Http\Controllers;

use App\Services\SvgGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SvgStudioController extends Controller
{
    public function __construct(protected SvgGeneratorService $generatorService)
    {
        //
    }

    /**
     * Render the educational SVG generator UI.
     */
    public function index(Request $request): Response
    {
        // Pre-generate initial showcase illustration ("Photosynthesis") so UI lands on a stunning view
        $initialTopic = $request->query('topic', 'Photosynthesis');
        $initialData = $this->generatorService->generate((string) $initialTopic);

        return Inertia::render('SvgStudio', [
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
                    'title' => 'Photosynthesis',
                    'category' => 'Cellular Biology',
                    'prompt' => 'Photosynthesis: Light-dependent reactions and Calvin cycle inside the chloroplast',
                    'icon' => 'leaf',
                ],
                [
                    'title' => 'Completing the Square',
                    'category' => 'Algebra & Geometry',
                    'prompt' => 'Completing the square method of solving quadratic equations with geometric area proof',
                    'icon' => 'grid',
                ],
                [
                    'title' => 'Laplace Transform',
                    'category' => 'Signals & Control Theory',
                    'prompt' => 'The Laplace transform: Continuous time differential signals mapped to the complex frequency s-plane',
                    'icon' => 'activity',
                ],
                [
                    'title' => 'Neural Network Layers',
                    'category' => 'Artificial Intelligence',
                    'prompt' => 'Deep neural network architecture showing input, dense hidden layers, weights, activation, and output',
                    'icon' => 'brain',
                ],
                [
                    'title' => 'CRISPR-Cas9 Mechanism',
                    'category' => 'Biotechnology',
                    'prompt' => 'CRISPR-Cas9 gene editing mechanism: Guide RNA recognition and DNA double-strand cleavage',
                    'icon' => 'dna',
                ],
            ],
        ]);
    }

    /**
     * Generate an SVG illustration for the requested topic.
     */
    public function generate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'topic' => 'required|string|min:2|max:300',
            'provider' => 'nullable|string',
            'api_key' => 'nullable|string',
        ]);

        $result = $this->generatorService->generate(
            $validated['topic'],
            $validated['provider'] ?? null,
            $validated['api_key'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }
}
