<script setup lang="ts">
import { Head, useForm, Link } from '@inertiajs/vue3';
import { Eye, EyeOff } from 'lucide-vue-next';
import { ref } from 'vue';

const form = useForm({
    name: '',
    username: '',
    password: '',
    email: '',
    terms: false,
});

const showPassword = ref(false);

const submit = () => {
    form.post('/register', {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Sign Up | GameBoxLive" />
    
    <div class="min-h-screen flex flex-col items-center pt-12 sm:pt-20 bg-gradient-to-b from-[#dceefb] to-[#f4f9fd] font-sans px-4">
        
        <!-- Header -->
        <div class="text-center mb-8">
            <div class="text-4xl mb-2 text-center flex justify-center">👑</div>
            <h1 class="text-[32px] font-black tracking-tight text-slate-900 flex items-center justify-center gap-2">
                GameBoxLive 
                <span class="bg-slate-800 text-white text-[10px] px-1.5 py-0.5 rounded-full font-bold ml-1">18+</span>
            </h1>
            <p class="text-slate-500 mt-2 text-[15px] font-medium">Live your GameBoxLive story with real people.</p>
        </div>

        <!-- Card -->
        <div class="w-full max-w-[420px] bg-white rounded-[2rem] shadow-[0_8px_30px_rgb(0,0,0,0.04)] p-6 sm:p-8 mb-12">
            
            <!-- Tabs -->
            <div class="flex bg-slate-50 p-1 rounded-full mb-8">
                <div class="flex-1 text-center py-2.5 bg-white rounded-full shadow-sm text-sm font-bold text-slate-900 cursor-default">
                    Create account
                </div>
                <Link href="/login" class="flex-1 text-center py-2.5 rounded-full text-sm font-medium text-slate-500 hover:text-slate-800 transition-colors">
                    Log in
                </Link>
            </div>

            <form @submit.prevent="submit" class="space-y-5">
                
                <!-- Your name -->
                <div class="space-y-1.5">
                    <label for="name" class="block text-sm font-semibold text-slate-700">Your name</label>
                    <input id="name" v-model="form.name" type="text" placeholder="e.g. Tolu Adebayo" required
                        class="w-full bg-slate-50 border-0 focus:ring-2 focus:ring-emerald-500 rounded-2xl h-12 px-4 text-slate-900 placeholder-slate-400" />
                    <div v-if="form.errors.name" class="text-red-500 text-xs">{{ form.errors.name }}</div>
                </div>

                <!-- Username -->
                <div class="space-y-1.5">
                    <label for="username" class="block text-sm font-semibold text-slate-700">Username</label>
                    <input id="username" v-model="form.username" type="text" placeholder="@tolu_eko" required
                        class="w-full bg-slate-50 border-0 focus:ring-2 focus:ring-emerald-500 rounded-2xl h-12 px-4 text-slate-900 placeholder-slate-400" />
                    <p class="text-xs text-slate-500 mt-1">This is your Sim's name in GameBoxLive.</p>
                    <div v-if="form.errors.username" class="text-red-500 text-xs">{{ form.errors.username }}</div>
                </div>

                <!-- Password -->
                <div class="space-y-1.5">
                    <label for="password" class="block text-sm font-semibold text-slate-700">Password</label>
                    <div class="relative">
                        <input id="password" v-model="form.password" :type="showPassword ? 'text' : 'password'" required
                            class="w-full bg-slate-50 border-0 focus:ring-2 focus:ring-emerald-500 rounded-2xl h-12 px-4 pr-12 text-slate-900" />
                        <button type="button" @click="showPassword = !showPassword" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600">
                            <Eye v-if="!showPassword" class="w-5 h-5" />
                            <EyeOff v-else class="w-5 h-5" />
                        </button>
                    </div>
                    <p class="text-xs text-slate-500 mt-1">At least 8 characters. Not 123456 😅</p>
                    <div v-if="form.errors.password" class="text-red-500 text-xs">{{ form.errors.password }}</div>
                </div>

                <!-- Email -->
                <div class="space-y-1.5">
                    <label for="email" class="block text-sm font-semibold text-slate-700">Email (optional)</label>
                    <input id="email" v-model="form.email" type="email" placeholder="you@email.com"
                        class="w-full bg-slate-50 border-0 focus:ring-2 focus:ring-emerald-500 rounded-2xl h-12 px-4 text-slate-900 placeholder-slate-400" />
                    <p class="text-xs text-slate-500 mt-1">You can log in with it too. We never send you codes.</p>
                    <div v-if="form.errors.email" class="text-red-500 text-xs">{{ form.errors.email }}</div>
                </div>

                <!-- Terms Checkbox -->
                <div class="flex items-start gap-3 pt-2">
                    <div class="flex items-center h-5">
                        <input id="terms" v-model="form.terms" type="checkbox" required
                            class="w-4 h-4 rounded border-slate-300 text-[#51a76c] focus:ring-[#51a76c]" />
                    </div>
                    <label for="terms" class="text-xs text-slate-600 leading-snug">
                        I'm <strong>18+</strong> and I agree to the <a href="#" class="text-blue-500 hover:underline">Terms</a>, 
                        <a href="#" class="text-blue-500 hover:underline">Privacy Policy</a> and 
                        <a href="#" class="text-blue-500 hover:underline">Disclaimer</a>.
                    </label>
                </div>

                <!-- Submit Button -->
                <div class="pt-4">
                    <button type="submit" :disabled="form.processing"
                        class="w-full h-[52px] bg-[#51a76c] hover:bg-[#45935d] text-white font-bold text-[15px] rounded-full transition-all duration-200 transform active:scale-95 flex items-center justify-center disabled:opacity-70 disabled:cursor-not-allowed">
                        <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-5 w-5 text-white" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        Sign up - it's free
                    </button>
                    <p class="text-[11px] text-slate-400 text-center mt-4">
                        Your Sim will live in the same world as everyone else who signs up.
                    </p>
                </div>

            </form>
        </div>
    </div>
</template>

<style>
/* Empty style block to satisfy Vite HMR */
</style>
