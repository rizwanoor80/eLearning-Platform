<?php

namespace App\Http\Controllers\Tutor;

use App\Enums\TutorProfileStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tutor\Onboarding\DocumentStepRequest;
use App\Http\Requests\Tutor\Onboarding\PermitStepRequest;
use App\Http\Requests\Tutor\Onboarding\PersonalStepRequest;
use App\Models\DocumentType;
use App\Models\TutorDocument;
use App\Models\TutorProfile;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class TutorOnboardingController extends Controller
{
    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $this->profileFor($user);
        $step = $this->currentStep($user, $profile);

        return Inertia::render('tutor/Onboarding', [
            'step' => $step['name'],
            'currentDocumentType' => $step['documentType'] ?? null,
            'personal' => [
                'phone' => $user->phone,
                'timezone' => $user->timezone,
            ],
            'profile' => [
                'permit_number' => $profile->permit_number,
                'permit_expires_at' => $profile->permit_expires_at?->toDateString(),
            ],
            'documentTypes' => DocumentType::query()->active()->orderBy('sort')
                ->get(['id', 'code', 'name', 'description']),
            'documents' => $profile->tutorDocuments()
                ->get(['id', 'document_type_id', 'original_name', 'status']),
        ]);
    }

    public function storePersonal(PersonalStepRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->update($request->validated());
        $this->profileFor($user);

        return redirect()->route('tutor.onboarding');
    }

    public function storePermit(PermitStepRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->profileFor($user)->update($request->validated());

        return redirect()->route('tutor.onboarding');
    }

    public function storeDocument(DocumentStepRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $profile = $this->profileFor($user);

        $step = $this->currentStep($user, $profile);
        $documentType = $step['documentType'] ?? null;
        abort_unless($documentType !== null, 409);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $path = $file->store('tutor-documents/'.$profile->id, 'local');

        DB::transaction(function () use ($profile, $documentType, $file, $path): void {
            $profile->tutorDocuments()->where('document_type_id', $documentType->id)->delete();

            TutorDocument::query()->create([
                'tutor_profile_id' => $profile->id,
                'document_type_id' => $documentType->id,
                'disk_path' => $path,
                'original_name' => $file->getClientOriginalName(),
            ]);
        });

        return redirect()->route('tutor.onboarding');
    }

    private function profileFor(User $user): TutorProfile
    {
        return TutorProfile::query()->firstOrCreate(
            ['user_id' => $user->id],
            ['status' => TutorProfileStatus::Draft],
        );
    }

    /**
     * The current step is derived server-side from what's actually saved —
     * never from client input — so the wizard is resumable from a refresh or
     * a new device. Each active document type is its own step (in `sort`
     * order): adding one in Filament inserts a step, deactivating one
     * removes it, with no code change (CP1 acceptance box 6).
     *
     * @return array{name: string, documentType?: DocumentType}
     */
    private function currentStep(User $user, TutorProfile $profile): array
    {
        if ($user->phone === null) {
            return ['name' => 'personal'];
        }

        if ($profile->permit_number === null || $profile->permit_expires_at === null) {
            return ['name' => 'permit'];
        }

        $uploadedTypeIds = $profile->tutorDocuments()->pluck('document_type_id');

        $nextType = DocumentType::query()->active()->orderBy('sort')
            ->whereNotIn('id', $uploadedTypeIds)
            ->first();

        if ($nextType !== null) {
            return ['name' => 'document', 'documentType' => $nextType];
        }

        return ['name' => 'complete'];
    }
}
