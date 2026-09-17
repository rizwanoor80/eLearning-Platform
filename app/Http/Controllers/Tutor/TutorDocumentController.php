<?php

namespace App\Http\Controllers\Tutor;

use App\Http\Controllers\Controller;
use App\Models\TutorDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TutorDocumentController extends Controller
{
    /**
     * Stream a tutor's own document from the private disk. Reached only via
     * a signed, expiring URL (route middleware: auth, verified,
     * access-tutor-area, signed) — this method still checks ownership itself
     * so a signature alone is never enough to read someone else's document.
     */
    public function show(Request $request, TutorDocument $document): StreamedResponse
    {
        abort_unless($request->user()->can('view', $document), 403);

        return Storage::disk('local')->response($document->disk_path, $document->original_name);
    }
}
