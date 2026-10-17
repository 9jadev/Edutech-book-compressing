<script setup lang="ts">
import { shallowRef } from 'vue';
import { useLoop } from '@tresjs/core';

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

const props = defineProps<{
    meshes: MeshItem[];
}>();

const sceneGroupRef = shallowRef();
const meshRefs = shallowRef<Record<string, any>>({});

const setMeshRef = (el: any, id: string) => {
    if (el) {
        meshRefs.value[id] = el;
    }
};

const { onBeforeRender } = useLoop();

onBeforeRender(({ elapsed, delta }) => {
    if (sceneGroupRef.value) {
        // Very subtle idle breathing of the entire platform
        sceneGroupRef.value.position.y = Math.sin(elapsed * 0.8) * 0.03;
    }

    props.meshes.forEach((mesh) => {
        const obj = meshRefs.value[mesh.id];
        if (!obj) return;

        if (mesh.animation === 'float') {
            obj.position.y = (mesh.position[1] || 0) + Math.sin(elapsed * 2 + (mesh.position[0] || 0)) * 0.15;
        } else if (mesh.animation === 'spin-y') {
            obj.rotation.y += delta * 0.6;
        } else if (mesh.animation === 'spin-x') {
            obj.rotation.x += delta * 0.6;
        } else if (mesh.animation === 'pulse') {
            const s = 1 + Math.sin(elapsed * 3) * 0.06;
            obj.scale.set(s, s, s);
        }
    });
});
</script>

<template>
    <TresGroup ref="sceneGroupRef">
        <TresMesh
            v-for="mesh in meshes"
            :key="mesh.id"
            :ref="(el) => setMeshRef(el, mesh.id)"
            :position="mesh.position"
            :rotation="mesh.rotation || [0, 0, 0]"
            :scale="mesh.scale || [1, 1, 1]"
        >
            <!-- Dynamic Geometry Types -->
            <TresBoxGeometry v-if="mesh.type === 'box'" :args="mesh.args || [1, 1, 1]" />
            <TresSphereGeometry v-else-if="mesh.type === 'sphere'" :args="mesh.args || [1, 16, 16]" />
            <TresCylinderGeometry v-else-if="mesh.type === 'cylinder'" :args="mesh.args || [1, 1, 1, 16]" />
            <TresConeGeometry v-else-if="mesh.type === 'cone'" :args="mesh.args || [1, 1, 16]" />
            <TresTorusGeometry v-else-if="mesh.type === 'torus'" :args="mesh.args || [1, 0.2, 16, 32]" />
            <TresPlaneGeometry v-else-if="mesh.type === 'plane'" :args="mesh.args || [2, 2]" />
            <TresBoxGeometry v-else :args="mesh.args || [1, 1, 1]" />

            <!-- PBR Standard Material -->
            <TresMeshStandardMaterial
                :color="mesh.color"
                :metalness="mesh.metalness ?? 0.2"
                :roughness="mesh.roughness ?? 0.6"
                :flat-shading="mesh.flat_shading ?? true"
                :emissive="mesh.emissive || '#000000'"
                :emissive-intensity="mesh.emissive_intensity || 0.0"
            />
        </TresMesh>
    </TresGroup>
</template>
