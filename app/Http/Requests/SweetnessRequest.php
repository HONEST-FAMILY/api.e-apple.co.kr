<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SweetnessRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return auth()->user()?->is_admin;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'fruit_name' => ['required', 'string'],
            'sweetness' => ['required', 'numeric', 'min:0'],
            'standard_sweetness' => ['required', 'numeric', 'min:0'],
            'is_display' => ['required', 'boolean'],
            'description' => ['nullable', 'string'],
            'curator' => ['nullable', 'string'],
            'imgs' => ['nullable', 'array'],
            'photos' => ['nullable', 'array'],
        ];
    }

    public function prepareForValidation()
    {
        $inputs = $this->input();
        foreach ($inputs as $key => $input) {
            if ($input === 'null') $inputs[$key] = null;
            if ($input === 'true') $inputs[$key] = true;
            if ($input === 'false') $inputs[$key] = false;
        }
        $this->merge($inputs);
    }
}
