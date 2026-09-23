<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplyMarketingPromoCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route middleware enforces authentication: redemptions are always
        // attributed to the authenticated user.
        return true;
    }

    public function rules(): array
    {
        return [
            // Only the code and an optional tournament context are accepted.
            // Client-supplied prices/totals/discounts are deliberately NOT
            // part of the contract — the discount is always recomputed
            // server-side from the tournament's own entry fee.
            'code' => 'required|string|max:32',
            'tournament_id' => 'nullable|integer|exists:tournaments,id',
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Enter a promo code.',
            'tournament_id.exists' => 'Unknown tournament.',
        ];
    }

    /**
     * @return array{code:string, tournament_id:?int}
     */
    public function promoContext(): array
    {
        return [
            'code' => strtoupper(trim((string) $this->input('code'))),
            'tournament_id' => $this->input('tournament_id') !== null ? (int) $this->input('tournament_id') : null,
        ];
    }
}
