<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMarketingArticleCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'slug' => 'required|string|max:100|unique:marketing_article_categories,slug|regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
            'description' => 'nullable|string|max:255',
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex' => 'The category slug must contain only lowercase alphanumeric characters and hyphens.',
            'slug.unique' => 'A category with this slug already exists.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('slug')) {
            $this->merge([
                'slug' => mb_strtolower(trim((string) $this->input('slug'))),
            ]);
        }
    }

    public function categoryData(): array
    {
        return [
            'name' => trim((string) $this->input('name')),
            'slug' => mb_strtolower(trim((string) $this->input('slug'))),
            'description' => $this->filled('description') ? trim((string) $this->input('description')) : null,
        ];
    }
}
