<?php

namespace App\Http\Requests\Tutor\Onboarding;

use App\Services\Scheduling\BookingLeadTime;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileStepRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'headline' => ['required', 'string', 'max:255'],
            'bio' => ['required', 'string', 'max:5000'],
            'intro_video_url' => ['nullable', 'url', 'max:255'],
            // R179: checked against the admin's current list here, server-side — "0" is simply absent
            // from it while `allow_immediate_booking` is off, so it cannot be reached by a hand-made post.
            'min_lead_hours' => ['nullable', 'integer', Rule::in(app(BookingLeadTime::class)->options())],
        ];
    }
}
