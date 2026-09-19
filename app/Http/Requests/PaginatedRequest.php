<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class PaginatedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => 'nullable|integer|min:1|max:1000',
            'per_page' => 'nullable|integer|min:1|max:100',
        ];
    }

    public function getPaginationParams(): array
    {
        $page = max(1, (int) $this->input('page', 1));
        $perPage = min(100, max(1, (int) $this->input('per_page', 50)));
        
        return [
            'page' => $page,
            'per_page' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'limit' => $perPage,
        ];
    }
}
