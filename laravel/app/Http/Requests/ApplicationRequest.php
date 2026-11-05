<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplicationRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'resume_id' => ['nullable', 'integer', 'exists:resumes,id'],
            'cover_letter' => ['nullable', 'string', 'max:5000'],
            // answers to the employer's screening questions (up to 5)
            'answers' => ['nullable', 'array', 'max:5'],
            'answers.*.q' => ['required_with:answers', 'string', 'max:300'],
            'answers.*.a' => ['required_with:answers', 'string', 'max:2000'],
            'answers.*.mins' => ['nullable', 'integer', 'min:1', 'max:60'],
        ];
    }
}
