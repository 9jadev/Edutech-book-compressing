<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { TresCanvas } from '@tresjs/core';
import { OrbitControls, ContactShadows, Environment } from '@tresjs/cientos';
import DummyCharacter from '@/components/World/DummyCharacter.vue';

const form = useForm({
    body: 'Woman',
    hairstyle: 'Afro',
    outfit: 'Owambe',
});

const submit = () => {
    form.post('/setup-character');
};

// Dynamic Colors based on Outfit
const outfitColors = computed(() => {
    if (form.body === 'Woman') {
        switch (form.outfit) {
            case 'Office': return { top: '#f8fafc', bottom: '#0f172a' }; // White top, black skirt
            case 'Owambe': return { top: '#c026d3', bottom: '#f59e0b' }; // Magenta top, Gold skirt
            case 'Site work': return { top: '#ea580c', bottom: '#1d4ed8' }; // Orange vest, blue jeans
            default: return { top: '#facc15', bottom: '#1e3a8a' }; // Casual: Yellow top, dark blue skirt
        }
    } else {
        switch (form.outfit) {
            case 'Office': return { top: '#f8fafc', bottom: '#475569' }; // White shirt, gray pants
            case 'Owambe': return { top: '#f8fafc', bottom: '#f8fafc' }; // Full white Agbada
            case 'Site work': return { top: '#ea580c', bottom: '#1d4ed8' }; // Orange shirt, blue jeans
            default: return { top: '#3b82f6', bottom: '#1e293b' }; // Casual: Blue shirt, dark pants
        }
    }
});
</script>

<template>
    <Head title="Setup Character" />

    <div class="min-h-screen flex w-full bg-gradient-to-br from-[#eef5fa] to-[#d8e8f5] text-slate-900 overflow-hidden relative font-sans">
        
        <!-- Header -->
        <div class="absolute top-0 left-0 right-0 h-16 flex items-center justify-between px-6 z-20 backdrop-blur-sm bg-white/30 border-b border-white/40">
            <button class="w-10 h-10 bg-white/80 rounded-full flex items-center justify-center shadow-sm text-slate-400 hover:text-slate-600 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>
            </button>
            <div class="flex flex-col items-center">
                <span class="font-black text-slate-900 mb-1 uppercase tracking-widest text-[10px]">Look</span>
                <div class="flex gap-1.5">
                    <div class="h-1.5 w-8 bg-[#32ba6b] rounded-full shadow-[0_0_8px_rgba(50,186,107,0.5)]"></div>
                    <div class="h-1.5 w-6 bg-slate-300/50 rounded-full"></div>
                    <div class="h-1.5 w-6 bg-slate-300/50 rounded-full"></div>
                </div>
            </div>
            <div class="flex items-center gap-3">
                <button @click="submit" class="bg-[#32ba6b] hover:bg-[#2ca65f] text-white px-6 py-2.5 rounded-full font-bold text-sm shadow-[0_4px_14px_0_rgba(50,186,107,0.39)] transition-all active:scale-95 hover:-translate-y-0.5">
                    Next Step
                </button>
            </div>
        </div>

        <!-- LEFT SIDE: 3D Character Preview -->
        <div class="flex-1 relative hidden lg:block z-10 pt-16">
            <TresCanvas clear-color="transparent" window-size antialias :pixel-ratio="2">
                <TresPerspectiveCamera :position="[0, 1.2, 3.5]" :fov="45" />
                <OrbitControls :enableDamping="true" :autoRotate="false" :maxPolarAngle="Math.PI/2 - 0.05" :minDistance="2" :maxDistance="6" />
                
                <TresAmbientLight :intensity="1.2" color="#ffffff" />
                <TresDirectionalLight :position="[5, 8, 5]" :intensity="1.5" color="#ffffff" cast-shadow />
                <TresPointLight :position="[-5, 3, -5]" :intensity="2" color="#c084fc" :distance="20" />
                
                <Suspense>
                    <DummyCharacter :body="form.body" :hairstyle="form.hairstyle" :outfit-colors="outfitColors" />

                    <ContactShadows :resolution="512" :scale="5" :blur="2" :opacity="0.4" :far="2" :position="[0, 0.01, 0]" />
                </Suspense>
            </TresCanvas>
            
            <div class="absolute bottom-10 w-full text-center text-xs text-slate-400 font-medium pointer-events-none tracking-widest uppercase">
                Drag to spin
            </div>
        </div>

        <!-- RIGHT SIDE: Configuration Panel -->
        <div class="w-full lg:w-[460px] p-4 lg:p-6 lg:pt-24 z-20 flex flex-col h-screen">
            <div class="bg-white rounded-[2rem] shadow-[0_8px_30px_rgb(0,0,0,0.04)] p-6 flex-1 flex flex-col overflow-hidden relative">
                
                <!-- User tag -->
                <div class="bg-slate-50 rounded-2xl p-4 flex items-center justify-between mb-8">
                    <span class="font-bold text-slate-900 tracking-tight">@{{ $page.props.auth.user.username || 'player' }}</span>
                    <span class="text-xs text-slate-400">your Sim's name</span>
                </div>

                <div class="flex-1 overflow-y-auto pr-2 custom-scrollbar">
                    
                    <!-- Body -->
                    <div class="mb-8">
                        <h3 class="text-sm font-medium text-slate-500 mb-3">Body</h3>
                        <div class="flex gap-2">
                            <button type="button" @click="form.body = 'Woman'"
                                :class="['flex-1 text-center py-2.5 rounded-full text-sm font-bold transition-none', form.body === 'Woman' ? 'bg-[#1e2336] text-white' : 'bg-slate-50 text-slate-600 hover:bg-slate-100']">
                                Woman
                            </button>
                            <button type="button" @click="form.body = 'Man'"
                                :class="['flex-1 text-center py-2.5 rounded-full text-sm font-bold transition-none', form.body === 'Man' ? 'bg-[#1e2336] text-white' : 'bg-slate-50 text-slate-600 hover:bg-slate-100']">
                                Man
                            </button>
                        </div>
                    </div>

                    <!-- Hairstyle -->
                    <div class="mb-8">
                        <h3 class="text-sm font-medium text-slate-500 mb-3">Hairstyle</h3>
                        <div class="flex flex-wrap gap-2">
                            <button v-for="style in ['Braids', 'Afro', 'Bun', 'Ponytail', 'Long', 'Locs', 'Low cut', 'Gele', 'Classic']" :key="style"
                                type="button" @click="form.hairstyle = style"
                                :class="['px-5 py-2.5 rounded-full text-sm font-bold transition-none', form.hairstyle === style ? 'bg-[#1e2336] text-white' : 'bg-slate-50 text-slate-600 hover:bg-slate-100']">
                                {{ style }}
                            </button>
                        </div>
                    </div>

                    <!-- Outfit -->
                    <div class="mb-8">
                        <h3 class="text-sm font-medium text-slate-500 mb-3">Outfit</h3>
                        <div class="flex flex-wrap gap-2">
                            <button v-for="outfit in ['Casual', 'Office', 'Owambe', 'Site work']" :key="outfit"
                                type="button" @click="form.outfit = outfit"
                                :class="['px-5 py-2.5 rounded-full text-sm font-bold transition-none', form.outfit === outfit ? 'bg-[#1e2336] text-white' : 'bg-slate-50 text-slate-600 hover:bg-slate-100']">
                                {{ outfit }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Footer button -->
                <div class="pt-4 mt-auto border-t border-transparent">
                    <button @click="submit" :disabled="form.processing"
                        class="w-full h-[52px] bg-[#32ba6b] hover:bg-[#2ca65f] text-white font-bold text-[15px] rounded-full transition-transform active:scale-95 flex items-center justify-center disabled:opacity-70 disabled:cursor-not-allowed">
                        <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-5 w-5 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Continue
                    </button>
                </div>
                
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Hide scrollbar for a cleaner look but keep functionality */
.custom-scrollbar::-webkit-scrollbar {
    width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: #e2e8f0;
    border-radius: 4px;
}
</style>
