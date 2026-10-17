<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ExternalLink, FileText } from '@lucide/vue';
import PdfStatusBadge from '@/components/PdfStatusBadge.vue';
import type { PdfStatus } from '@/components/PdfStatusBadge.vue';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

type LessonsStatus = 'pending' | 'preparing' | 'splitting' | 'done' | 'failed';

type Lesson = {
    id: number;
    position: number;
    title: string;
    start_page: number;
    end_page: number;
    printed_start_page: number | null;
    printed_end_page: number | null;
    url: string | null;
    warnings: string[];
};

type Chapter = {
    id: number;
    chapter_name: string;
    start_page: number;
    end_page: number;
    file_path: string | null;
    url: string | null;
    status: 'pending' | 'done' | 'failed';
    runtime_error: string | null;
    lessons_status: LessonsStatus;
    lessons_error: string | null;
    lessons: Lesson[];
};

type PdfDocument = {
    id: number;
    original_name: string;
    url: string;
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
                title: 'PDF Library',
                href: '/pdfs/library',
            },
        ],
    },
});

const lessonsLabels: Record<LessonsStatus, string> = {
    pending: 'Lessons not started',
    preparing: 'Step 1: reading text, OCR and images…',
    splitting: 'Step 2: splitting into lessons…',
    done: 'Lessons',
    failed: 'Lessons failed',
};

function printedPages(lesson: Lesson): string {
    if (lesson.printed_start_page === null) {
        return `PDF pages ${lesson.start_page}–${lesson.end_page}`;
    }

    return lesson.printed_start_page === lesson.printed_end_page
        ? `Page ${lesson.printed_start_page}`
        : `Pages ${lesson.printed_start_page}–${lesson.printed_end_page}`;
}

function countWithStatus(doc: PdfDocument, status: Chapter['status']): number {
    return doc.chapters.filter((chapter) => chapter.status === status).length;
}
</script>

<template>
    <Head title="PDF Library" />

    <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
        <div>
            <h1 class="text-xl font-semibold">PDF Library</h1>
            <p class="text-sm text-muted-foreground">
                Every uploaded PDF and its chapters. Chapters that have been
                split open in a new tab.
            </p>
        </div>

        <Card v-if="documents.length === 0">
            <CardContent class="text-sm text-muted-foreground">
                No PDFs yet.
                <Link href="/pdfs" class="font-medium underline"
                    >Upload one</Link
                >.
            </CardContent>
        </Card>

        <Card v-for="doc in documents" :key="doc.id">
            <CardHeader>
                <div class="flex items-start gap-3">
                    <FileText class="mt-0.5 size-5 shrink-0" />
                    <div class="min-w-0 flex-1">
                        <CardTitle class="break-all">
                            <a
                                :href="doc.url"
                                target="_blank"
                                rel="noopener"
                                class="hover:underline"
                            >
                                {{ doc.original_name }}
                            </a>
                        </CardTitle>
                        <CardDescription>
                            Uploaded by {{ doc.uploaded_by ?? 'unknown' }} ·
                            {{ doc.created_at }} ·
                            {{ countWithStatus(doc, 'done') }} of
                            {{ doc.chapters.length }} chapters ready
                            <span
                                v-if="countWithStatus(doc, 'failed')"
                                class="text-red-600 dark:text-red-400"
                            >
                                · {{ countWithStatus(doc, 'failed') }} failed
                            </span>
                        </CardDescription>
                    </div>
                    <PdfStatusBadge :status="doc.status" />
                </div>
            </CardHeader>

            <CardContent>
                <p
                    v-if="doc.chapters.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    This PDF has no chapters.
                </p>

                <ul v-else class="divide-y rounded-lg border">
                    <li
                        v-for="chapter in doc.chapters"
                        :key="chapter.id"
                        class="px-3 py-2 text-sm"
                    >
                        <div class="flex items-start gap-3">
                            <div class="min-w-0 flex-1">
                                <p class="font-medium break-words">
                                    {{ chapter.chapter_name }}
                                </p>
                                <p class="text-xs text-muted-foreground">
                                    Pages {{ chapter.start_page }}–{{
                                        chapter.end_page
                                    }}
                                </p>
                                <p
                                    v-if="chapter.status === 'failed'"
                                    class="mt-1 font-mono text-xs break-words text-red-600 dark:text-red-400"
                                >
                                    {{ chapter.runtime_error }}
                                </p>
                            </div>

                            <span
                                v-if="chapter.status === 'failed'"
                                class="shrink-0 rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800 dark:bg-red-900/40 dark:text-red-300"
                            >
                                Failed
                            </span>
                            <a
                                v-else-if="chapter.url"
                                :href="chapter.url"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex shrink-0 items-center gap-1 font-medium text-primary hover:underline"
                            >
                                Open
                                <ExternalLink class="size-3.5" />
                            </a>
                            <span
                                v-else
                                class="shrink-0 text-xs text-muted-foreground"
                            >
                                Not split yet
                            </span>
                        </div>

                        <div
                            v-if="chapter.status === 'done'"
                            class="mt-2 ml-3 border-l pl-3"
                        >
                            <p
                                class="text-xs font-medium"
                                :class="
                                    chapter.lessons_status === 'failed'
                                        ? 'text-red-600 dark:text-red-400'
                                        : 'text-muted-foreground'
                                "
                            >
                                {{ lessonsLabels[chapter.lessons_status] }}
                                <template
                                    v-if="chapter.lessons_status === 'done'"
                                >
                                    ({{ chapter.lessons.length }})
                                </template>
                            </p>
                            <p
                                v-if="chapter.lessons_error"
                                class="mt-1 font-mono text-xs break-words text-red-600 dark:text-red-400"
                            >
                                {{ chapter.lessons_error }}
                            </p>

                            <ol
                                v-if="chapter.lessons.length"
                                class="mt-1 flex flex-col gap-1"
                            >
                                <li
                                    v-for="lesson in chapter.lessons"
                                    :key="lesson.id"
                                    class="flex items-start gap-2"
                                >
                                    <span
                                        class="w-5 shrink-0 text-right text-xs text-muted-foreground tabular-nums"
                                        >{{ lesson.position }}.</span
                                    >
                                    <div class="min-w-0 flex-1">
                                        <p class="break-words">
                                            {{ lesson.title }}
                                        </p>
                                        <p
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ printedPages(lesson) }}
                                            <span
                                                v-if="lesson.warnings.length"
                                                class="text-amber-700 dark:text-amber-400"
                                                :title="
                                                    lesson.warnings.join('\n')
                                                "
                                            >
                                                · {{ lesson.warnings.length }}
                                                to check
                                            </span>
                                        </p>
                                    </div>
                                    <a
                                        v-if="lesson.url"
                                        :href="lesson.url"
                                        target="_blank"
                                        rel="noopener"
                                        class="inline-flex shrink-0 items-center gap-1 text-xs font-medium text-primary hover:underline"
                                    >
                                        Open
                                        <ExternalLink class="size-3" />
                                    </a>
                                </li>
                            </ol>
                        </div>
                    </li>
                </ul>
            </CardContent>
        </Card>
    </div>
</template>
