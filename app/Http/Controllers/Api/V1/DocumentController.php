<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\DocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $documents = Document::query()
            ->where('member_id', $this->membership()->id)
            ->whereIn('visibility', ['member', 'public'])
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->orderByDesc('created_at')
            ->paginate((int) min(max($request->integer('per_page', 20), 1), 100));

        return response()->json([
            'data' => $documents->through(fn (Document $document) => [
                'id' => $document->id,
                'uuid' => $document->uuid,
                'type' => $document->type,
                'title' => $document->title,
                'mime_type' => $document->mime_type,
                'size' => $document->size,
                'status' => $document->status,
                'visibility' => $document->visibility,
                'verified_at' => $document->verified_at?->toIso8601String(),
                'created_at' => $document->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function download(Document $document, DocumentService $service)
    {
        $this->authorize('view', $document);

        return $service->download($document, $this->actor());
    }
}
