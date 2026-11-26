<?php

namespace App\Http\Resources;

use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Generation */
class GenerationResource extends JsonResource
{
    /** What a user should read for each failure code, plus whether retrying is likely to help. */
    private const ERRORS = [
        'content_policy_violation' => ['Pixelforge declined this prompt under its content policy. Try rewording it.', false],
        'generation_failed' => ["The model couldn't produce an image for this prompt. Try rewording it.", true],
        'internal_error' => ['Pixelforge had an internal error. Retrying usually works.', true],
        'daily_limit_exceeded' => ["We've reached today's generation limit with our provider. Please try again after midnight UTC.", true],
        'submission_timeout' => ['Pixelforge was too busy to accept this generation for 20 minutes. Please try again.', true],
    ];

    public function toArray(Request $request): array
    {
        [$errorMessage, $retryable] = self::ERRORS[$this->error_code] ?? [$this->error_message ?: 'Something went wrong.', true];

        return [
            'id' => $this->uuid,
            'status' => $this->status->value,
            'status_detail' => $this->status_detail,
            'is_terminal' => $this->status->isTerminal(),
            'prompt' => $this->prompt,
            'size' => $this->size,
            'quality' => $this->quality,
            'reference_images' => $this->reference_images,
            'estimated_nanos' => $this->estimated_nanos,
            'max_nanos' => $this->max_nanos,
            'charged_nanos' => $this->charged_nanos,
            'usage' => $this->usage,
            'image_url' => $this->image_url,
            'image_url_expires_at' => $this->image_url_expires_at?->toIso8601String(),
            'error' => $this->error_code ? [
                'code' => $this->error_code,
                'message' => $errorMessage,
                'retryable' => $retryable,
            ] : null,
            'created_at' => $this->created_at->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
