<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';
import {
    Sparkles,
    Box,
    Search,
    Download,
    Copy,
    Check,
    ZoomIn,
    ZoomOut,
    RotateCcw,
    Code,
    Eye,
    Maximize2,
    Minimize2,
    Settings,
    FileCode,
    BookOpen,
    ArrowRight,
    RefreshCw,
    Sliders,
    Leaf,
    Activity,
    Brain,
    Dna,
    Grid,
    CheckCircle2,
    Info,
    ChevronDown,
    ExternalLink,
    AlertTriangle,
    MessageSquare,
    Send,
    Workflow,
    Clock,
    Terminal,
    Cpu,
    CheckCircle,
    CircleDashed,
} from 'lucide-vue-next';

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
}

interface SvgResult {
    title: string;
    description: string;
    svg: string;
    key_takeaways: string[];
    category: string;
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
    category?: string;
}

const props = defineProps<{
    initialData: SvgResult;
    initialTopic: string;
    defaultProvider: string;
    availableProviders: ProviderOption[];
    sampleTopics: SampleTopic[];
}>();

// State
const query = ref(props.initialTopic || 'Photosynthesis');
const currentData = ref<SvgResult>(props.initialData);
const isLoading = ref(false);
const viewMode = ref<'render' | 'code'>('render');
const canvasBg = ref<'dark' | 'slate' | 'light' | 'grid'>('dark');
const zoomLevel = ref(1);
const isFullscreen = ref(false);
const copiedCode = ref(false);
const showSettings = ref(false);
const selectedProvider = ref(props.defaultProvider || 'gemini');
const customApiKey = ref('');
const errorMessage = ref<string | null>(null);

// Interactive Sidebar Tab ('plan' | 'chat' | 'history')
const activeTab = ref<'plan' | 'chat' | 'history'>('plan');

// Interactive Agent Console Chat
const chatInput = ref('');
const chatMessages = ref<ChatMessage[]>([
    {
        id: 'msg-0',
        sender: 'user',
        text: `Generate an educational SVG illustration for: ${props.initialTopic || 'Photosynthesis'}`,
        timestamp: new Date(),
    },
    {
        id: 'msg-1',
        sender: 'agent',
        text: `Synthesized "${props.initialData.title}". Created self-contained vector diagram with complete structural annotations.`,
        title: props.initialData.title,
        category: props.initialData.category,
        timestamp: new Date(),
    },
]);

// Live Stepper Simulation State
const executionTimer = ref(0);
const currentExecutingStep = ref(1);
let timerInterval: any = null;
let stepInterval: any = null;

const defaultPlan: AgentStep[] = [
    {
        step: 1,
        title: 'Intent & Learning Objective',
        detail: 'Parsing prompt, extracting pedagogical scope and target audience.',
        status: 'completed',
    },
    {
        step: 2,
        title: 'Factual & Scientific Modeling',
        detail: 'Building entity relationships, chemical/mathematical formulas, and causal pathways.',
        status: 'completed',
    },
    {
        step: 3,
        title: 'Layout & Hierarchy Planning',
        detail: 'Calculating spatial grid, margin coordinates, and visual reading order on 1600x1000 canvas.',
        status: 'completed',
    },
    {
        step: 4,
        title: 'Visual System & Palette Selection',
        detail: 'Applying WCAG AA contrast colors, linear gradients, and directional arrowheads.',
        status: 'completed',
    },
    {
        step: 5,
        title: 'Vector Markup Generation',
        detail: 'Writing semantic SVG primitives (<defs>, <path>, <rect>, <text>, <g>).',
        status: 'completed',
    },
    {
        step: 6,
        title: 'Structural Validation & Delivery',
        detail: 'Verifying XML well-formedness, viewBox scaling, and composing educational description.',
        status: 'completed',
    },
];

const liveSteps = ref<AgentStep[]>(
    props.initialData.agent_metadata?.plan || JSON.parse(JSON.stringify(defaultPlan))
);

// History cache
const history = ref<Array<{ query: string; data: SvgResult; timestamp: Date }>>([
    {
        query: props.initialTopic,
        data: props.initialData,
        timestamp: new Date(),
    },
]);

// Helper: Get XSRF Token from Cookies
const getXsrfToken = () => {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
};

// Start live execution visualization
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

onUnmounted(() => {
    if (timerInterval) clearInterval(timerInterval);
    if (stepInterval) clearInterval(stepInterval);
});

// Generate SVG Action
const handleGenerate = async (topicToUse?: string) => {
    const targetTopic = (topicToUse || query.value).trim();
    if (!targetTopic) return;

    query.value = targetTopic;
    isLoading.value = true;
    errorMessage.value = null;

    // Add user message to conversation
    chatMessages.value.push({
        id: `msg-${Date.now()}`,
        sender: 'user',
        text: targetTopic,
        timestamp: new Date(),
    });

    startStepExecutionAnimation();

    try {
        const response = await fetch('/svg-studio/generate', {
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
            throw new Error(errData.message || `Request failed with status ${response.status}`);
        }

        const json = await response.json();
        if (json.success && json.data) {
            currentData.value = json.data;
            zoomLevel.value = 1;

            if (typeof window !== 'undefined') {
                window.history.pushState({}, '', `/svg-studio?topic=${encodeURIComponent(targetTopic)}`);
            }

            // Stop animation & apply returned steps
            stopStepExecutionAnimation(json.data.agent_metadata?.plan);

            // Add agent response to conversation
            chatMessages.value.push({
                id: `msg-${Date.now() + 1}`,
                sender: 'agent',
                text: `Generated SVG: "${json.data.title}". ${json.data.description.slice(0, 140)}...`,
                title: json.data.title,
                category: json.data.category,
                timestamp: new Date(),
            });

            // Add to history (avoid duplicates at top)
            history.value = [
                { query: targetTopic, data: json.data, timestamp: new Date() },
                ...history.value.filter((h) => h.query.toLowerCase() !== targetTopic.toLowerCase()),
            ].slice(0, 8);
        }
    } catch (err: any) {
        console.error('Generation error:', err);
        errorMessage.value = err.message || 'Failed to generate SVG. Please try again.';
        stopStepExecutionAnimation();
    } finally {
        isLoading.value = false;
    }
};

// Conversational input submission
const handleChatSubmit = () => {
    if (!chatInput.value.trim() || isLoading.value) return;
    const promptToSend = chatInput.value;
    chatInput.value = '';
    handleGenerate(promptToSend);
};

// Select from history
const selectHistoryItem = (item: { query: string; data: SvgResult }) => {
    query.value = item.query;
    currentData.value = item.data;
    zoomLevel.value = 1;
    if (item.data.agent_metadata?.plan) {
        liveSteps.value = item.data.agent_metadata.plan;
    }
};

// Copy raw SVG code
const copySvg = async () => {
    try {
        await navigator.clipboard.writeText(currentData.value.svg);
        copiedCode.value = true;
        setTimeout(() => {
            copiedCode.value = false;
        }, 2200);
    } catch (err) {
        console.error('Failed to copy code', err);
    }
};

// Download SVG file
const downloadSvg = () => {
    const blob = new Blob([currentData.value.svg], { type: 'image/svg+xml;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    const filename = `${currentData.value.title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '') || 'diagram'}.svg`;
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
};

// Zoom controls
const zoomIn = () => {
    zoomLevel.value = Math.min(2.5, Math.round((zoomLevel.value + 0.15) * 100) / 100);
};

const zoomOut = () => {
    zoomLevel.value = Math.max(0.4, Math.round((zoomLevel.value - 0.15) * 100) / 100);
};

const resetZoom = () => {
    zoomLevel.value = 1;
};

// Fullscreen toggle
const toggleFullscreen = () => {
    isFullscreen.value = !isFullscreen.value;
};

// Background class computed
const canvasBgClass = computed(() => {
    switch (canvasBg.value) {
        case 'slate':
            return 'bg-slate-900 border-slate-800';
        case 'light':
            return 'bg-white border-slate-200 text-slate-900';
        case 'grid':
            return 'bg-[#0b101e] bg-[radial-gradient(#1e293b_1px,transparent_1px)] [background-size:16px_16px] border-slate-800';
        default:
            return 'bg-[#080d19] border-slate-800/80';
    }
});

const getSampleIcon = (iconName: string) => {
    switch (iconName) {
        case 'leaf':
            return Leaf;
        case 'grid':
            return Grid;
        case 'activity':
            return Activity;
        case 'brain':
            return Brain;
        case 'dna':
            return Dna;
        default:
            return Sparkles;
    }
};
</script>

<template>
    <Head :title="`${currentData.title} — AI SVG Studio`" />

    <div class="min-h-screen bg-[#060913] text-slate-100 flex flex-col font-sans selection:bg-indigo-500 selection:text-white relative overflow-x-hidden">
        <!-- Ambient Glowing Aura -->
        <div class="absolute top-0 left-1/4 w-[600px] h-[350px] bg-indigo-600/10 blur-[140px] pointer-events-none rounded-full"></div>
        <div class="absolute top-40 right-1/4 w-[500px] h-[300px] bg-emerald-600/10 blur-[130px] pointer-events-none rounded-full"></div>

        <!-- TOP NAVIGATION BAR -->
        <header class="sticky top-0 z-40 w-full backdrop-blur-xl bg-[#060913]/85 border-b border-slate-800/80 transition-all">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-16 flex items-center justify-between gap-4">
                <!-- Branding -->
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 via-sky-500 to-emerald-400 p-[1.5px] shadow-lg shadow-indigo-500/20">
                        <div class="w-full h-full bg-[#090e1c] rounded-[10px] flex items-center justify-center">
                            <Sparkles class="w-5 h-5 text-sky-400" />
                        </div>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="font-extrabold text-base tracking-tight text-white">EduVector</span>
                            <span class="text-[10px] font-bold uppercase tracking-wider px-1.5 py-0.5 rounded bg-indigo-500/20 border border-indigo-500/30 text-indigo-300">
                                AI Agent Studio
                            </span>
                        </div>
                        <p class="text-xs text-slate-400 font-medium">Autonomous Vector Generation &amp; Step Inspector</p>
                    </div>
                </div>

                <!-- Right Actions -->
                <div class="flex items-center gap-3">
                    <!-- Agent Mode Indicator -->
                    <div class="hidden md:flex items-center gap-2 px-3 py-1.5 rounded-lg bg-indigo-950/40 border border-indigo-500/30 text-xs text-indigo-200">
                        <Cpu class="w-3.5 h-3.5 text-indigo-400 animate-pulse" />
                        <span>Agentic Pipeline Active</span>
                    </div>

                    <!-- 3D Studio Sister Link -->
                    <Link
                        href="/3d-studio"
                        class="hidden sm:flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-cyan-950/40 hover:bg-cyan-900/60 text-xs font-semibold text-cyan-300 hover:text-white border border-cyan-500/30 transition-all"
                    >
                        <Box class="w-3.5 h-3.5 text-cyan-400" />
                        <span>3D Studio</span>
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
        </header>

        <!-- MAIN STUDIO CONTENT -->
        <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-6 flex flex-col gap-6 relative z-10">
            <!-- PROMPT SEARCH BAR -->
            <section class="flex flex-col gap-3">
                <form @submit.prevent="() => handleGenerate()" class="relative flex items-center w-full">
                    <div class="absolute left-4 pointer-events-none text-indigo-400 flex items-center">
                        <Search v-if="!isLoading" class="w-5 h-5" />
                        <RefreshCw v-else class="w-5 h-5 animate-spin text-sky-400" />
                    </div>

                    <input
                        v-model="query"
                        type="text"
                        placeholder="Ask for any concept or formula (e.g. Photosynthesis, Completing the square, Laplace transform...)"
                        class="w-full pl-12 pr-36 py-4 rounded-2xl bg-[#0c1222]/90 border border-slate-700/80 hover:border-indigo-500/50 focus:border-indigo-500 text-base sm:text-lg text-white placeholder-slate-400 shadow-2xl focus:outline-none focus:ring-4 focus:ring-indigo-500/20 transition-all"
                        :disabled="isLoading"
                    />

                    <div class="absolute right-2 flex items-center gap-2">
                        <button
                            type="submit"
                            :disabled="isLoading || !query.trim()"
                            class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-indigo-600 via-sky-600 to-emerald-500 hover:from-indigo-500 hover:via-sky-500 hover:to-emerald-400 disabled:opacity-50 text-white font-bold text-sm shadow-lg shadow-indigo-600/30 flex items-center gap-2 transition-all active:scale-95 cursor-pointer"
                        >
                            <Sparkles class="w-4 h-4" />
                            <span>{{ isLoading ? 'Executing Plan...' : 'Generate' }}</span>
                        </button>
                    </div>
                </form>

                <!-- QUICK TOPIC SUGGESTION PILLS -->
                <div class="flex items-center gap-2 overflow-x-auto pb-1 scrollbar-none text-xs">
                    <span class="text-slate-400 font-semibold uppercase tracking-wider text-[11px] shrink-0 flex items-center gap-1">
                        <Sparkles class="w-3.5 h-3.5 text-amber-400" /> Examples:
                    </span>

                    <button
                        v-for="sample in sampleTopics"
                        :key="sample.title"
                        type="button"
                        @click="handleGenerate(sample.title)"
                        :class="[
                            'flex items-center gap-1.5 px-3 py-1.5 rounded-full border transition-all shrink-0 font-medium cursor-pointer',
                            query.toLowerCase() === sample.title.toLowerCase()
                                ? 'bg-indigo-600/30 border-indigo-500/70 text-indigo-200'
                                : 'bg-slate-900/70 border-slate-800 text-slate-300 hover:border-slate-700 hover:text-white',
                        ]"
                    >
                        <component :is="getSampleIcon(sample.icon)" class="w-3.5 h-3.5 text-sky-400" />
                        <span>{{ sample.title }}</span>
                    </button>
                </div>
            </section>

            <!-- ERROR NOTICE BANNER (IF ANY) -->
            <div
                v-if="currentData.error_notice"
                class="flex items-center gap-3 px-4 py-3 rounded-xl bg-amber-500/10 border border-amber-500/30 text-amber-200 text-xs sm:text-sm"
            >
                <Info class="w-5 h-5 text-amber-400 shrink-0" />
                <div class="flex-1">
                    <span class="font-semibold text-amber-300">Notice: </span>
                    {{ currentData.error_notice }}
                </div>
            </div>

            <!-- MAIN WORKSPACE: CANVAS & AGENT CONSOLE/INSPECTOR -->
            <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">
                <!-- SVG VISUAL DISPLAY STAGE (8 COLS) -->
                <div class="lg:col-span-8 flex flex-col gap-4">
                    <!-- TOOLBAR CONTROLS -->
                    <div class="flex flex-wrap items-center justify-between gap-3 bg-[#0c1222] border border-slate-800 p-2.5 rounded-xl">
                        <!-- View Toggle (Rendered vs Code) -->
                        <div class="flex items-center bg-slate-900 p-1 rounded-lg border border-slate-800">
                            <button
                                @click="viewMode = 'render'"
                                :class="[
                                    'flex items-center gap-1.5 px-3 py-1 rounded-md text-xs font-semibold transition-all cursor-pointer',
                                    viewMode === 'render' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-400 hover:text-slate-200',
                                ]"
                            >
                                <Eye class="w-3.5 h-3.5" />
                                <span>Preview</span>
                            </button>
                            <button
                                @click="viewMode = 'code'"
                                :class="[
                                    'flex items-center gap-1.5 px-3 py-1 rounded-md text-xs font-semibold transition-all cursor-pointer',
                                    viewMode === 'code' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-400 hover:text-slate-200',
                                ]"
                            >
                                <Code class="w-3.5 h-3.5" />
                                <span>Source XML</span>
                            </button>
                        </div>

                        <!-- Zoom Controls -->
                        <div v-if="viewMode === 'render'" class="flex items-center gap-1 bg-slate-900 p-1 rounded-lg border border-slate-800 text-xs">
                            <button
                                @click="zoomOut"
                                class="p-1.5 rounded hover:bg-slate-800 text-slate-300 hover:text-white transition-colors cursor-pointer"
                                title="Zoom Out"
                            >
                                <ZoomOut class="w-3.5 h-3.5" />
                            </button>
                            <span class="px-2 font-mono text-[11px] text-slate-400 font-semibold">{{ Math.round(zoomLevel * 100) }}%</span>
                            <button
                                @click="zoomIn"
                                class="p-1.5 rounded hover:bg-slate-800 text-slate-300 hover:text-white transition-colors cursor-pointer"
                                title="Zoom In"
                            >
                                <ZoomIn class="w-3.5 h-3.5" />
                            </button>
                            <button
                                @click="resetZoom"
                                class="p-1.5 rounded hover:bg-slate-800 text-slate-400 hover:text-white transition-colors ml-1 cursor-pointer"
                                title="Reset Scale"
                            >
                                <RotateCcw class="w-3.5 h-3.5" />
                            </button>
                        </div>

                        <!-- Canvas Background Selector -->
                        <div v-if="viewMode === 'render'" class="flex items-center gap-1 bg-slate-900 p-1 rounded-lg border border-slate-800">
                            <button
                                @click="canvasBg = 'dark'"
                                :class="['w-6 h-6 rounded bg-[#080d19] border transition-all cursor-pointer', canvasBg === 'dark' ? 'border-indigo-500 ring-2 ring-indigo-500/40' : 'border-slate-700']"
                                title="Dark Canvas"
                            ></button>
                            <button
                                @click="canvasBg = 'slate'"
                                :class="['w-6 h-6 rounded bg-slate-800 border transition-all cursor-pointer', canvasBg === 'slate' ? 'border-indigo-500 ring-2 ring-indigo-500/40' : 'border-slate-700']"
                                title="Slate Canvas"
                            ></button>
                            <button
                                @click="canvasBg = 'light'"
                                :class="['w-6 h-6 rounded bg-white border transition-all cursor-pointer', canvasBg === 'light' ? 'border-indigo-500 ring-2 ring-indigo-500/40' : 'border-slate-700']"
                                title="Light Canvas"
                            ></button>
                            <button
                                @click="canvasBg = 'grid'"
                                :class="['w-6 h-6 rounded bg-[#0b101e] border transition-all flex items-center justify-center text-[10px] text-slate-400 cursor-pointer', canvasBg === 'grid' ? 'border-indigo-500 ring-2 ring-indigo-500/40' : 'border-slate-700']"
                                title="Grid Canvas"
                            >
                                #
                            </button>
                        </div>

                        <!-- Export & Action Buttons -->
                        <div class="flex items-center gap-2">
                            <button
                                @click="copySvg"
                                class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-xs font-semibold text-slate-200 transition-all border border-slate-700/60 cursor-pointer"
                                title="Copy SVG Code"
                            >
                                <component :is="copiedCode ? Check : Copy" class="w-3.5 h-3.5 text-emerald-400" />
                                <span>{{ copiedCode ? 'Copied!' : 'Copy SVG' }}</span>
                            </button>

                            <button
                                @click="downloadSvg"
                                class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-xs font-semibold text-white transition-all shadow-md shadow-indigo-600/30 cursor-pointer"
                                title="Download .svg File"
                            >
                                <Download class="w-3.5 h-3.5" />
                                <span>Download</span>
                            </button>

                            <button
                                @click="toggleFullscreen"
                                class="p-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-slate-300 hover:text-white transition-all border border-slate-700/60 cursor-pointer"
                                :title="isFullscreen ? 'Exit Fullscreen' : 'Fullscreen View'"
                            >
                                <component :is="isFullscreen ? Minimize2 : Maximize2" class="w-3.5 h-3.5" />
                            </button>
                        </div>
                    </div>

                    <!-- THE CANVAS DISPLAY AREA -->
                    <div
                        :class="[
                            'relative rounded-2xl border transition-all overflow-hidden flex items-center justify-center min-h-[520px] shadow-2xl',
                            canvasBgClass,
                            isFullscreen ? 'fixed inset-4 z-50 min-h-0' : '',
                        ]"
                    >
                        <!-- Close Fullscreen Floating Button -->
                        <button
                            v-if="isFullscreen"
                            @click="toggleFullscreen"
                            class="absolute top-4 right-4 z-20 p-2 rounded-xl bg-slate-900/90 text-white border border-slate-700 hover:bg-slate-800"
                        >
                            <Minimize2 class="w-5 h-5" />
                        </button>

                        <!-- LIVE AGENT EXECUTION OVERLAY (ACTIVE WHILE GENERATING) -->
                        <div v-if="isLoading" class="absolute inset-0 z-30 backdrop-blur-xl bg-slate-950/85 flex flex-col items-center justify-center p-6 gap-6">
                            <div class="max-w-md w-full bg-[#0c1222] border border-indigo-500/40 rounded-2xl p-6 shadow-2xl flex flex-col gap-4">
                                <div class="flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <div class="w-3 h-3 rounded-full bg-indigo-500 animate-ping"></div>
                                        <span class="text-xs font-bold uppercase tracking-wider text-indigo-300">Agent Plan In Motion</span>
                                    </div>
                                    <span class="text-xs font-mono text-slate-400 flex items-center gap-1">
                                        <Clock class="w-3.5 h-3.5 text-sky-400" />
                                        {{ executionTimer }}s
                                    </span>
                                </div>

                                <!-- Active Stage Progress Bar -->
                                <div class="w-full bg-slate-800 h-2 rounded-full overflow-hidden">
                                    <div
                                        class="bg-gradient-to-r from-indigo-500 via-sky-400 to-emerald-400 h-full transition-all duration-300"
                                        :style="{ width: `${Math.min(100, (currentExecutingStep / 6) * 100)}%` }"
                                    ></div>
                                </div>

                                <!-- Current Step Highlight -->
                                <div class="flex items-center gap-3 bg-indigo-950/50 p-3 rounded-xl border border-indigo-500/30">
                                    <RefreshCw class="w-5 h-5 text-sky-400 animate-spin shrink-0" />
                                    <div>
                                        <p class="text-xs font-bold text-white">
                                            Step {{ currentExecutingStep }} of 6: {{ liveSteps[currentExecutingStep - 1]?.title }}
                                        </p>
                                        <p class="text-[11px] text-slate-300 mt-0.5">
                                            {{ liveSteps[currentExecutingStep - 1]?.detail }}
                                        </p>
                                    </div>
                                </div>

                                <!-- Mini Checklist -->
                                <div class="flex flex-col gap-1.5 text-xs">
                                    <div
                                        v-for="step in liveSteps"
                                        :key="step.step"
                                        class="flex items-center justify-between text-[11px] py-1 border-b border-slate-800/60"
                                    >
                                        <span :class="step.status === 'completed' ? 'text-slate-300' : step.status === 'running' ? 'text-sky-300 font-bold' : 'text-slate-600'">
                                            {{ step.step }}. {{ step.title }}
                                        </span>
                                        <span v-if="step.status === 'completed'" class="text-emerald-400 flex items-center gap-1 font-semibold">
                                            <Check class="w-3 h-3" /> Done
                                        </span>
                                        <span v-else-if="step.status === 'running'" class="text-sky-400 flex items-center gap-1 animate-pulse">
                                            Running...
                                        </span>
                                        <span v-else class="text-slate-600">Queued</span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Rendered Vector Mode -->
                        <div
                            v-if="viewMode === 'render'"
                            class="w-full h-full flex items-center justify-center p-4 transition-transform duration-150 ease-out select-none"
                            :style="{ transform: `scale(${zoomLevel})` }"
                        >
                            <div
                                class="w-full max-h-[700px] flex items-center justify-center [&>svg]:w-full [&>svg]:h-auto [&>svg]:max-h-[660px] [&>svg]:drop-shadow-2xl"
                                v-html="currentData.svg"
                            ></div>
                        </div>

                        <!-- Raw XML Code View Mode -->
                        <div v-else class="w-full h-full p-6 overflow-auto max-h-[660px] bg-[#050811] text-xs font-mono">
                            <pre class="text-emerald-400 whitespace-pre-wrap leading-relaxed">{{ currentData.svg }}</pre>
                        </div>
                    </div>

                    <!-- EDUCATIONAL DESCRIPTION UNDER THE SVG -->
                    <div class="bg-[#0c1222] border border-slate-800/90 rounded-2xl p-6 shadow-xl flex flex-col gap-4">
                        <div class="flex items-center justify-between flex-wrap gap-2">
                            <div class="flex items-center gap-2">
                                <span class="px-2.5 py-1 rounded-md bg-sky-500/10 border border-sky-500/30 text-sky-400 text-xs font-bold uppercase tracking-wider">
                                    {{ currentData.category }}
                                </span>
                                <span class="text-xs text-slate-400 font-mono">viewBox: 0 0 1600 1000</span>
                            </div>

                            <div class="text-xs text-slate-400 flex items-center gap-1.5">
                                <CheckCircle2 class="w-4 h-4 text-emerald-400" />
                                <span>Verified Pedagogical Standards</span>
                            </div>
                        </div>

                        <div>
                            <h2 class="text-2xl font-black text-white tracking-tight">{{ currentData.title }}</h2>
                            <p class="text-slate-300 text-sm sm:text-base leading-relaxed mt-2.5">
                                {{ currentData.description }}
                            </p>
                        </div>

                        <!-- Key Educational Takeaways -->
                        <div v-if="currentData.key_takeaways?.length" class="mt-2 pt-4 border-t border-slate-800 flex flex-col gap-2">
                            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-400">Core Conceptual Insights</h3>
                            <ul class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs sm:text-sm text-slate-300">
                                <li v-for="(takeaway, idx) in currentData.key_takeaways" :key="idx" class="flex items-start gap-2 bg-slate-900/60 p-2.5 rounded-xl border border-slate-800/80">
                                    <div class="w-5 h-5 rounded-full bg-emerald-500/20 text-emerald-400 flex items-center justify-center shrink-0 mt-0.5 font-bold text-[10px]">
                                        {{ idx + 1 }}
                                    </div>
                                    <span class="leading-snug">{{ takeaway }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>

                <!-- RIGHT SIDEBAR: INTERACTIVE AGENT CONSOLE, PLAN VISUALIZER & HISTORY (4 COLS) -->
                <div class="lg:col-span-4 flex flex-col gap-6">
                    <!-- NAVIGATION TABS -->
                    <div class="flex items-center bg-[#0c1222] border border-slate-800 p-1 rounded-xl text-xs font-bold">
                        <button
                            @click="activeTab = 'plan'"
                            :class="[
                                'flex-1 py-2 rounded-lg transition-all flex items-center justify-center gap-1.5 cursor-pointer',
                                activeTab === 'plan' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-400 hover:text-slate-200',
                            ]"
                        >
                            <Workflow class="w-3.5 h-3.5" />
                            <span>Agent Plan</span>
                        </button>
                        <button
                            @click="activeTab = 'chat'"
                            :class="[
                                'flex-1 py-2 rounded-lg transition-all flex items-center justify-center gap-1.5 cursor-pointer',
                                activeTab === 'chat' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-400 hover:text-slate-200',
                            ]"
                        >
                            <MessageSquare class="w-3.5 h-3.5" />
                            <span>Interact</span>
                        </button>
                        <button
                            @click="activeTab = 'history'"
                            :class="[
                                'flex-1 py-2 rounded-lg transition-all flex items-center justify-center gap-1.5 cursor-pointer',
                                activeTab === 'history' ? 'bg-indigo-600 text-white shadow-sm' : 'text-slate-400 hover:text-slate-200',
                            ]"
                        >
                            <BookOpen class="w-3.5 h-3.5" />
                            <span>History</span>
                        </button>
                    </div>

                    <!-- TAB 1: LIVE AGENT PLAN & EXECUTION STEPPER -->
                    <div v-if="activeTab === 'plan'" class="bg-[#0c1222] border border-slate-800 rounded-2xl p-5 shadow-lg flex flex-col gap-4">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <Cpu class="w-4 h-4 text-emerald-400" />
                                <h3 class="text-sm font-bold text-white">Agentic Execution Plan</h3>
                            </div>
                            <span class="text-[10px] font-mono px-2 py-0.5 rounded bg-emerald-500/20 text-emerald-300 font-bold border border-emerald-500/30">
                                {{ isLoading ? 'EXECUTING' : 'VALIDATED' }}
                            </span>
                        </div>

                        <p class="text-xs text-slate-400">
                            The SvgDrawingAgent applies its 6-stage cognitive skill pipeline to analyze, structure, and synthesize this graphic.
                        </p>

                        <!-- STEP-BY-STEP FLOW VISUALIZER -->
                        <div class="relative flex flex-col gap-3 pl-2">
                            <!-- Vertical connector line -->
                            <div class="absolute left-5 top-3 bottom-3 w-0.5 bg-slate-800"></div>

                            <div
                                v-for="(step, idx) in liveSteps"
                                :key="step.step"
                                :class="[
                                    'relative flex items-start gap-3 p-3 rounded-xl border transition-all z-10',
                                    step.status === 'running'
                                        ? 'bg-indigo-950/60 border-indigo-500 ring-2 ring-indigo-500/30'
                                        : step.status === 'completed'
                                          ? 'bg-slate-900/60 border-slate-800 text-slate-200'
                                          : 'bg-slate-950/40 border-slate-900 text-slate-500',
                                ]"
                            >
                                <!-- Step Circle Icon -->
                                <div
                                    :class="[
                                        'w-6 h-6 rounded-full flex items-center justify-center shrink-0 text-[11px] font-black',
                                        step.status === 'completed'
                                            ? 'bg-emerald-500 text-slate-950'
                                            : step.status === 'running'
                                              ? 'bg-sky-400 text-slate-950 animate-pulse'
                                              : 'bg-slate-800 text-slate-500',
                                    ]"
                                >
                                    <Check v-if="step.status === 'completed'" class="w-3.5 h-3.5 stroke-[3]" />
                                    <span v-else>{{ step.step }}</span>
                                </div>

                                <div class="flex-1">
                                    <div class="flex items-center justify-between">
                                        <h4 class="text-xs font-bold" :class="step.status === 'running' ? 'text-sky-300' : 'text-white'">
                                            {{ step.title }}
                                        </h4>
                                        <span v-if="step.status === 'running'" class="text-[10px] text-sky-400 font-mono animate-pulse">
                                            active
                                        </span>
                                    </div>
                                    <p class="text-[11px] text-slate-400 mt-1 leading-relaxed">
                                        {{ step.detail }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Skill Info Footer -->
                        <div class="mt-2 pt-3 border-t border-slate-800 flex items-center justify-between text-[11px] text-slate-400">
                            <span>Skill: <code class="text-indigo-300 font-mono">educational-svg-gen</code></span>
                            <span>Model: <code class="text-emerald-300 font-mono">smartest</code></span>
                        </div>
                    </div>

                    <!-- TAB 2: INTERACTIVE CONVERSATIONAL AGENT CONSOLE (INPUT BOX) -->
                    <div v-if="activeTab === 'chat'" class="bg-[#0c1222] border border-slate-800 rounded-2xl p-5 shadow-lg flex flex-col gap-4">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-bold text-white flex items-center gap-2">
                                <MessageSquare class="w-4 h-4 text-indigo-400" />
                                <span>Interactive Conversation</span>
                            </h3>
                            <span class="text-[10px] text-slate-400">Live Agent Session</span>
                        </div>

                        <!-- Chat Message Feed -->
                        <div class="flex flex-col gap-2.5 max-h-[360px] overflow-y-auto pr-1 text-xs">
                            <div
                                v-for="msg in chatMessages"
                                :key="msg.id"
                                :class="[
                                    'p-3 rounded-xl border flex flex-col gap-1',
                                    msg.sender === 'user'
                                        ? 'bg-indigo-950/40 border-indigo-500/40 self-end max-w-[90%]'
                                        : 'bg-slate-900 border-slate-800 self-start max-w-[95%]',
                                ]"
                            >
                                <div class="flex items-center justify-between text-[10px] text-slate-400">
                                    <span class="font-bold uppercase tracking-wider" :class="msg.sender === 'user' ? 'text-indigo-300' : 'text-emerald-400'">
                                        {{ msg.sender === 'user' ? 'You' : 'SvgDrawingAgent' }}
                                    </span>
                                    <span>{{ msg.timestamp.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) }}</span>
                                </div>
                                <p class="text-slate-200 leading-relaxed">{{ msg.text }}</p>
                            </div>
                        </div>

                        <!-- Interactive Input Form -->
                        <form @submit.prevent="handleChatSubmit" class="flex flex-col gap-2 mt-2 pt-3 border-t border-slate-800">
                            <label class="text-[11px] font-semibold text-slate-300">Interact / Ask for refinements or new drawings:</label>
                            <div class="relative flex items-center">
                                <input
                                    v-model="chatInput"
                                    type="text"
                                    placeholder="Type anything (e.g. 'Show mitochondria ATP cycle', 'Explain Fourier transform')..."
                                    class="w-full pl-3 pr-10 py-2.5 rounded-xl bg-slate-900 border border-slate-700 text-xs text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                                    :disabled="isLoading"
                                />
                                <button
                                    type="submit"
                                    :disabled="isLoading || !chatInput.trim()"
                                    class="absolute right-1.5 p-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white disabled:opacity-40 transition-all cursor-pointer"
                                >
                                    <Send class="w-3.5 h-3.5" />
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- TAB 3: RECENT VISUALIZATIONS HISTORY -->
                    <div v-if="activeTab === 'history'" class="bg-[#0c1222] border border-slate-800 rounded-2xl p-5 shadow-lg flex flex-col gap-3">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-bold text-white flex items-center gap-2">
                                <BookOpen class="w-4 h-4 text-indigo-400" />
                                <span>Recent Visualizations</span>
                            </h3>
                            <span class="text-[11px] text-slate-500 font-mono">{{ history.length }} cached</span>
                        </div>

                        <div class="flex flex-col gap-2">
                            <button
                                v-for="(item, idx) in history"
                                :key="idx"
                                @click="selectHistoryItem(item)"
                                :class="[
                                    'text-left p-3 rounded-xl border transition-all flex items-start justify-between gap-3 cursor-pointer',
                                    query.toLowerCase() === item.query.toLowerCase()
                                        ? 'bg-indigo-950/40 border-indigo-500/50 text-white'
                                        : 'bg-slate-900/40 border-slate-800 text-slate-300 hover:border-slate-700 hover:text-white',
                                ]"
                            >
                                <div class="flex flex-col">
                                    <span class="font-bold text-xs">{{ item.data.title }}</span>
                                    <span class="text-[11px] text-slate-400 capitalize mt-0.5">{{ item.data.category }}</span>
                                </div>
                                <ArrowRight class="w-4 h-4 text-slate-500 shrink-0 mt-1" />
                            </button>
                        </div>
                    </div>

                    <!-- SETTINGS DRAWER / ACCORDION -->
                    <div v-if="showSettings" class="bg-[#0c1222] border border-slate-800 rounded-2xl p-5 shadow-lg flex flex-col gap-3 text-xs">
                        <div class="flex items-center justify-between">
                            <h3 class="text-sm font-bold text-white flex items-center gap-2">
                                <Settings class="w-4 h-4 text-indigo-400" />
                                <span>Provider &amp; Key Settings</span>
                            </h3>
                            <button @click="showSettings = false" class="text-slate-500 hover:text-white text-xs">Close</button>
                        </div>

                        <div>
                            <label class="text-[11px] font-semibold text-slate-400 block mb-1">Active AI Provider</label>
                            <select
                                v-model="selectedProvider"
                                class="w-full bg-[#060913] border border-slate-700 rounded-lg p-2 text-xs text-white focus:outline-none focus:border-indigo-500"
                            >
                                <option v-for="prov in availableProviders" :key="prov.id" :value="prov.id">
                                    {{ prov.name }}
                                </option>
                            </select>
                        </div>

                        <div>
                            <label class="text-[11px] font-semibold text-slate-400 block mb-1">Custom API Key (Optional override)</label>
                            <input
                                v-model="customApiKey"
                                type="password"
                                placeholder="sk-..."
                                class="w-full bg-[#060913] border border-slate-700 rounded-lg p-2 text-xs text-white focus:outline-none focus:border-indigo-500 font-mono"
                            />
                            <p class="text-[10px] text-slate-500 mt-1">Leave empty to use credentials defined in .env.</p>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</template>
