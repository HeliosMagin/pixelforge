<?php

namespace App\Http\Requests;

use App\Models\Generation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Mirrors Pixelforge's own limits so users get errors here, not from the provider. */
class GenerationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // The price preview runs while the user is still typing, so it accepts an empty prompt.
            'prompt' => [$this->routeIs('generations.estimate') ? 'nullable' : 'required', 'string', 'max:'.Generation::MAX_PROMPT_LENGTH],
            'size' => ['required', Rule::in(Generation::SIZES)],
            'quality' => ['required', Rule::in(Generation::QUALITIES)],
            'reference_images' => ['array', 'max:'.Generation::MAX_REFERENCE_IMAGES],
            'reference_images.*' => ['required', 'url:http,https', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            'reference_images.*.url' => 'Reference images must be http(s) URLs.',
            'reference_images.max' => 'You can add up to '.Generation::MAX_REFERENCE_IMAGES.' reference images.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $references = array_filter(
            array_map(fn ($url) => is_string($url) ? trim($url) : $url, (array) $this->input('reference_images', [])),
            fn ($url) => $url !== '' && $url !== null,
        );

        $this->merge(['reference_images' => array_values($references)]);
    }
}
