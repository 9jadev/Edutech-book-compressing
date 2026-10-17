<?php

namespace Tests\Unit;

use App\Ai\Agents\SimulationReflectionAgent;
use App\Ai\Agents\ThreeDPageGenerationAgent;
use App\Services\SimulationReflectionService;
use Tests\TestCase;

class SimulationReflectionServiceTest extends TestCase
{
    public function test_perfect_simulation_html_scores_100_percent(): void
    {
        $boilerplatePath = resource_path('skills/3d-page-generator/references/boilerplate.html');
        $this->assertFileExists($boilerplatePath);
        $html = (string) file_get_contents($boilerplatePath);

        $service = new SimulationReflectionService();
        $result = $service->evaluate($html);

        $this->assertTrue($result['passed']);
        $this->assertEquals(100, $result['score']);
        $this->assertEmpty($result['issues']);
        $this->assertEquals(0, $result['critical_failures']);
    }

    public function test_substandard_html_is_detected_with_specific_deficiencies(): void
    {
        // A substandard simulation missing KaTeX, CSS2D pointer-events, and STAGES
        $substandardHtml = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>Basic Rotating Box</title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/renderers/CSS2DRenderer.js"></script>
</head>
<body>
    <div id="canvas-container"></div>
    <div id="css2d-container"></div>
    <script>
        const scene = new THREE.Scene();
        const camera = new THREE.PerspectiveCamera(45, window.innerWidth / window.innerHeight, 0.1, 100);
        const renderer = new THREE.WebGLRenderer();
        const labelRenderer = new THREE.CSS2DRenderer();
        function animate() {
            requestAnimationFrame(animate);
            renderer.render(scene, camera);
            labelRenderer.render(scene, camera);
        }
        animate();
    </script>
</body>
</html>
HTML;

        $service = new SimulationReflectionService();
        $result = $service->evaluate($substandardHtml);

        $this->assertFalse($result['passed']);
        $this->assertLessThan(100, $result['score']);
        $this->assertNotEmpty($result['issues']);
        $this->assertGreaterThan(0, $result['critical_failures']);

        // Check specific missing rules
        $rulesFailed = array_column(array_filter($result['checks'], fn ($c) => ! $c['passed']), 'rule');
        $this->assertContains('katex_math', $rulesFailed);
        $this->assertContains('css2d_pointer_events', $rulesFailed);
        $this->assertContains('registries', $rulesFailed);
    }

    public function test_auto_healer_patches_doctype_and_css2d_pointer_events(): void
    {
        $incompleteHtml = <<<'HTML'
<html>
<head>
    <style>
        body { margin: 0; }
    </style>
</head>
<body>
    <div id="css2d-container"></div>
</body>
</html>
HTML;

        $service = new SimulationReflectionService();
        $eval = $service->evaluate($incompleteHtml);
        $healed = $service->autoHeal($incompleteHtml, $eval);

        $this->assertStringContainsString('<!DOCTYPE html>', $healed);
        $this->assertStringContainsString('pointer-events: none !important', $healed);
        $this->assertStringContainsString('tailwindcss.com', $healed);
        $this->assertStringContainsString('katex.min.js', $healed);
    }

    public function test_raw_math_symbol_p1_without_auto_render_or_dollar_delimiters_fails_correctness_check(): void
    {
        // Simulation containing raw $P_1$ math symbol and isolated katex.render, but missing auto-render with single-dollar delimiters
        $htmlWithRawP1 = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>Thermodynamic Cycle</title>
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/katex.min.css">
    <script src="https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/katex.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/renderers/CSS2DRenderer.js"></script>
    <style>
        #css2d-container { pointer-events: none; }
        .label-badge { pointer-events: auto; }
    </style>
</head>
<body>
    <div id="info">State 1 has initial pressure $P_1$ and volume $V_1$.</div>
    <div id="formula"></div>
    <input type="range" id="slider" min="1" max="10">
    <script>
        const COMPONENTS = { state1: { title: 'State 1', formula: 'P_1 V_1 = nRT_1' } };
        const STAGES = [{ title: 'Compression', narration: 'Compression stage' }];
        const renderer = new THREE.WebGLRenderer();
        const labelRenderer = new THREE.CSS2DRenderer();
        const scene = new THREE.Scene();
        const camera = new THREE.PerspectiveCamera();
        function animate() {
            requestAnimationFrame(animate);
            renderer.render(scene, camera);
            labelRenderer.render(scene, camera);
        }
        animate();
        katex.render('P_1 V_1 = nRT_1', document.getElementById('formula'));
    </script>
</body>
</html>
HTML;

        $service = new SimulationReflectionService();
        $result = $service->evaluate($htmlWithRawP1);

        $mathCheck = current(array_filter($result['checks'], fn ($c) => $c['rule'] === 'math_symbol_rendering'));
        $this->assertNotEmpty($mathCheck);
        $this->assertFalse($mathCheck['passed']);
        $this->assertStringContainsString('$P_1', $mathCheck['message']);
        $this->assertStringContainsString('auto-render', $mathCheck['message']);
    }

    public function test_math_symbols_with_dollar_notation_pass_when_auto_render_and_single_dollar_delimiters_are_present(): void
    {
        $htmlWithConfiguredMath = <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <title>Thermodynamic Cycle</title>
    <script src="https://cdn.tailwindcss.com/3.4.17"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/katex.min.css">
    <script src="https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/katex.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/contrib/auto-render.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/renderers/CSS2DRenderer.js"></script>
    <style>
        #css2d-container { pointer-events: none; }
        .label-badge { pointer-events: auto; }
    </style>
</head>
<body>
    <div id="info">State 1 has initial pressure $P_1$ and volume $V_1$.</div>
    <input type="range" id="slider" min="1" max="10">
    <script>
        const COMPONENTS = { state1: { title: 'State 1', formula: 'P_1 V_1 = nRT_1' } };
        const STAGES = [{ title: 'Compression', narration: 'Compression stage' }];
        const renderer = new THREE.WebGLRenderer();
        const labelRenderer = new THREE.CSS2DRenderer();
        const scene = new THREE.Scene();
        const camera = new THREE.PerspectiveCamera();
        function animate() {
            requestAnimationFrame(animate);
            renderer.render(scene, camera);
            labelRenderer.render(scene, camera);
        }
        animate();
        renderMathInElement(document.body, {
            delimiters: [
                { left: '$$', right: '$$', display: true },
                { left: '$', right: '$', display: false }
            ]
        });
    </script>
</body>
</html>
HTML;

        $service = new SimulationReflectionService();
        $result = $service->evaluate($htmlWithConfiguredMath);

        $mathCheck = current(array_filter($result['checks'], fn ($c) => $c['rule'] === 'math_symbol_rendering'));
        $this->assertNotEmpty($mathCheck);
        $this->assertTrue($mathCheck['passed']);
    }

    public function test_auto_healer_patches_html_containing_raw_p1_to_fully_rendered_math(): void
    {
        $rawHtml = <<<'HTML'
<html>
<head>
    <style>
        body { margin: 0; }
    </style>
</head>
<body>
    <div>Current Pressure: $P_1$ where $P_1 = 101.3\text{ kPa}$</div>
</body>
</html>
HTML;

        $service = new SimulationReflectionService();
        $initialEval = $service->evaluate($rawHtml);
        $this->assertFalse($initialEval['passed']);

        $healed = $service->autoHeal($rawHtml, $initialEval);

        $this->assertStringContainsString('auto-render.min.js', $healed);
        $this->assertStringContainsString('renderDocumentMathematics', $healed);
        $this->assertStringContainsString("left: '$'", $healed);
        $this->assertStringContainsString('MutationObserver', $healed);

        // Verify that math_symbol_rendering rule now passes on the healed HTML
        $healedEval = $service->evaluate($healed);
        $mathCheck = current(array_filter($healedEval['checks'], fn ($c) => $c['rule'] === 'math_symbol_rendering'));
        $this->assertNotEmpty($mathCheck);
        $this->assertTrue($mathCheck['passed']);
    }

    public function test_simulation_reflection_agent_instantiates_with_dedicated_skill(): void
    {
        $agent = SimulationReflectionAgent::make();
        $this->assertInstanceOf(SimulationReflectionAgent::class, $agent);

        $skills = iterator_to_array($agent->skills());
        $this->assertNotEmpty($skills);

        $skillNames = array_map(fn ($s) => $s->name, $skills);
        $this->assertContains('simulation-reflection', $skillNames);

        $reflectionSkill = current(array_filter($skills, fn ($s) => $s->name === 'simulation-reflection'));
        $this->assertStringContainsString('Three.js', $reflectionSkill->description);
        $this->assertStringContainsString('$P_1', $reflectionSkill->description);
    }

    public function test_reflect_and_correct_returns_immediate_100_percent_if_already_compliant(): void
    {
        $boilerplatePath = resource_path('skills/3d-page-generator/references/boilerplate.html');
        $html = (string) file_get_contents($boilerplatePath);

        $service = new SimulationReflectionService();
        $result = $service->reflectAndCorrect('Harmonic Oscillator', $html);

        $this->assertEquals(100, $result['score']);
        $this->assertEquals(0, $result['reflection_iterations']);
        $this->assertTrue($result['evaluation']['passed']);
    }

    public function test_three_d_page_generation_agent_exposes_simulation_reflection_agent_as_tool(): void
    {
        $agent = ThreeDPageGenerationAgent::make();
        $this->assertInstanceOf(ThreeDPageGenerationAgent::class, $agent);

        $tools = iterator_to_array($agent->tools());
        $this->assertNotEmpty($tools);

        $reflectionTool = current(array_filter($tools, fn ($t) => $t instanceof SimulationReflectionAgent));
        $this->assertInstanceOf(SimulationReflectionAgent::class, $reflectionTool);
        $this->assertEquals('simulation_reflection_agent', $reflectionTool->name());
        $this->assertStringContainsString('$P_1', (string) $reflectionTool->description());
    }
}
