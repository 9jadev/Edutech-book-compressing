<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { FileText, Plus, Trash2, Upload } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PdfStatusBadge from '@/components/PdfStatusBadge.vue';
import type { PdfStatus } from '@/components/PdfStatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Chapter = {
    id?: number;
    chapter_name: string;
    start_page?: number;
    end_page?: number;
    file_path?: string | null;
    url?: string | null;
    status?: 'pending' | 'done' | 'failed';
    runtime_error?: string | null;
};

type PdfDocument = {
    id: number;
    original_name: string;
    file_path: string;
    url: string;
    file_size: number;
    status: PdfStatus;
    uploaded_by: string | null;
    created_at: string;
    chapters: Chapter[];
};

defineProps<{
    documents: PdfDocument[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'PDF Uploads',
                href: '/pdfs',
            },
        ],
    },
});

const fileInput = ref<HTMLInputElement | null>(null);
const successMessage = ref('');

const form = useForm<{
    pdf: File | null;
    header_text: string;
    chapters: Chapter[];
}>({
    pdf: null,
    header_text: '',
    chapters: [],
});

function onFileChange(event: Event) {
    const target = event.target as HTMLInputElement;
    form.pdf = target.files?.[0] ?? null;
}

function addChapter() {
    form.chapters.push({ chapter_name: '' });
}

function removeChapter(index: number) {
    form.chapters.splice(index, 1);
}

function submit() {
    successMessage.value = '';
    form.post('/pdfs', {
        forceFormData: true,
        onSuccess: () => {
            form.reset();
            if (fileInput.value) {
                fileInput.value.value = '';
            }
            successMessage.value =
                'PDF uploaded. Its chapters are being split in the background.';
        },
    });
}

function chapterError(index: number, field: string): string | undefined {
    return (form.errors as Record<string, string>)[
        `chapters.${index}.${field}`
    ];
}

function formatSize(bytes: number): string {
    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
</script>

<template>
    <Head title="PDF Uploads" />

    <div class="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
        <Card>
            <CardHeader>
                <CardTitle>Upload a PDF</CardTitle>
                <CardDescription>
                    Only PDF files are accepted. Optionally add chapter bounds
                    with their start and end pages.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form class="flex flex-col gap-6" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="pdf">PDF file</Label>
                        <input
                            id="pdf"
                            ref="fileInput"
                            type="file"
                            accept="application/pdf,.pdf"
                            class="block w-full text-sm file:mr-4 file:rounded-md file:border-0 file:bg-primary file:px-4 file:py-2 file:text-sm file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                            @change="onFileChange"
                        />
                        <InputError :message="form.errors.pdf" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="header_text"
                            >Repeated header/footer text (optional)</Label
                        >
                        <textarea
                            id="header_text"
                            v-model="form.header_text"
                            rows="2"
                            placeholder="Created by Bernard Brochez"
                            class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 dark:bg-input/30"
                        />
                        <p class="text-xs text-muted-foreground">
                            One per line. Lines containing this text are removed
                            from every page before the chapters are split into
                            lessons.
                        </p>
                        <InputError :message="form.errors.header_text" />
                    </div>

                    <div class="grid gap-3">
                        <div class="flex items-center justify-between">
                            <Label>Chapters</Label>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="addChapter"
                            >
                                <Plus />
                                Add chapter
                            </Button>
                        </div>

                        <p
                            v-if="form.chapters.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            No chapters added yet.
                        </p>

                        <div
                            v-for="(chapter, index) in form.chapters"
                            :key="index"
                            class="grid gap-3 rounded-lg border p-3 sm:grid-cols-[1fr_8rem_8rem_auto] sm:items-start"
                        >
                            <div class="grid gap-1">
                                <Input
                                    v-model="chapter.chapter_name"
                                    placeholder="Chapter name"
                                />
                                <InputError
                                    :message="
                                        chapterError(index, 'chapter_name')
                                    "
                                />
                            </div>
                            <div class="grid gap-1">
                                <Input
                                    v-model.number="chapter.start_page"
                                    type="number"
                                    min="1"
                                    placeholder="Start page"
                                />
                                <InputError
                                    :message="chapterError(index, 'start_page')"
                                />
                            </div>
                            <div class="grid gap-1">
                                <Input
                                    v-model.number="chapter.end_page"
                                    type="number"
                                    min="1"
                                    placeholder="End page"
                                />
                                <InputError
                                    :message="chapterError(index, 'end_page')"
                                />
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                @click="removeChapter(index)"
                            >
                                <Trash2 />
                            </Button>
                        </div>
                    </div>

                    <div class="flex items-center gap-4">
                        <Button type="submit" :disabled="form.processing">
                            <Upload />
                            {{ form.processing ? 'Uploading…' : 'Upload' }}
                        </Button>
                        <progress
                            v-if="form.progress"
                            :value="form.progress.percentage"
                            max="100"
                            class="h-2 w-40"
                        />
                        <p
                            v-if="successMessage"
                            class="text-sm text-green-600 dark:text-green-400"
                        >
                            {{ successMessage }}
                        </p>
                    </div>
                </form>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Uploaded PDFs</CardTitle>
            </CardHeader>
            <CardContent>
                <p
                    v-if="documents.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    Nothing uploaded yet.
                </p>
                <ul v-else class="flex flex-col gap-4">
                    <li
                        v-for="doc in documents"
                        :key="doc.id"
                        class="rounded-lg border p-4"
                    >
                        <div class="flex items-start gap-3">
                            <FileText class="mt-0.5 size-5 shrink-0" />
                            <div class="min-w-0 flex-1">
                                <a
                                    :href="doc.url"
                                    target="_blank"
                                    class="font-medium break-all hover:underline"
                                >
                                    {{ doc.original_name }}
                                </a>
                                <p class="text-xs text-muted-foreground">
                                    {{ formatSize(doc.file_size) }} · uploaded
                                    by {{ doc.uploaded_by ?? 'unknown' }} ·
                                    {{ doc.created_at }}
                                </p>
                                <a
                                    :href="doc.url"
                                    target="_blank"
                                    rel="noopener"
                                    class="font-mono text-xs break-all text-primary hover:underline"
                                >
                                    {{ doc.url }}
                                </a>
                            </div>
                            <PdfStatusBadge :status="doc.status" />
                        </div>

                        <table
                            v-if="doc.chapters.length"
                            class="mt-3 w-full text-sm"
                        >
                            <thead>
                                <tr class="text-left text-muted-foreground">
                                    <th class="py-1 font-medium">Chapter</th>
                                    <th class="py-1 font-medium">Start</th>
                                    <th class="py-1 font-medium">End</th>
                                    <th class="py-1 font-medium">File</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="chapter in doc.chapters"
                                    :key="chapter.id"
                                    class="border-t"
                                >
                                    <td class="py-1">
                                        {{ chapter.chapter_name }}
                                    </td>
                                    <td class="py-1">
                                        {{ chapter.start_page }}
                                    </td>
                                    <td class="py-1">{{ chapter.end_page }}</td>
                                    <td class="py-1 font-mono text-xs break-all">
                                        <span
                                            v-if="chapter.status === 'failed'"
                                            class="text-red-600 dark:text-red-400"
                                        >
                                            Failed: {{ chapter.runtime_error }}
                                        </span>
                                        <a
                                            v-else-if="chapter.url"
                                            :href="chapter.url"
                                            target="_blank"
                                            rel="noopener"
                                            class="text-primary hover:underline"
                                        >
                                            {{ chapter.url }}
                                        </a>
                                        <span
                                            v-else
                                            class="text-muted-foreground"
                                            >Not split yet</span
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
