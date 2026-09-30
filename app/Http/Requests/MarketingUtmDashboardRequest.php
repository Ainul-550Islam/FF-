<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MarketingUtmDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date|after_or_equal:period_start',
            'source' => 'nullable|string|max:64',
            'medium' => 'nullable|string|max:64',
            'campaign' => 'nullable|string|max:64',
            'sort_by' => 'nullable|in:period_start,touches,unique_visitors,conversions,attributed_users',
            'sort_order' => 'nullable|in:asc,desc',
            'page' => 'nullable|integer|min:1',
        ];
    }

    public function filterParams(): array
    {
        return array_filter([
            'period_start' => $this->input('period_start'),
            'period_end' => $this->input('period_end'),
            'source' => $this->filled('source') ? trim((string) $this->input('source')) : null,
            'medium' => $this->filled('medium') ? trim((string) $this->input('medium')) : null,
            'campaign' => $this->filled('campaign') ? trim((string) $this->input('campaign')) : null,
            'sort_by' => $this->input('sort_by', 'period_start'),
            'sort_order' => $this->input('sort_order', 'desc'),
        ], fn ($v) => $v !== null && $v !== '');
    }
}
