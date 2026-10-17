<script setup lang="ts">
import { Head, useForm, Link } from '@inertiajs/vue3';
import { Eye, EyeOff } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{
    status?: string;
    canResetPassword?: boolean;
}>();

const form = useForm({
    email: '', // We use email as the field name to match Fortify defaults for now
    password: '',
    remember: false,
});

const showPassword = ref(false);

const submit = () => {
    form.post('/login', {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Log In | GameBoxLive" />
    
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
            
            <div v-if="status" class="mb-4 text-center text-sm font-medium text-emerald-600">
                {{ status }}
            </div>

            <!-- Tabs -->
            <div class="flex bg-slate-50 p-1 rounded-full mb-8">
                <Link href="/register" class="flex-1 text-center py-2.5 rounded-full text-sm font-medium text-slate-500 hover:text-slate-800 transition-colors">
                    Create account
                </Link>
                <div class="flex-1 text-center py-2.5 bg-white rounded-full shadow-sm text-sm font-bold text-slate-900 cursor-default">
                    Log in
                </div>
            </div>

            <form @submit.prevent="submit" class="space-y-5">
                
                <!-- Identifier (Email/Username) -->
                <div class="space-y-1.5">
                    <label for="email" class="block text-sm font-semibold text-slate-700">Email address</label>
                    <input id="email" v-model="form.email" type="text" placeholder="you@email.com" required autofocus
                        class="w-full bg-slate-50 border-0 focus:ring-2 focus:ring-emerald-500 rounded-2xl h-12 px-4 text-slate-900 placeholder-slate-400" />
                    <div v-if="form.errors.email" class="text-red-500 text-xs">{{ form.errors.email }}</div>
                </div>

                <!-- Password -->
                <div class="space-y-1.5">
                    <div class="flex justify-between items-center">
                        <label for="password" class="block text-sm font-semibold text-slate-700">Password</label>
                        <Link v-if="canResetPassword" href="/forgot-password" class="text-xs text-blue-500 hover:underline">
                            Forgot password?
                        </Link>
                    </div>
                    <div class="relative">
                        <input id="password" v-model="form.password" :type="showPassword ? 'text' : 'password'" required
                            class="w-full bg-slate-50 border-0 focus:ring-2 focus:ring-emerald-500 rounded-2xl h-12 px-4 pr-12 text-slate-900" />
                        <button type="button" @click="showPassword = !showPassword" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600">
                            <Eye v-if="!showPassword" class="w-5 h-5" />
                            <EyeOff v-else class="w-5 h-5" />
                        </button>
                    </div>
                    <div v-if="form.errors.password" class="text-red-500 text-xs">{{ form.errors.password }}</div>
                </div>

                <!-- Remember Me -->
                <div class="flex items-center h-5 pt-1">
                    <input id="remember" v-model="form.remember" type="checkbox"
                        class="w-4 h-4 rounded border-slate-300 text-[#51a76c] focus:ring-[#51a76c]" />
                    <label for="remember" class="ml-2 text-sm text-slate-600">
                        Remember me
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
                        Log in
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
