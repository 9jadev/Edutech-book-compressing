<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePdfDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'pdf' => ['required', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:51200'],
            'header_text' => ['nullable', 'string', 'max:2000'],
            'chapters' => ['nullable', 'array'],
            'chapters.*.chapter_name' => ['required', 'string', 'max:255'],
            'chapters.*.start_page' => ['required', 'integer', 'min:1'],
            'chapters.*.end_page' => ['required', 'integer', 'gte:chapters.*.start_page'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pdf.mimes' => 'Only PDF files can be uploaded.',
            'pdf.mimetypes' => 'Only PDF files can be uploaded.',
            'chapters.*.end_page.gte' => 'End page must be greater than or equal to the start page.',
        ];
    }
}
