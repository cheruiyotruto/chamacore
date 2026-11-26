<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\Document;
use App\Models\Member;
use App\Services\DocumentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DocumentController extends Controller
{
    public function __construct(private readonly DocumentService $documents)
    {
    }

    public function index(Request $request): View
    {
        $this->requirePermission('documents.view');

        $documents = Document::query()
            ->with(['member', 'uploader'])
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('member_id'), fn ($query) => $query->where('member_id', $request->integer('member_id')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = '%'.$request->string('search').'%';
                $query->where(fn ($inner) => $inner
                    ->where('title', 'like', $search)
                    ->orWhereHas('member', fn ($member) => $member->where('first_name', 'like', $search)->orWhere('last_name', 'like', $search)));
            })
            ->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString();

        return view('documents.index', [
            'documents' => $documents,
            'members' => Member::query()->orderBy('first_name')->get(),
            'types' => ['national_id', 'passport', 'passport_photo', 'face_video', 'biometric', 'kra_pin', 'membership_form', 'signature', 'loan_agreement', 'guarantee', 'guarantee_signature', 'minutes', 'policy', 'receipt', 'other'],
            'statuses' => ['pending', 'verified', 'rejected', 'expired'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requirePermission('documents.manage');

        $request->validate([
            'file' => ['required', 'file'],
            'title' => ['nullable', 'string', 'max:150'],
            'type' => ['required', 'string', 'max:50'],
            'member_id' => ['nullable', 'integer'],
            'visibility' => ['required', 'in:staff,member,public'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $member = $request->filled('member_id')
            ? Member::query()->findOrFail($request->integer('member_id'))
            : null;

        try {
            $document = $this->documents->store(
                $this->group(),
                $request->file('file'),
                $request->only(['title', 'type', 'visibility', 'notes', 'expires_at', 'member_id']),
                $this->actor(),
                $member
            );
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Document "'.$document->title.'" uploaded.');
    }

    public function show(Document $document): View
    {
        $this->authorize('view', $document);

        $document->load(['member', 'uploader', 'verifier', 'accessLogs']);

        return view('documents.show', ['document' => $document]);
    }

    public function download(Document $document)
    {
        $this->authorize('view', $document);

        try {
            return $this->documents->download($document, $this->actor());
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    public function verify(Document $document): RedirectResponse
    {
        $this->authorize('verify', $document);

        $this->documents->verify($document, $this->actor());

        return back()->with('status', 'Document verified.');
    }

    public function reject(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('verify', $document);

        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        $this->documents->reject($document, $this->actor(), $data['reason']);

        return back()->with('status', 'Document rejected.');
    }

    public function destroy(Document $document): RedirectResponse
    {
        $this->authorize('delete', $document);

        $this->documents->delete($document, $this->actor());

        return redirect()->route('documents.index')->with('status', 'Document deleted.');
    }
}
