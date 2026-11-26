<?php

namespace App\Http\Controllers;

use App\Exceptions\BusinessRuleException;
use App\Models\Fine;
use App\Models\Meeting;
use App\Models\MeetingCollection;
use App\Models\Member;
use App\Models\SavingsProduct;
use App\Models\WelfareFund;
use App\Services\MeetingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MeetingController extends Controller
{
    public function __construct(private readonly MeetingService $meetings)
    {
    }

    public function index(Request $request): View
    {
        $this->requirePermission('meetings.view');

        $meetings = Meeting::query()
            ->withCount('attendances')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('scheduled_at')
            ->paginate(15)
            ->withQueryString();

        return view('meetings.index', [
            'meetings' => $meetings,
            'statuses' => ['scheduled', 'ongoing', 'completed', 'cancelled'],
        ]);
    }

    public function create(): View
    {
        $this->requirePermission('meetings.manage');

        return view('meetings.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->requirePermission('meetings.manage');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'type' => ['required', 'in:regular,special,agm,committee,training'],
            'scheduled_at' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:150'],
            'agenda' => ['nullable', 'string', 'max:2000'],
        ]);

        $meeting = $this->meetings->create($this->group(), $data, $this->actor());

        return redirect()->route('meetings.show', $meeting)->with('status', 'Meeting scheduled.');
    }

    public function show(Meeting $meeting): View
    {
        $this->requirePermission('meetings.view');

        $meeting->load([
            'attendances.member',
            'collections.member',
            'collections.product',
        ]);

        $members = Member::query()
            ->active()
            ->orderBy('first_name')
            ->get();

        $attendance = $meeting->attendances->keyBy('member_id');

        $collected = (int) $meeting->collections->sum('amount');

        return view('meetings.show', [
            'meeting' => $meeting,
            'members' => $members,
            'attendance' => $attendance,
            'collected' => $collected,
            'savingsProducts' => SavingsProduct::where('is_active', true)->orderBy('name')->get(),
            'welfareFunds' => WelfareFund::where('is_active', true)->orderBy('name')->get(),
            'fines' => Fine::whereIn('status', ['pending', 'partially_paid'])->orderBy('due_date')->get(),
            'canManage' => $this->context()->isStaff() && $this->context()->hasPermission('meetings.manage'),
        ]);
    }

    public function saveAttendance(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->requirePermission('meetings.manage');

        $data = $request->validate([
            'attendance' => ['required', 'array'],
            'attendance.*.member_id' => ['required', 'integer'],
            'attendance.*.status' => ['required', 'in:present,absent,late,excused'],
            'attendance.*.fine_amount' => ['nullable', 'numeric', 'min:0'],
            'attendance.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        $records = collect($data['attendance'])->map(fn (array $row) => [
            'member_id' => (int) $row['member_id'],
            'status' => $row['status'],
            'fine_amount' => (int) round(((float) ($row['fine_amount'] ?? 0)) * 100),
            'notes' => $row['notes'] ?? null,
        ])->all();

        $saved = $this->meetings->saveAttendance($meeting, $records, $this->actor());

        return back()->with('status', $saved.' attendance record(s) saved.');
    }

    public function recordCollection(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->requirePermission('meetings.manage');

        $data = $request->validate([
            'member_id' => ['required', 'integer'],
            'type' => ['required', 'in:savings,share,contribution,welfare,fine'],
            'amount' => ['required', 'numeric', 'min:1'],
            'savings_product_id' => ['nullable', 'integer'],
            'welfare_fund_id' => ['nullable', 'integer'],
            'fine_id' => ['nullable', 'integer'],
            'method' => ['required', 'in:cash,bank,mpesa'],
            'reference' => ['nullable', 'string', 'max:80'],
        ]);

        $data['amount'] = (int) round(((float) $data['amount']) * 100);

        try {
            $this->meetings->recordCollection($meeting, $data, $this->actor());
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Collection recorded.');
    }

    public function complete(Request $request, Meeting $meeting): RedirectResponse
    {
        $this->requirePermission('meetings.manage');

        $data = $request->validate([
            'minutes' => ['required', 'string', 'max:20000'],
        ]);

        $this->meetings->complete($meeting, $data['minutes'], $this->actor());

        return back()->with('status', 'Meeting completed and minutes saved.');
    }

    public function export(Meeting $meeting)
    {
        $this->requirePermission('meetings.view');

        $meeting->load(['collections.member', 'collections.product']);

        return $this->csv('meeting-'.$meeting->id.'-collections.csv', [
            'Member', 'Type', 'Product', 'Amount', 'Reference', 'Recorded at',
        ], $meeting->collections->map(fn (MeetingCollection $collection) => [
            $collection->member?->full_name,
            $collection->type,
            $collection->product?->name,
            number_format($collection->amount / 100, 2, '.', ''),
            $collection->reference,
            $collection->created_at?->toDateTimeString(),
        ]));
    }
}
