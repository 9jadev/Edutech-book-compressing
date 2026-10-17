<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed, shallowRef, onMounted, onUnmounted, nextTick } from 'vue';
import { TresCanvas } from '@tresjs/core';
import { OrbitControls, ContactShadows } from '@tresjs/cientos';
import DynamicScene3D from '@/components/World/DynamicScene3D.vue';
import {
    Sparkles,
    Box,
    Layers,
    Eye,
    Code,
    Download,
    Copy,
    Check,
    RotateCcw,
    Sun,
    Moon,
    Grid,
    Shield,
    Building,
    Globe,
    Cpu,
    Mountain,
    Send,
    Settings,
    Maximize2,
    Minimize2,
    Play,
    Pause,
    CheckCircle2,
    Terminal,
    Workflow,
    Clock,
    Palette,
    Sliders,
    MessageSquare,
    ExternalLink,
    Compass,
    Activity,
} from 'lucide-vue-next';

interface MeshItem {
    id: string;
    name: string;
    type: 'box' | 'sphere' | 'cylinder' | 'cone' | 'torus' | 'plane';
    position: [number, number, number];
    rotation?: [number, number, number];
    scale?: [number, number, number];
    args?: any[];
    color: string;
    metalness?: number;
    roughness?: number;
    emissive?: string;
    emissive_intensity?: number;
    flat_shading?: boolean;
    animation?: 'float' | 'spin-y' | 'spin-x' | 'pulse' | 'none';
}

interface SceneConfig {
    camera: { position: [number, number, number]; fov: number };
    background_color: string;
    ambient_light: { color: string; intensity: number };
    directional_light: { color: string; intensity: number; position: [number, number, number] };
    accent_light: { color: string; intensity: number; position: [number, number, number] };
    fog: { color: string; near: number; far: number };
    show_grid: boolean;
    meshes: MeshItem[];
}

interface AgentStep {
    step: number;
    title: string;
    detail: string;
    status: 'pending' | 'running' | 'completed';
}

interface AgentMetadata {
    is_agentic: boolean;
    agent_name: string;
    skill_loaded: string;
    plan: AgentStep[];
    execution_source: string;
    timestamp: string;
    quality_evaluation?: {
        score: number;
        passed: boolean;
        iterations: number;
        checks: Array<{ rule: string; title: string; passed: boolean; severity: string; message: string }>;
        issues?: string[];
    };
}

interface ThreeDResult {
    title: string;
    description: string;
    category: string;
    palette: string[];
    key_features: string[];
    scene: SceneConfig;
    vue_code: string;
    html_code?: string;
    source: string;
    error_notice: string | null;
    agent_metadata?: AgentMetadata;
}

interface ProviderOption {
    id: string;
    name: string;
    models: string[];
}

interface SampleTopic {
    title: string;
    category: string;
    prompt: string;
    icon: string;
}

interface ChatMessage {
    id: string;
    sender: 'user' | 'agent';
    text: string;
    timestamp: Date;
    title?: string;
}

const props = defineProps<{
    initialData: ThreeDResult;
    initialTopic: string;
    defaultProvider: string;
    availableProviders: ProviderOption[];
    sampleTopics: SampleTopic[];
}>();

// State
const query = ref(props.initialTopic || 'Harmonic Oscillator');
const currentData = ref<ThreeDResult>(props.initialData);
const isLoading = ref(false);
const viewMode = ref<'sim' | '3d' | 'code'>('sim');
const codeType = ref<'html' | 'vue'>('html');
const autoRotate = ref(true);
const showGrid = ref(props.initialData.scene.show_grid ?? true);
const lightingPreset = ref<'cinematic' | 'night' | 'day' | 'neon'>('cinematic');
const isFullscreen = ref(false);
const copiedCode = ref(false);
const showSettings = ref(false);
const selectedProvider = ref(props.defaultProvider || 'gemini');
const customApiKey = ref('');
const errorMessage = ref<string | null>(null);

// Simulation Sandbox state
const simulationBlobUrl = ref<string>('');
const simulationContainerRef = ref<HTMLElement | null>(null);

// Sidebar tabs ('plan' | 'chat' | 'inspector')
const activeTab = ref<'plan' | 'chat' | 'inspector'>('plan');

// Interactive Chat
const chatInput = ref('');
const chatMessages = ref<ChatMessage[]>([
    {
        id: 'msg-0',
        sender: 'user',
        text: `Generate 3D spatial scene for: ${props.initialTopic || 'Cyberpunk Neon City'}`,
        timestamp: new Date(),
    },
    {
        id: 'msg-1',
        sender: 'agent',
        text: `Constructed "${props.initialData.title}". Created ${props.initialData.scene.meshes.length} low-poly 3D nodes with PBR materials and idle animation loops.`,
        title: props.initialData.title,
        timestamp: new Date(),
    },
]);

// Agentic Steps Animation
const executionTimer = ref(0);
const currentExecutingStep = ref(1);
let timerInterval: any = null;
let stepInterval: any = null;

const defaultPlan: AgentStep[] = [
    {
        step: 1,
        title: 'Physical Modeling & Governing Equations',
        detail: 'Formulating physical laws, dynamic variables, and simulation limits.',
        status: 'completed',
    },
    {
        step: 2,
        title: 'Interactive Anatomy & Component Registry',
        detail: 'Configuring COMPONENTS registry with KaTeX formulas and SI unit tables.',
        status: 'completed',
    },
    {
        step: 3,
        title: 'Three.js 3D Primitives & CSS2D Label Layer',
        detail: 'Generating raycastable mesh groups and synchronized CSS2D pill badges.',
        status: 'completed',
    },
    {
        step: 4,
        title: 'Guided Play Journey & Web Speech Narration',
        detail: 'Sequencing STAGES array with camera lerping and chunked speech synthesis.',
        status: 'completed',
    },
    {
        step: 5,
        title: 'Dynamic Sliders & Real-Time Physics Loop',
        detail: 'Wiring numerical integration with live parameter controls and readouts.',
        status: 'completed',
    },
    {
        step: 6,
        title: 'Self-Contained Standalone HTML Assembly',
        detail: 'Bundling pinned CDN assets into zero-error interactive simulation document.',
        status: 'completed',
    },
];

const liveSteps = ref<AgentStep[]>(
    props.initialData.agent_metadata?.plan || JSON.parse(JSON.stringify(defaultPlan))
);

// Helper: Get XSRF Token
const getXsrfToken = () => {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
};

// Live Execution Animation
const startStepExecutionAnimation = () => {
    executionTimer.value = 0;
    currentExecutingStep.value = 1;

    liveSteps.value = defaultPlan.map((s, idx) => ({
        ...s,
        status: idx === 0 ? 'running' : 'pending',
    }));

    timerInterval = setInterval(() => {
        executionTimer.value = Math.round((executionTimer.value + 0.1) * 10) / 10;
    }, 100);

    stepInterval = setInterval(() => {
        if (currentExecutingStep.value < 5) {
            liveSteps.value[currentExecutingStep.value - 1].status = 'completed';
            currentExecutingStep.value++;
            liveSteps.value[currentExecutingStep.value - 1].status = 'running';
        }
    }, 650);
};

const stopStepExecutionAnimation = (finalSteps?: AgentStep[]) => {
    if (timerInterval) clearInterval(timerInterval);
    if (stepInterval) clearInterval(stepInterval);

    if (finalSteps && finalSteps.length) {
        liveSteps.value = finalSteps.map((s) => ({ ...s, status: 'completed' }));
    } else {
        liveSteps.value = liveSteps.value.map((s) => ({ ...s, status: 'completed' }));
    }
    currentExecutingStep.value = 6;
};

// Simulation Sandbox Management
const updateSimulationSrc = () => {
    if (simulationBlobUrl.value) {
        URL.revokeObjectURL(simulationBlobUrl.value);
    }
    const htmlToRender = currentData.value.html_code || currentData.value.vue_code;
    if (htmlToRender) {
        const blob = new Blob([htmlToRender], { type: 'text/html' });
        simulationBlobUrl.value = URL.createObjectURL(blob);
    } else {
        simulationBlobUrl.value = '';
    }
};

const reloadSimulation = () => {
    updateSimulationSrc();
};

const openInNewTab = () => {
    const htmlToRender = currentData.value.html_code || currentData.value.vue_code;
    if (htmlToRender) {
        const blob = new Blob([htmlToRender], { type: 'text/html' });
        const url = URL.createObjectURL(blob);
        window.open(url, '_blank');
    }
};

const toggleFullscreen = () => {
    if (!simulationContainerRef.value) return;
    if (!document.fullscreenElement) {
        simulationContainerRef.value.requestFullscreen().catch((err) => {
            console.error('Fullscreen request failed:', err);
        });
        isFullscreen.value = true;
    } else {
        document.exitFullscreen().catch(() => {});
        isFullscreen.value = false;
    }
};

const handleFullscreenChange = () => {
    isFullscreen.value = !!document.fullscreenElement;
};

onMounted(() => {
    updateSimulationSrc();
    document.addEventListener('fullscreenchange', handleFullscreenChange);
});

onUnmounted(() => {
    if (timerInterval) clearInterval(timerInterval);
    if (stepInterval) clearInterval(stepInterval);
    if (simulationBlobUrl.value) {
        URL.revokeObjectURL(simulationBlobUrl.value);
    }
    document.removeEventListener('fullscreenchange', handleFullscreenChange);
});

// Generate 3D Scene
const handleGenerate = async (topicToUse?: string) => {
    const targetTopic = (topicToUse || query.value).trim();
    if (!targetTopic) return;

    query.value = targetTopic;
    isLoading.value = true;
    errorMessage.value = null;

    chatMessages.value.push({
        id: `msg-${Date.now()}`,
        sender: 'user',
        text: targetTopic,
        timestamp: new Date(),
    });

    startStepExecutionAnimation();

    try {
        const response = await fetch('/3d-studio/generate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-XSRF-TOKEN': getXsrfToken(),
            },
            body: JSON.stringify({
                topic: targetTopic,
                provider: selectedProvider.value,
                api_key: customApiKey.value || null,
            }),
        });

        if (!response.ok) {
            const errData = await response.json().catch(() => ({}));
            if (response.status === 502 || response.status === 504) {
                throw new Error('AI generation timed out due to complex 3D simulation size. Please try again or select one of the presets.');
            }
            throw new Error(errData.message || `Request failed with status ${response.status}`);
        }

        const json = await response.json();
        if (json.success && json.data) {
            currentData.value = json.data;
            updateSimulationSrc();

            if (json.data.error_notice) {
                errorMessage.value = json.data.error_notice;
            }

            if (typeof window !== 'undefined') {
                window.history.pushState({}, '', `/3d-studio?topic=${encodeURIComponent(targetTopic)}`);
            }

            stopStepExecutionAnimation(json.data.agent_metadata?.plan);

            chatMessages.value.push({
                id: `msg-${Date.now() + 1}`,
                sender: 'agent',
                text: `Generated 3D simulation: "${json.data.title}". Synthesized interactive Three.js model with KaTeX math and audio narration.`,
                title: json.data.title,
                timestamp: new Date(),
            });
        }
    } catch (err: any) {
        console.error('3D Generation error:', err);
        errorMessage.value = err.message || 'Failed to generate 3D scene. Please try again.';
        stopStepExecutionAnimation();
    } finally {
        isLoading.value = false;
    }
};

const handleChatSubmit = () => {
    if (!chatInput.value.trim() || isLoading.value) return;
    const promptText = chatInput.value.trim();
    chatInput.value = '';
    handleGenerate(promptText);
};

// Copy Code
const copyCode = async () => {
    try {
        const content = codeType.value === 'html' && currentData.value.html_code
            ? currentData.value.html_code
            : currentData.value.vue_code;
        await navigator.clipboard.writeText(content);
        copiedCode.value = true;
        setTimeout(() => {
            copiedCode.value = false;
        }, 2200);
    } catch (err) {
        console.error('Failed to copy code:', err);
    }
};

// Download File (.html or .vue)
const downloadFile = () => {
    const isHtml = codeType.value === 'html' || !!currentData.value.html_code;
    const ext = isHtml ? 'html' : 'vue';
    const content = isHtml ? (currentData.value.html_code || currentData.value.vue_code) : currentData.value.vue_code;
    const filename = `${currentData.value.title.toLowerCase().replace(/[^a-z0-9]+/g, '-')}.${ext}`;
    const blob = new Blob([content], { type: isHtml ? 'text/html' : 'text/plain;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
};

// Computed active lighting
const activeAmbientIntensity = computed(() => {
    switch (lightingPreset.value) {
        case 'night': return 0.3;
        case 'day': return 1.5;
        case 'neon': return 0.5;
        default: return currentData.value.scene.ambient_light.intensity || 0.9;
    }
});

const activeDirLightColor = computed(() => {
    switch (lightingPreset.value) {
        case 'night': return '#38bdf8';
        case 'day': return '#ffffff';
        case 'neon': return '#f43f5e';
        default: return currentData.value.scene.directional_light.color || '#ffffff';
    }
});

const activeBgColor = computed(() => {
    switch (lightingPreset.value) {
        case 'night': return '#030712';
        case 'day': return '#0f172a';
        case 'neon': return '#05050f';
        default: return currentData.value.scene.background_color || '#070b16';
    }
});

const getSampleIcon = (iconName: string) => {
    switch (iconName) {
        case 'activity': return Activity;
        case 'eye': return Eye;
        case 'sun': return Sun;
        case 'building': return Building;
        case 'globe': return Globe;
        case 'cpu': return Cpu;
        case 'mountain': return Mountain;
        case 'shield': return Shield;
        default: return Sparkles;
    }
};
</script>

<template>
    <Head :title="`${currentData.title} — AI 3D Studio`" />

    <div class="min-h-screen bg-[#05070e] text-slate-100 flex flex-col font-sans selection:bg-cyan-500 selection:text-black relative overflow-x-hidden">
        <!-- Ambient Background Glows -->
        <div class="absolute top-0 left-1/4 w-[700px] h-[380px] bg-cyan-600/10 blur-[150px] pointer-events-none rounded-full"></div>
        <div class="absolute top-60 right-1/4 w-[600px] h-[350px] bg-purple-600/10 blur-[150px] pointer-events-none rounded-full"></div>

        <!-- TOP NAVIGATION BAR -->
        <header class="sticky top-0 z-40 w-full backdrop-blur-xl bg-[#05070e]/85 border-b border-slate-800/80 transition-all">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-4">
                <!-- Branding -->
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-cyan-500 via-indigo-500 to-pink-500 p-[1.5px] shadow-lg shadow-cyan-500/20">
                        <div class="w-full h-full bg-[#080c18] rounded-[10px] flex items-center justify-center">
                            <Box class="w-5 h-5 text-cyan-400" />
                        </div>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="font-extrabold text-base tracking-tight text-white">SpatialForge</span>
                            <span class="text-[10px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded bg-cyan-500/20 border border-cyan-500/30 text-cyan-300">
                                3D Simulation Agent
                            </span>
                        </div>
                        <p class="text-xs text-slate-400 font-medium">Interactive Three.js Science &amp; Physical Simulation Generator</p>
                    </div>
                </div>

                <!-- Navigation Links & Provider Badge -->
                <div class="flex items-center gap-3">
                    <!-- SVG Studio Sister Link -->
                    <Link
                        href="/svg-studio"
                        class="hidden sm:flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-slate-900/80 hover:bg-slate-800 text-xs font-semibold text-slate-300 hover:text-white border border-slate-800 hover:border-slate-700 transition-all"
                    >
                        <Sparkles class="w-3.5 h-3.5 text-indigo-400" />
                        <span>SVG Studio</span>
                    </Link>

                    <!-- Provider Settings Badge -->
                    <button
                        @click="showSettings = !showSettings"
                        class="flex items-center gap-2 px-3 py-1.5 rounded-lg bg-slate-900/90 border border-slate-800 hover:border-slate-700 text-xs text-slate-300 hover:text-white transition-all shadow-sm cursor-pointer"
                    >
                        <div class="w-2 h-2 rounded-full bg-emerald-400"></div>
                        <span class="capitalize">{{ selectedProvider }}</span>
                        <Settings class="w-3.5 h-3.5 text-slate-400" />
                    </button>

                    <Link
                        href="/"
                        class="text-xs font-semibold px-3 py-1.5 rounded-lg bg-slate-800/60 hover:bg-slate-800 text-slate-300 hover:text-white border border-slate-700/50 transition-all"
                    >
                        Home
                    </Link>
                </div>
            </div>

            <!-- Provider Settings Dropdown -->
            <div
                v-if="showSettings"
                class="absolute right-6 top-16 w-80 bg-slate-900/95 backdrop-blur-xl border border-slate-800 rounded-xl p-4 shadow-2xl z-50 animate-in fade-in slide-in-from-top-2"
            >
                <div class="flex items-center justify-between pb-3 border-b border-slate-800 mb-3">
                    <span class="text-xs font-bold text-slate-200 uppercase tracking-wider">AI Provider Config</span>
                    <button @click="showSettings = false" class="text-slate-400 hover:text-white text-xs">✕</button>
                </div>
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">Active AI Provider</label>
                        <select
                            v-model="selectedProvider"
                            class="w-full bg-slate-950 border border-slate-800 rounded-lg px-2.5 py-1.5 text-xs text-slate-200 focus:outline-none focus:border-cyan-500"
                        >
                            <option v-for="prov in availableProviders" :key="prov.id" :value="prov.id">
                                {{ prov.name }} ({{ prov.models.join(', ') }})
                            </option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-slate-400 mb-1">Custom API Key (Optional)</label>
                        <input
                            v-model="customApiKey"
                            type="password"
                            placeholder="Leave empty to use .env key"
                            class="w-full bg-slate-950 border border-slate-800 rounded-lg px-2.5 py-1.5 text-xs text-slate-200 focus:outline-none focus:border-cyan-500"
                        />
                    </div>
                </div>
            </div>
        </header>

        <!-- MAIN STUDIO CONTAINER -->
        <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 flex flex-col gap-5">
            <!-- PROMPT & HERO SEARCH BAR -->
            <section class="w-full">
                <form @submit.prevent="handleGenerate()" class="relative flex items-center">
                    <div class="relative w-full flex items-center">
                        <div class="absolute left-4.5 text-cyan-400 pointer-events-none">
                            <Sparkles class="w-5 h-5" />
                        </div>
                        <input
                            v-model="query"
                            type="text"
                            placeholder="Enter any simulation prompt (e.g., 'Double pendulum chaos mechanics', 'Quantum harmonic oscillator', 'Thin lens optics')..."
                            :disabled="isLoading"
                            class="w-full pl-12 pr-44 py-4 bg-slate-900/90 border border-slate-800 rounded-2xl text-slate-100 placeholder-slate-500 text-sm md:text-base font-medium shadow-2xl focus:outline-none focus:border-cyan-500 focus:ring-2 focus:ring-cyan-500/20 transition-all disabled:opacity-60"
                        />
                        <button
                            type="submit"
                            :disabled="isLoading || !query.trim()"
                            class="absolute right-2 px-5 py-2.5 rounded-xl font-bold text-sm bg-gradient-to-r from-cyan-500 via-sky-500 to-indigo-600 hover:from-cyan-400 hover:to-indigo-500 text-slate-950 flex items-center gap-2 shadow-lg shadow-cyan-500/25 transition-all active:scale-95 disabled:opacity-50 cursor-pointer"
                        >
                            <Sparkles v-if="!isLoading" class="w-4 h-4" />
                            <div v-else class="w-4 h-4 border-2 border-slate-950 border-t-transparent rounded-full animate-spin"></div>
                            <span>{{ isLoading ? 'Synthesizing...' : 'Generate 3D Sim' }}</span>
                        </button>
                    </div>
                </form>

                <!-- Quick Showcase Topic Chips -->
                <div class="flex items-center gap-2 mt-3 overflow-x-auto pb-1 scrollbar-none text-xs text-slate-400">
                    <span class="text-slate-400 font-semibold uppercase text-[10px] tracking-wider flex items-center gap-1 shrink-0">
                        <Sparkles class="w-3 h-3 text-cyan-400" /> Presets:
                    </span>
                    <button
                        v-for="topic in sampleTopics"
                        :key="topic.title"
                        @click="handleGenerate(topic.prompt)"
                        :disabled="isLoading"
                        class="shrink-0 px-3 py-1.5 rounded-full bg-slate-900/70 hover:bg-slate-800 border border-slate-800/80 hover:border-slate-700 text-slate-300 hover:text-white transition-all flex items-center gap-1.5 cursor-pointer disabled:opacity-50"
                    >
                        <component :is="getSampleIcon(topic.icon)" class="w-3 h-3 text-cyan-400" />
                        <span>{{ topic.title }}</span>
                    </button>
                </div>
            </section>

            <!-- ERROR ALERT (If Any) -->
            <div
                v-if="errorMessage"
                class="w-full bg-rose-950/40 border border-rose-500/40 rounded-xl p-3 flex items-center justify-between text-xs text-rose-200"
            >
                <div class="flex items-center gap-2">
                    <span class="text-rose-400 font-bold">Generation Notice:</span>
                    <span>{{ errorMessage }}</span>
                </div>
                <button @click="errorMessage = null" class="text-rose-400 hover:text-white font-bold">✕</button>
            </div>

            <!-- WORKSPACE LAYOUT: 3D CANVAS & INSPECTOR -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 flex-1 items-start">
                
                <!-- 3D CANVAS & CODE VIEWER (8 COLS) -->
                <div class="lg:col-span-8 flex flex-col gap-3">
                    
                    <!-- Canvas Toolbar -->
                    <div class="flex items-center justify-between bg-slate-900/80 backdrop-blur-md border border-slate-800 rounded-xl px-3 py-2 text-xs">
                        <!-- Left: View Mode Toggle -->
                        <div class="flex items-center gap-1 bg-slate-950/80 p-0.5 rounded-lg border border-slate-800/80">
                            <button
                                @click="viewMode = 'sim'"
                                :class="[
                                    'flex items-center gap-1.5 px-3 py-1.5 rounded-md font-semibold transition-all cursor-pointer',
                                    viewMode === 'sim'
                                        ? 'bg-cyan-500 text-slate-950 shadow-sm'
                                        : 'text-slate-400 hover:text-slate-200',
                                ]"
                            >
                                <Play class="w-3.5 h-3.5" />
                                <span>Interactive Runner</span>
                            </button>
                            <button
                                @click="viewMode = '3d'"
                                :class="[
                                    'flex items-center gap-1.5 px-3 py-1.5 rounded-md font-semibold transition-all cursor-pointer',
                                    viewMode === '3d'
                                        ? 'bg-cyan-500 text-slate-950 shadow-sm'
                                        : 'text-slate-400 hover:text-slate-200',
                                ]"
                            >
                                <Eye class="w-3.5 h-3.5" />
                                <span>Geometry Canvas</span>
                            </button>
                            <button
                                @click="viewMode = 'code'"
                                :class="[
                                    'flex items-center gap-1.5 px-3 py-1.5 rounded-md font-semibold transition-all cursor-pointer',
                                    viewMode === 'code'
                                        ? 'bg-cyan-500 text-slate-950 shadow-sm'
                                        : 'text-slate-400 hover:text-slate-200',
                                ]"
                            >
                                <Code class="w-3.5 h-3.5" />
                                <span>Code Inspector</span>
                            </button>
                        </div>

                        <!-- Center: Simulation Controls (when in sim view) -->
                        <div v-if="viewMode === 'sim'" class="flex items-center gap-1.5">
                            <button
                                @click="reloadSimulation"
                                title="Reload simulation"
                                class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-slate-950/80 hover:bg-slate-800 text-slate-300 hover:text-white border border-slate-800 transition cursor-pointer"
                            >
                                <RotateCcw class="w-3.5 h-3.5 text-cyan-400" />
                                <span class="hidden sm:inline">Restart</span>
                            </button>
                            <button
                                @click="openInNewTab"
                                title="Open full simulation in new window/tab"
                                class="flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-slate-950/80 hover:bg-slate-800 text-slate-300 hover:text-white border border-slate-800 transition cursor-pointer"
                            >
                                <ExternalLink class="w-3.5 h-3.5 text-indigo-400" />
                                <span class="hidden sm:inline">New Tab</span>
                            </button>
                            <button
                                @click="toggleFullscreen"
                                :title="isFullscreen ? 'Exit fullscreen' : 'Expand fullscreen'"
                                class="p-1.5 rounded-lg bg-slate-950/80 hover:bg-slate-800 text-slate-300 hover:text-white border border-slate-800 transition cursor-pointer"
                            >
                                <Minimize2 v-if="isFullscreen" class="w-3.5 h-3.5 text-amber-400" />
                                <Maximize2 v-else class="w-3.5 h-3.5 text-slate-400" />
                            </button>
                        </div>

                        <!-- Center: 3D Interaction Toggles (when in 3d view) -->
                        <div v-else-if="viewMode === '3d'" class="hidden sm:flex items-center gap-1 bg-slate-950/80 px-2 py-1 rounded-lg border border-slate-800/80">
                            <!-- Auto-Rotate -->
                            <button
                                @click="autoRotate = !autoRotate"
                                :title="autoRotate ? 'Pause rotation' : 'Start auto-spin'"
                                :class="[
                                    'p-1 rounded hover:bg-slate-800 transition-colors cursor-pointer',
                                    autoRotate ? 'text-cyan-400' : 'text-slate-500',
                                ]"
                            >
                                <Play v-if="!autoRotate" class="w-3.5 h-3.5" />
                                <Pause v-else class="w-3.5 h-3.5" />
                            </button>

                            <!-- Grid Toggle -->
                            <button
                                @click="showGrid = !showGrid"
                                :title="showGrid ? 'Hide grid floor' : 'Show grid floor'"
                                :class="[
                                    'p-1 rounded hover:bg-slate-800 transition-colors cursor-pointer',
                                    showGrid ? 'text-cyan-400' : 'text-slate-500',
                                ]"
                            >
                                <Grid class="w-3.5 h-3.5" />
                            </button>

                            <!-- Lighting Preset Toggle -->
                            <button
                                @click="lightingPreset = lightingPreset === 'cinematic' ? 'night' : (lightingPreset === 'night' ? 'day' : (lightingPreset === 'day' ? 'neon' : 'cinematic'))"
                                :title="`Lighting: ${lightingPreset}`"
                                class="flex items-center gap-1 px-1.5 py-0.5 rounded text-[11px] font-bold text-slate-300 hover:bg-slate-800 transition-colors uppercase tracking-wider cursor-pointer"
                            >
                                <Sun v-if="lightingPreset === 'day'" class="w-3 h-3 text-amber-400" />
                                <Moon v-else-if="lightingPreset === 'night'" class="w-3 h-3 text-sky-400" />
                                <Sparkles v-else class="w-3 h-3 text-cyan-400" />
                                <span>{{ lightingPreset }}</span>
                            </button>
                        </div>

                        <!-- Right: Actions (Copy & Export) -->
                        <div class="flex items-center gap-1.5">
                            <button
                                @click="copyCode"
                                class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-200 transition-all font-medium cursor-pointer"
                            >
                                <Check v-if="copiedCode" class="w-3.5 h-3.5 text-emerald-400" />
                                <Copy v-else class="w-3.5 h-3.5 text-slate-400" />
                                <span>{{ copiedCode ? 'Copied!' : 'Copy' }}</span>
                            </button>
                            <button
                                @click="downloadFile"
                                class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg bg-cyan-600 hover:bg-cyan-500 text-slate-950 font-bold transition-all cursor-pointer"
                            >
                                <Download class="w-3.5 h-3.5 text-slate-950" />
                                <span>Export</span>
                            </button>
                        </div>
                    </div>

                    <!-- 1. INTERACTIVE 3D SIMULATION RUNNER -->
                    <div
                        v-show="viewMode === 'sim'"
                        ref="simulationContainerRef"
                        class="w-full h-[620px] rounded-2xl border border-slate-800/80 bg-slate-950 relative overflow-hidden shadow-2xl transition-all"
                    >
                        <iframe
                            v-if="simulationBlobUrl"
                            :src="simulationBlobUrl"
                            class="w-full h-full border-0 absolute inset-0"
                            sandbox="allow-scripts allow-same-origin allow-popups"
                            title="Interactive 3D Simulation Runner"
                        />
                        <div v-else class="w-full h-full flex flex-col items-center justify-center text-slate-500 gap-2">
                            <div class="w-6 h-6 border-2 border-cyan-500 border-t-transparent rounded-full animate-spin"></div>
                            <span class="text-xs">Initializing simulation sandbox...</span>
                        </div>

                        <!-- Simulation Mode Indicator Badge -->
                        <div class="absolute bottom-3 left-3 pointer-events-none flex items-center gap-2 px-3 py-1 rounded-full bg-slate-900/85 backdrop-blur-md border border-slate-800 text-[10px] font-bold text-slate-400">
                            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                            <span>Interactive Simulation • Three.js r128 • KaTeX &amp; Speech</span>
                        </div>
                    </div>

                    <!-- 2. TRESJS CANVAS VIEWPORT (GEOMETRY FALLBACK) -->
                    <div
                        v-show="viewMode === '3d'"
                        class="w-full h-[620px] rounded-2xl border border-slate-800/80 relative overflow-hidden shadow-2xl transition-all"
                        :style="{ backgroundColor: activeBgColor }"
                    >
                        <!-- Real-time TresJS Canvas -->
                        <TresCanvas
                            :clear-color="activeBgColor"
                            antialias
                            :pixel-ratio="2"
                        >
                            <TresPerspectiveCamera
                                :position="currentData.scene.camera.position"
                                :fov="currentData.scene.camera.fov"
                            />
                            <OrbitControls
                                :enable-damping="true"
                                :damping-factor="0.05"
                                :auto-rotate="autoRotate"
                                :auto-rotate-speed="1.2"
                                :max-polar-angle="Math.PI / 2 - 0.05"
                                :min-distance="2"
                                :max-distance="25"
                            />

                            <!-- Dynamic Lighting System -->
                            <TresAmbientLight
                                :color="currentData.scene.ambient_light.color"
                                :intensity="activeAmbientIntensity"
                            />
                            <TresDirectionalLight
                                :position="currentData.scene.directional_light.position"
                                :color="activeDirLightColor"
                                :intensity="currentData.scene.directional_light.intensity"
                                cast-shadow
                            />
                            <TresPointLight
                                :position="currentData.scene.accent_light.position"
                                :color="currentData.scene.accent_light.color"
                                :intensity="currentData.scene.accent_light.intensity"
                            />

                            <!-- Depth Fog -->
                            <TresFog
                                :color="activeBgColor"
                                :near="currentData.scene.fog.near"
                                :far="currentData.scene.fog.far"
                            />

                            <!-- Dynamic 3D Scene Nodes & Animation -->
                            <Suspense>
                                <DynamicScene3D :meshes="currentData.scene.meshes" />
                            </Suspense>

                            <!-- Ground Contact Shadow -->
                            <ContactShadows
                                :resolution="512"
                                :scale="14"
                                :blur="2"
                                :opacity="0.5"
                                :far="4"
                                :position="[0, 0.01, 0]"
                            />
                        </TresCanvas>

                        <!-- HUD Overlay: Orbit Hint -->
                        <div class="absolute bottom-4 left-4 pointer-events-none flex items-center gap-2 px-3 py-1.5 rounded-full bg-slate-900/80 backdrop-blur-md border border-slate-800 text-[11px] font-medium text-slate-400">
                            <Compass class="w-3.5 h-3.5 text-cyan-400" />
                            <span>Left-click drag to rotate • Right-click drag to pan • Scroll to zoom</span>
                        </div>

                        <!-- HUD Overlay: Scene Stats Badge -->
                        <div class="absolute top-4 right-4 pointer-events-none flex items-center gap-2">
                            <span class="px-2.5 py-1 rounded-full bg-slate-950/80 backdrop-blur-md border border-slate-800 text-[11px] font-bold text-slate-300">
                                {{ currentData.scene.meshes.length }} Meshes
                            </span>
                            <span class="px-2.5 py-1 rounded-full bg-cyan-950/80 backdrop-blur-md border border-cyan-500/30 text-[11px] font-bold text-cyan-300">
                                {{ currentData.category }}
                            </span>
                        </div>
                    </div>

                    <!-- 3. SOURCE CODE VIEWER CONTAINER -->
                    <div v-show="viewMode === 'code'" class="w-full flex flex-col gap-2">
                        <div class="flex items-center gap-2 bg-slate-900/60 p-1.5 rounded-xl border border-slate-800 text-xs">
                            <button
                                @click="codeType = 'html'"
                                :class="[
                                    'px-3 py-1.5 rounded-lg font-bold transition cursor-pointer',
                                    codeType === 'html'
                                        ? 'bg-cyan-500 text-slate-950 shadow-sm'
                                        : 'text-slate-400 hover:text-slate-200',
                                ]"
                            >
                                Standalone Simulation HTML (New Standard)
                            </button>
                            <button
                                @click="codeType = 'vue'"
                                :class="[
                                    'px-3 py-1.5 rounded-lg font-bold transition cursor-pointer',
                                    codeType === 'vue'
                                        ? 'bg-cyan-500 text-slate-950 shadow-sm'
                                        : 'text-slate-400 hover:text-slate-200',
                                ]"
                            >
                                Vue 3 / TresJS Component
                            </button>
                        </div>
                        <div class="w-full h-[560px] rounded-2xl border border-slate-800/80 bg-[#070b16] p-4 overflow-auto font-mono text-xs text-slate-300 shadow-2xl relative">
                            <pre class="whitespace-pre"><code>{{ codeType === 'html' ? (currentData.html_code || currentData.vue_code) : currentData.vue_code }}</code></pre>
                        </div>
                    </div>

                    <!-- SCENE DETAILS & SUMMARY CARD -->
                    <div class="bg-slate-900/60 border border-slate-800/80 rounded-2xl p-5 shadow-xl">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-slate-800/80">
                            <div>
                                <h2 class="text-xl font-extrabold text-white tracking-tight">{{ currentData.title }}</h2>
                                <p class="text-xs text-slate-400 mt-1">{{ currentData.description }}</p>
                            </div>
                            <!-- Color Swatches -->
                            <div class="flex items-center gap-1.5 shrink-0">
                                <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mr-1">Palette:</span>
                                <div
                                    v-for="(hex, idx) in currentData.palette"
                                    :key="idx"
                                    class="w-6 h-6 rounded-lg border border-white/20 shadow-sm"
                                    :style="{ backgroundColor: hex }"
                                    :title="hex"
                                ></div>
                            </div>
                        </div>

                        <!-- Key Features Grid -->
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mt-4">
                            <div
                                v-for="(feat, idx) in currentData.key_features"
                                :key="idx"
                                class="bg-slate-950/60 border border-slate-800/60 rounded-xl p-3 flex items-start gap-2.5 text-xs text-slate-300"
                            >
                                <CheckCircle2 class="w-4 h-4 text-cyan-400 shrink-0 mt-0.5" />
                                <span>{{ feat }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- RIGHT SIDEBAR: AGENT PLAN & CHAT CONSOLE (4 COLS) -->
                <div class="lg:col-span-4 flex flex-col gap-4">
                    
                    <!-- Sidebar Tab Selector -->
                    <div class="flex items-center gap-1 bg-slate-900/80 p-1 rounded-xl border border-slate-800">
                        <button
                            @click="activeTab = 'plan'"
                            :class="[
                                'flex-1 py-2 rounded-lg font-bold text-xs flex items-center justify-center gap-1.5 transition-all cursor-pointer',
                                activeTab === 'plan'
                                    ? 'bg-cyan-500 text-slate-950 shadow-sm'
                                    : 'text-slate-400 hover:text-white',
                            ]"
                        >
                            <Workflow class="w-3.5 h-3.5" />
                            <span>Agent Plan</span>
                        </button>
                        <button
                            @click="activeTab = 'chat'"
                            :class="[
                                'flex-1 py-2 rounded-lg font-bold text-xs flex items-center justify-center gap-1.5 transition-all cursor-pointer',
                                activeTab === 'chat'
                                    ? 'bg-cyan-500 text-slate-950 shadow-sm'
                                    : 'text-slate-400 hover:text-white',
                            ]"
                        >
                            <MessageSquare class="w-3.5 h-3.5" />
                            <span>Iterate &amp; Chat</span>
                        </button>
                        <button
                            @click="activeTab = 'inspector'"
                            :class="[
                                'flex-1 py-2 rounded-lg font-bold text-xs flex items-center justify-center gap-1.5 transition-all cursor-pointer',
                                activeTab === 'inspector'
                                    ? 'bg-cyan-500 text-slate-950 shadow-sm'
                                    : 'text-slate-400 hover:text-white',
                            ]"
                        >
                            <Sliders class="w-3.5 h-3.5" />
                            <span>Nodes</span>
                        </button>
                    </div>

                    <!-- TAB 1: AGENTIC EXECUTION PLAN -->
                    <div v-show="activeTab === 'plan'" class="bg-slate-900/60 border border-slate-800 rounded-2xl p-5 shadow-xl">
                        <div class="flex items-center justify-between pb-3 border-b border-slate-800/80 mb-4">
                            <div class="flex items-center gap-2">
                                <Terminal class="w-4 h-4 text-cyan-400" />
                                <span class="font-bold text-xs text-white uppercase tracking-wider">Spatial Pipeline</span>
                            </div>
                            <span class="text-[10px] font-bold px-2 py-0.5 rounded bg-cyan-500/20 text-cyan-300 border border-cyan-500/30 uppercase">
                                {{ isLoading ? 'Executing' : 'Completed' }}
                            </span>
                        </div>

                        <!-- 6-Stage Timeline -->
                        <div class="space-y-4">
                            <div
                                v-for="step in liveSteps"
                                :key="step.step"
                                class="flex items-start gap-3 relative"
                            >
                                <!-- Step Status Icon -->
                                <div class="shrink-0 mt-0.5">
                                    <div
                                        v-if="step.status === 'completed'"
                                        class="w-5 h-5 rounded-full bg-emerald-500/20 border border-emerald-500 flex items-center justify-center text-emerald-400"
                                    >
                                        <Check class="w-3 h-3 stroke-[3]" />
                                    </div>
                                    <div
                                        v-else-if="step.status === 'running'"
                                        class="w-5 h-5 rounded-full bg-cyan-500/20 border border-cyan-400 flex items-center justify-center"
                                    >
                                        <div class="w-2 h-2 rounded-full bg-cyan-400 animate-ping"></div>
                                    </div>
                                    <div
                                        v-else
                                        class="w-5 h-5 rounded-full bg-slate-800 border border-slate-700 flex items-center justify-center text-[10px] text-slate-500 font-bold"
                                    >
                                        {{ step.step }}
                                    </div>
                                </div>

                                <!-- Step Text -->
                                <div class="flex-1">
                                    <h4
                                        :class="[
                                            'text-xs font-bold leading-tight',
                                            step.status === 'running' ? 'text-cyan-400' : (step.status === 'completed' ? 'text-slate-200' : 'text-slate-500'),
                                        ]"
                                    >
                                        {{ step.title }}
                                    </h4>
                                    <p class="text-[11px] text-slate-400 mt-0.5 leading-snug">{{ step.detail }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Reflective Quality Verification Certificate Card -->
                        <div
                            v-if="currentData.agent_metadata?.quality_evaluation"
                            class="mt-4 p-3.5 rounded-xl bg-emerald-950/25 border border-emerald-500/30 shadow-lg animate-in fade-in"
                        >
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center gap-1.5">
                                    <CheckCircle2 class="w-4 h-4 text-emerald-400" />
                                    <span class="text-xs font-bold text-emerald-300">Reflective Quality Verified</span>
                                </div>
                                <span class="text-[10px] font-extrabold px-2 py-0.5 rounded-full bg-emerald-500/20 text-emerald-300 border border-emerald-500/40">
                                    {{ currentData.agent_metadata.quality_evaluation.score }}% Standard Compliance
                                </span>
                            </div>
                            <p class="text-[10px] text-slate-400 mb-2.5">
                                Tested and validated against all 11 architectural invariants: Three.js r128, CSS2D pointer-events safety, KaTeX math typesetting ($P_1$), chunked audio, and physics sliders.
                            </p>
                            <div class="grid grid-cols-2 gap-1.5 text-[10px]">
                                <div
                                    v-for="chk in currentData.agent_metadata.quality_evaluation.checks"
                                    :key="chk.rule"
                                    class="flex items-center gap-1.5 bg-slate-900/80 px-2 py-1.5 rounded-lg border border-slate-800"
                                >
                                    <span :class="chk.passed ? 'text-emerald-400 font-bold' : 'text-rose-400 font-bold'">
                                        {{ chk.passed ? '✓' : '✗' }}
                                    </span>
                                    <span class="truncate text-slate-300">{{ chk.title }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 2: INTERACTIVE CONVERSATION CHAT -->
                    <div v-show="activeTab === 'chat'" class="bg-slate-900/60 border border-slate-800 rounded-2xl p-4 shadow-xl flex flex-col h-[460px]">
                        <div class="flex items-center justify-between pb-3 border-b border-slate-800/80 mb-3">
                            <div class="flex items-center gap-2">
                                <MessageSquare class="w-4 h-4 text-cyan-400" />
                                <span class="font-bold text-xs text-white uppercase tracking-wider">Spatial Chat Console</span>
                            </div>
                            <span class="text-[10px] text-slate-400">Iterative Refinement</span>
                        </div>

                        <!-- Message List -->
                        <div class="flex-1 overflow-y-auto space-y-3 pr-1 text-xs">
                            <div
                                v-for="msg in chatMessages"
                                :key="msg.id"
                                :class="[
                                    'p-3 rounded-xl max-w-[90%]',
                                    msg.sender === 'user'
                                        ? 'ml-auto bg-cyan-600/30 border border-cyan-500/40 text-cyan-100'
                                        : 'bg-slate-950 border border-slate-800 text-slate-300',
                                ]"
                            >
                                <div class="flex items-center justify-between mb-1 text-[10px] opacity-70">
                                    <span class="font-bold">{{ msg.sender === 'user' ? 'You' : '3D Agent' }}</span>
                                </div>
                                <p class="leading-relaxed">{{ msg.text }}</p>
                            </div>
                        </div>

                        <!-- Chat Input Box -->
                        <form @submit.prevent="handleChatSubmit" class="mt-3 flex items-center gap-2 pt-2 border-t border-slate-800/80">
                            <input
                                v-model="chatInput"
                                type="text"
                                placeholder="E.g., 'Add a floating ring around the central tower'..."
                                :disabled="isLoading"
                                class="flex-1 bg-slate-950 border border-slate-800 rounded-xl px-3 py-2 text-xs text-slate-100 placeholder-slate-500 focus:outline-none focus:border-cyan-500"
                            />
                            <button
                                type="submit"
                                :disabled="isLoading || !chatInput.trim()"
                                class="p-2 rounded-xl bg-cyan-500 hover:bg-cyan-400 text-slate-950 transition-all disabled:opacity-40 cursor-pointer"
                            >
                                <Send class="w-3.5 h-3.5" />
                            </button>
                        </form>
                    </div>

                    <!-- TAB 3: SCENE GRAPH NODE INSPECTOR -->
                    <div v-show="activeTab === 'inspector'" class="bg-slate-900/60 border border-slate-800 rounded-2xl p-4 shadow-xl flex flex-col h-[460px]">
                        <div class="flex items-center justify-between pb-3 border-b border-slate-800/80 mb-3">
                            <div class="flex items-center gap-2">
                                <Layers class="w-4 h-4 text-cyan-400" />
                                <span class="font-bold text-xs text-white uppercase tracking-wider">Mesh Graph Nodes</span>
                            </div>
                            <span class="text-[10px] text-cyan-400 font-bold">{{ currentData.scene.meshes.length }} Objects</span>
                        </div>

                        <!-- Mesh List -->
                        <div class="flex-1 overflow-y-auto space-y-2 pr-1 text-xs">
                            <div
                                v-for="mesh in currentData.scene.meshes"
                                :key="mesh.id"
                                class="bg-slate-950/80 border border-slate-800/80 rounded-xl p-2.5 flex items-center justify-between"
                            >
                                <div class="flex items-center gap-2.5">
                                    <div
                                        class="w-3.5 h-3.5 rounded-full border border-white/20 shrink-0"
                                        :style="{ backgroundColor: mesh.color }"
                                    ></div>
                                    <div>
                                        <div class="font-bold text-slate-200 text-xs">{{ mesh.name }}</div>
                                        <div class="text-[10px] text-slate-500 uppercase tracking-wider">
                                            {{ mesh.type }} • Pos [{{ mesh.position.join(', ') }}]
                                        </div>
                                    </div>
                                </div>
                                <span
                                    v-if="mesh.animation && mesh.animation !== 'none'"
                                    class="text-[9px] font-bold px-1.5 py-0.5 rounded bg-cyan-500/20 text-cyan-300 border border-cyan-500/30 uppercase"
                                >
                                    {{ mesh.animation }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>

<style scoped>
.scrollbar-none::-webkit-scrollbar {
    display: none;
}
.scrollbar-none {
    -ms-overflow-style: none;
    scrollbar-width: none;
}
</style>
