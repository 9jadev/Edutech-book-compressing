<script setup lang="ts">
import { shallowRef, computed } from 'vue';
import { useLoop } from '@tresjs/core';

const props = defineProps<{
    body: string;
    hairstyle: string;
    outfitColors: { top: string; bottom: string };
}>();

// Character animation refs
const characterRef = shallowRef();
const leftArmRef = shallowRef();
const rightArmRef = shallowRef();

const { onBeforeRender } = useLoop();
onBeforeRender(({ elapsed }) => {
    if (characterRef.value) {
        // Idle breathing (gentle up and down)
        characterRef.value.position.y = Math.sin(elapsed * 2) * 0.02;
    }
    if (leftArmRef.value) {
        // Gentle arm swing
        leftArmRef.value.rotation.x = Math.sin(elapsed * 2) * 0.05;
    }
    if (rightArmRef.value) {
        rightArmRef.value.rotation.x = Math.sin(elapsed * 2 + Math.PI) * 0.05;
    }
});
</script>

<template>
    <TresGroup ref="characterRef">
        <!-- WOMAN MODEL (Detailed Low Poly) -->
        <TresGroup v-if="body === 'Woman'">
            <!-- Head & Hair -->
            <TresMesh :position="[0, 1.5, 0]">
                <TresSphereGeometry :args="[0.11, 16, 16]" />
                <TresMeshStandardMaterial :flat-shading="true" color="#6b4c3a" :roughness="0.4" />
            </TresMesh>
            
            <!-- Dynamic Hairstyles -->
            <TresGroup v-if="hairstyle === 'Afro'">
                <TresMesh :position="[0, 1.55, -0.02]">
                    <TresSphereGeometry :args="[0.15, 12, 12]" />
                    <TresMeshStandardMaterial :flat-shading="true" color="#111111" :roughness="0.9" />
                </TresMesh>
            </TresGroup>
            <TresGroup v-else-if="hairstyle === 'Bun'">
                <TresMesh :position="[0, 1.62, -0.05]">
                    <TresSphereGeometry :args="[0.08, 12, 12]" />
                    <TresMeshStandardMaterial :flat-shading="true" color="#111111" :roughness="0.8" />
                </TresMesh>
                <TresMesh :position="[0, 1.53, -0.02]">
                    <TresBoxGeometry :args="[0.24, 0.1, 0.24]" />
                    <TresMeshStandardMaterial :flat-shading="true" color="#111111" :roughness="0.8" />
                </TresMesh>
            </TresGroup>
            <TresGroup v-else-if="hairstyle === 'Gele'">
                <TresMesh :position="[0, 1.62, 0]" :rotation="[0.2, 0.5, 0.1]">
                    <TresBoxGeometry :args="[0.3, 0.25, 0.15]" />
                    <TresMeshStandardMaterial :flat-shading="true" color="#c026d3" :roughness="0.6" />
                </TresMesh>
            </TresGroup>
            <TresGroup v-else> <!-- Default/Braids -->
                <TresMesh :position="[0, 1.53, -0.02]">
                    <TresBoxGeometry :args="[0.24, 0.22, 0.24]" />
                    <TresMeshStandardMaterial :flat-shading="true" color="#111111" :roughness="0.8" />
                </TresMesh>
            </TresGroup>

            <!-- Neck -->
            <TresMesh :position="[0, 1.38, 0]">
                <TresCylinderGeometry :args="[0.04, 0.04, 0.1]" />
                <TresMeshStandardMaterial :flat-shading="true" color="#6b4c3a" :roughness="0.4" />
            </TresMesh>
            
            <!-- Torso (Top) -->
            <TresMesh :position="[0, 1.18, 0]">
                <TresBoxGeometry :args="[0.28, 0.32, 0.16]" />
                <TresMeshStandardMaterial :flat-shading="true" :color="outfitColors.top" :roughness="0.7" />
            </TresMesh>
            <!-- Waist -->
            <TresMesh :position="[0, 0.95, 0]">
                <TresBoxGeometry :args="[0.23, 0.2, 0.15]" />
                <TresMeshStandardMaterial :flat-shading="true" :color="outfitColors.top" :roughness="0.7" />
            </TresMesh>
            <!-- Skirt / Bottom -->
            <TresMesh :position="[0, 0.7, 0]">
                <TresCylinderGeometry :args="[0.14, 0.24, 0.35, 16]" />
                <TresMeshStandardMaterial :flat-shading="true" :color="outfitColors.bottom" :roughness="0.8" />
            </TresMesh>
            
            <!-- Legs -->
            <TresMesh :position="[-0.09, 0.4, 0]">
                <TresCylinderGeometry :args="[0.06, 0.05, 0.35]" />
                <TresMeshStandardMaterial :flat-shading="true" color="#6b4c3a" :roughness="0.4" />
            </TresMesh>
            <TresMesh :position="[-0.09, 0.1, 0]">
                <TresCylinderGeometry :args="[0.05, 0.04, 0.3]" />
                <TresMeshStandardMaterial :flat-shading="true" color="#6b4c3a" :roughness="0.4" />
            </TresMesh>
            <TresMesh :position="[0.09, 0.4, 0]">
                <TresCylinderGeometry :args="[0.06, 0.05, 0.35]" />
                <TresMeshStandardMaterial :flat-shading="true" color="#6b4c3a" :roughness="0.4" />
            </TresMesh>
            <TresMesh :position="[0.09, 0.1, 0]">
                <TresCylinderGeometry :args="[0.05, 0.04, 0.3]" />
                <TresMeshStandardMaterial :flat-shading="true" color="#6b4c3a" :roughness="0.4" />
            </TresMesh>
            
            <!-- Arms (Animated) -->
            <TresGroup ref="leftArmRef" :position="[-0.16, 1.25, 0]">
                <TresMesh :position="[-0.02, -0.15, 0]" :rotation="[0, 0, -0.2]">
                    <TresCylinderGeometry :args="[0.04, 0.035, 0.3]" />
                    <TresMeshStandardMaterial :flat-shading="true" color="#6b4c3a" :roughness="0.4" />
                </TresMesh>
                <TresMesh :position="[-0.08, -0.42, 0]" :rotation="[0, 0, -0.1]">
                    <TresCylinderGeometry :args="[0.035, 0.03, 0.3]" />
                    <TresMeshStandardMaterial :flat-shading="true" color="#6b4c3a" :roughness="0.4" />
                </TresMesh>
            </TresGroup>
            
            <TresGroup ref="rightArmRef" :position="[0.16, 1.25, 0]">
                <TresMesh :position="[0.02, -0.15, 0]" :rotation="[0, 0, 0.2]">
                    <TresCylinderGeometry :args="[0.04, 0.035, 0.3]" />
                    <TresMeshStandardMaterial :flat-shading="true" color="#6b4c3a" :roughness="0.4" />
                </TresMesh>
                <TresMesh :position="[0.08, -0.42, 0]" :rotation="[0, 0, 0.1]">
                    <TresCylinderGeometry :args="[0.035, 0.03, 0.3]" />
                    <TresMeshStandardMaterial :flat-shading="true" color="#6b4c3a" :roughness="0.4" />
                </TresMesh>
            </TresGroup>
        </TresGroup>

        <!-- MAN MODEL (Detailed Low Poly) -->
        <TresGroup v-else>
            <!-- Head & Hair -->
            <TresMesh :position="[0, 1.55, 0]">
                <TresSphereGeometry :args="[0.12, 16, 16]" />
                <TresMeshStandardMaterial :flat-shading="true" color="#6b4c3a" :roughness="0.4" />
            </TresMesh>
            
            <!-- Dynamic Hairstyles (Man) -->
            <TresGroup v-if="hairstyle === 'Afro'">
                <TresMesh :position="[0, 1.62, 0]">
                    <TresSphereGeometry :args="[0.16, 12, 12]" />
                    <TresMeshStandardMaterial :flat-shading="true" color="#111111" :roughness="0.9" />
                </TresMesh>
            </TresGroup>
            <TresGroup v-else-if="hairstyle === 'Low cut'">
                <TresMesh :position="[0, 1.57, -0.01]">
                    <TresBoxGeometry :args="[0.22, 0.08, 0.22]" />
                    <TresMeshStandardMaterial :flat-shading="true" color="#111111" :roughness="0.8" />
                </TresMesh>
            </TresGroup>
            <TresGroup v-else> <!-- Classic -->
                <TresMesh :position="[0, 1.62, -0.01]">
                    <TresBoxGeometry :args="[0.23, 0.1, 0.24]" />
                    <TresMeshStandardMaterial :flat-shading="true" color="#111111" :roughness="0.8" />
                </TresMesh>
            </TresGroup>

            <!-- Neck -->
            <TresMesh :position="[0, 1.42, 0]">
                <TresCylinderGeometry :args="[0.05, 0.05, 0.1]" />
                <TresMeshStandardMaterial :flat-shading="true" color="#6b4c3a" :roughness="0.4" />
            </TresMesh>
            <!-- Torso (Chest) -->
            <TresMesh :position="[0, 1.22, 0]">
                <TresBoxGeometry :args="[0.38, 0.35, 0.18]" />
                <TresMeshStandardMaterial :flat-shading="true" :color="outfitColors.top" :roughness="0.8" />
            </TresMesh>
            <!-- Torso (Stomach) -->
            <TresMesh :position="[0, 0.95, 0]">
                <TresBoxGeometry :args="[0.32, 0.25, 0.16]" />
                <TresMeshStandardMaterial :flat-shading="true" :color="outfitColors.top" :roughness="0.8" />
            </TresMesh>
            <!-- Pelvis -->
            <TresMesh :position="[0, 0.75, 0]">
                <TresBoxGeometry :args="[0.32, 0.15, 0.18]" />
                <TresMeshStandardMaterial :flat-shading="true" :color="outfitColors.bottom" :roughness="0.9" />
            </TresMesh>
            
            <!-- Legs -->
            <TresMesh :position="[-0.1, 0.52, 0]">
                <TresBoxGeometry :args="[0.12, 0.35, 0.15]" />
                <TresMeshStandardMaterial :flat-shading="true" :color="outfitColors.bottom" :roughness="0.9" />
            </TresMesh>
            <TresMesh :position="[-0.1, 0.2, 0]">
                <TresBoxGeometry :args="[0.1, 0.35, 0.13]" />
                <TresMeshStandardMaterial :flat-shading="true" :color="outfitColors.bottom" :roughness="0.9" />
            </TresMesh>
            <TresMesh :position="[0.1, 0.52, 0]">
                <TresBoxGeometry :args="[0.12, 0.35, 0.15]" />
                <TresMeshStandardMaterial :flat-shading="true" :color="outfitColors.bottom" :roughness="0.9" />
            </TresMesh>
            <TresMesh :position="[0.1, 0.2, 0]">
                <TresBoxGeometry :args="[0.1, 0.35, 0.13]" />
                <TresMeshStandardMaterial :flat-shading="true" :color="outfitColors.bottom" :roughness="0.9" />
            </TresMesh>
            
            <!-- Arms (Animated) -->
            <TresGroup ref="leftArmRef" :position="[-0.2, 1.3, 0]">
                <TresMesh :position="[-0.04, -0.15, 0]" :rotation="[0, 0, -0.15]">
                    <TresBoxGeometry :args="[0.08, 0.35, 0.1]" />
                    <TresMeshStandardMaterial :flat-shading="true" color="#6b4c3a" :roughness="0.4" />
                </TresMesh>
                <TresMesh :position="[-0.09, -0.45, 0]" :rotation="[0, 0, -0.05]">
                    <TresBoxGeometry :args="[0.07, 0.3, 0.08]" />
                    <TresMeshStandardMaterial :flat-shading="true" color="#6b4c3a" :roughness="0.4" />
                </TresMesh>
            </TresGroup>
            
            <TresGroup ref="rightArmRef" :position="[0.2, 1.3, 0]">
                <TresMesh :position="[0.04, -0.15, 0]" :rotation="[0, 0, 0.15]">
                    <TresBoxGeometry :args="[0.08, 0.35, 0.1]" />
                    <TresMeshStandardMaterial :flat-shading="true" color="#6b4c3a" :roughness="0.4" />
                </TresMesh>
                <TresMesh :position="[0.09, -0.45, 0]" :rotation="[0, 0, 0.05]">
                    <TresBoxGeometry :args="[0.07, 0.3, 0.08]" />
                    <TresMeshStandardMaterial :flat-shading="true" color="#6b4c3a" :roughness="0.4" />
                </TresMesh>
            </TresGroup>
        </TresGroup>
    </TresGroup>
</template>
