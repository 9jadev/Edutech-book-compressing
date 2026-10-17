<?php

namespace Tests\Feature;

use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ThreeDStudioTest extends TestCase
{
    public function test_3d_studio_page_loads_successfully_with_initial_simulation_data(): void
    {
        $response = $this->get('/3d-studio');

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('ThreeDStudio')
            ->has('initialData')
            ->where('initialTopic', 'Harmonic Oscillator')
            ->has('sampleTopics')
            ->has('availableProviders')
        );
    }

    public function test_3d_studio_generate_endpoint_returns_valid_interactive_html_simulation(): void
    {
        $response = $this->postJson('/3d-studio/generate', [
            'topic' => 'Harmonic Oscillator',
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'title',
                    'description',
                    'html_code',
                    'vue_code',
                    'scene',
                    'agent_metadata',
                ],
            ]);

        $htmlCode = $response->json('data.html_code');
        $this->assertIsString($htmlCode);
        $this->assertStringContainsString('three.min.js', $htmlCode);
        $this->assertStringContainsString('CSS2DRenderer.js', $htmlCode);
    }

    public function test_3d_studio_generate_endpoint_handles_empty_topic_gracefully_with_validation(): void
    {
        $response = $this->postJson('/3d-studio/generate', [
            'topic' => '',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['topic']);
    }
}
