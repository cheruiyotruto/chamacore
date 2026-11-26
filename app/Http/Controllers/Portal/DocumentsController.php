<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\DocumentService;
use App\Support\PortalAccess;
use Illuminate\View\View;

class DocumentsController extends Controller
{
    public function index(): View
    {
        $member = $this->membership();

        abort_unless(
            PortalAccess::canAccessSection($this->group(), $member, 'documents'),
            403,
            'Document access is not enabled for your account.'
        );

        $documents = Document::query()
            ->where('member_id', $member->id)
            ->whereIn('visibility', ['member', 'public'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('portal.documents.index', ['documents' => $documents]);
    }

    public function download(Document $document, DocumentService $service)
    {
        $member = $this->membership();

        abort_unless(
            PortalAccess::canAccessSection($this->group(), $member, 'documents'),
            403,
            'Document access is not enabled for your account.'
        );

        $this->authorize('view', $document);

        return $service->download($document, $this->actor());
    }
}
