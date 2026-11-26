<?php

namespace App\Http\Controllers;

use App\Models\Communication;
use App\Models\Member;
use App\Models\NotificationLog;
use App\Models\ReminderRule;
use App\Services\NotificationService;
use App\Services\ReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        $this->requirePermission('notifications.view');

        $logs = NotificationLog::query()
            ->with('member')
            ->when($request->filled('channel'), fn ($query) => $query->where('channel', $request->string('channel')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->orderByDesc('created_at')
            ->paginate(30)
            ->withQueryString();

        $communications = Communication::query()
            ->with('member')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();

        return view('notifications.index', [
            'logs' => $logs,
            'communications' => $communications,
            'channels' => ['in_app', 'sms', 'email', 'push', 'whatsapp'],
            'statuses' => ['queued', 'sent', 'failed'],
            'members' => Member::query()->active()->orderBy('first_name')->get(),
            'rules' => ReminderRule::withoutGlobalScopes()
                ->where('group_id', $this->group()->id)
                ->orderBy('type')
                ->get(),
            'ruleTypes' => ReminderRule::TYPES,
            'audiences' => ReminderRule::AUDIENCES,
            'templateKeys' => [
                'admin_reminder' => 'General admin reminder',
                'mgr_contribution_reminder' => 'Merry-go-round contribution',
                'mgr_payout_reminder' => 'Merry-go-round payout',
                'loan_due_reminder' => 'Loan repayment',
                'bill_due_reminder' => 'Bill due',
                'investment_maturity_reminder' => 'Investment maturity',
            ],
            'canManage' => $this->context()->hasPermission('notifications.manage'),
        ]);
    }

    public function send(Request $request, NotificationService $notifications): RedirectResponse
    {
        $this->requirePermission('notifications.manage');

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:2000'],
            'audience' => ['required', 'in:all,selected'],
            'member_ids' => ['required_if:audience,selected', 'array'],
            'member_ids.*' => ['integer'],
        ]);

        $members = $data['audience'] === 'all'
            ? Member::query()->active()->get()
            : Member::query()->whereIn('id', $data['member_ids'] ?? [])->get();

        if ($members->isEmpty()) {
            return back()->withInput()->with('error', 'No recipients were selected.');
        }

        DB::transaction(function () use ($members, $data, $notifications) {
            foreach ($members as $member) {
                Communication::create([
                    'group_id' => $this->group()->id,
                    'member_id' => $member->id,
                    'channel' => 'in_app',
                    'direction' => 'outbound',
                    'subject' => $data['subject'],
                    'body' => $data['body'],
                    'status' => 'sent',
                    'sent_at' => now(),
                    'created_by' => $this->actor()->id,
                ]);

                $notifications->send($member, 'announcement', [
                    'subject' => $data['subject'],
                    'body' => $data['body'],
                    'group_name' => $this->group()->name,
                ]);
            }
        });

        return back()->with('status', 'Announcement sent to '.$members->count().' member(s).');
    }

    public function storeRule(Request $request): RedirectResponse
    {
        $this->requirePermission('notifications.manage');

        $data = $this->validateRule($request);

        ReminderRule::withoutGlobalScopes()->create([
            ...$data,
            'group_id' => $this->group()->id,
            'created_by' => $this->actor()->id,
        ]);

        return back()->with('status', 'Date reminder created.');
    }

    public function updateRule(Request $request, ReminderRule $rule): RedirectResponse
    {
        $this->requirePermission('notifications.manage');

        abort_unless((int) $rule->group_id === $this->group()->id, 404);

        $rule->update($this->validateRule($request));

        return back()->with('status', 'Date reminder updated.');
    }

    public function toggleRule(ReminderRule $rule): RedirectResponse
    {
        $this->requirePermission('notifications.manage');

        abort_unless((int) $rule->group_id === $this->group()->id, 404);

        $rule->update(['is_active' => ! $rule->is_active]);

        return back()->with('status', $rule->is_active ? 'Date reminder enabled.' : 'Date reminder disabled.');
    }

    public function destroyRule(ReminderRule $rule): RedirectResponse
    {
        $this->requirePermission('notifications.manage');

        abort_unless((int) $rule->group_id === $this->group()->id, 404);

        $rule->delete();

        return back()->with('status', 'Date reminder removed.');
    }

    public function runReminders(ReminderService $reminders): RedirectResponse
    {
        $this->requirePermission('notifications.manage');

        $result = $reminders->sendDue($this->group(), CarbonImmutable::today());

        return back()->with('status', "Reminders sent: {$result['sent']}. Already sent today: {$result['skipped']}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRule(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(array_keys(ReminderRule::TYPES))],
            'offset_days' => ['nullable', 'integer', 'between:-365,365'],
            'specific_date' => [
                'nullable', 'date',
                Rule::requiredIf($request->input('type') === ReminderRule::TYPE_CUSTOM_DATE),
            ],
            'audience' => ['required', Rule::in(array_keys(ReminderRule::AUDIENCES))],
            'member_ids' => ['nullable', 'array'],
            'member_ids.*' => ['integer', 'exists:members,id'],
            'template_key' => ['required', 'string', 'max:80'],
            'subject' => ['nullable', 'string', 'max:190'],
            'body' => ['nullable', 'string', 'max:2000'],
            'channels' => ['required', 'array', 'min:1'],
            'channels.*' => ['required', Rule::in(['in_app', 'sms', 'email', 'push'])],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $data['offset_days'] = $data['offset_days'] ?? -3;
        $data['member_ids'] = array_values(array_map('intval', $data['member_ids'] ?? []));
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        if ($request->input('type') === ReminderRule::TYPE_CUSTOM_DATE) {
            $data['offset_days'] = 0;
        } else {
            $data['specific_date'] = null;
        }

        return $data;
    }
}
