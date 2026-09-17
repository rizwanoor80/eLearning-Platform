<?php

namespace Database\Factories;

use App\Enums\TutorDocumentStatus;
use App\Models\DocumentType;
use App\Models\TutorDocument;
use App\Models\TutorProfile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TutorDocument>
 */
class TutorDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tutor_profile_id' => TutorProfile::factory(),
            'document_type_id' => DocumentType::factory(),
            'disk_path' => 'tutor-documents/'.Str::uuid().'.pdf',
            'original_name' => fake()->word().'.pdf',
            'status' => TutorDocumentStatus::Pending,
        ];
    }

    public function accepted(): static
    {
        return $this->state(fn (array $attributes) => ['status' => TutorDocumentStatus::Accepted]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => ['status' => TutorDocumentStatus::Rejected]);
    }
}
