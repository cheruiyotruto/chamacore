<?php

namespace App\Services;

use App\Contracts\PushGateway;
use App\Contracts\SmsGateway;
use App\Models\DeviceToken;
use App\Models\Group;
use App\Models\Member;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Notifications\ChamaNotification;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Template-driven, multi-channel notifications. Delivery failures are logged
 * and never roll back the financial transaction that triggered the message.
 */
class NotificationService
{
    public function __construct(
        private readonly SmsGateway $sms,
        private readonly PushGateway $push,
    ) {
    }

    /**
     * @param  array<string, scalar|null>  $data  `title` and `body` may be supplied by admin reminders to override the template.
     * @param  array<int, string>|null  $channels  Override the template's default delivery channels.
     */
    public function send(Member $member, string $key, array $data = [], ?array $channels = null): void
    {
        $group = Group::withoutGlobalScopes()->find($member->group_id);

        if (! $group) {
            return;
        }

        $data = array_merge([
            'member_name' => $member->full_name,
            'group_name' => $group->name,
        ], $data);

        $templates = $this->templates($group, $key);
        $fallback = $this->defaults()[$key] ?? [
            'title' => 'Chama Core',
            'body' => 'You have a new update: '.str_replace('_', ' ', $key),
            'channels' => ['in_app'],
        ];

        $title = $this->render($data['title'] ?? ($templates['in_app']['subject'] ?? $fallback['title']), $data);
        $body = $this->render($data['body'] ?? ($templates['in_app']['body'] ?? $fallback['body']), $data);
        $channels = $channels ?? $fallback['channels'];
        $portalUrl = is_string($data['portal_url'] ?? null) ? $data['portal_url'] : null;

        if ($portalUrl) {
            $body = $this->withPortalLink($body, $portalUrl);
        }

        $inApp = in_array('in_app', $channels, true);
        $email = in_array('email', $channels, true);

        if ($member->user && ($inApp || $email)) {
            $this->database($member, $key, $title, $body, $data, $inApp, $email, $portalUrl);
        }

        if (in_array('sms', $channels, true) && $member->phone) {
            $message = $this->render($templates['sms']['body'] ?? $body, $data);

            if ($portalUrl) {
                $message = $this->withPortalLink($message, $portalUrl);
            }

            $this->sms($member, $group, $key, $member->phone, $message, $data);
        }

        if (in_array('push', $channels, true) && $member->user_id) {
            $this->push($member, $group, $key, $title, $body, $data);
        }
    }

    public function notifyUser(User $user, string $key, string $title, string $body, array $data = []): void
    {
        try {
            $user->notify(new ChamaNotification($title, $body, $data, $key));
        } catch (Throwable $e) {
            Log::warning('notification.database.failed', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }

    private function database(
        Member $member,
        string $key,
        string $title,
        string $body,
        array $data,
        bool $inApp,
        bool $mail,
        ?string $actionUrl
    ): void {
        try {
            $member->user->notify(new ChamaNotification(
                title: $title,
                body: $body,
                data: $data,
                key: $key,
                sendMail: $mail,
                actionUrl: $actionUrl ?? route('dashboard'),
                sendDatabase: $inApp,
            ));

            if ($inApp) {
                $this->log($member, $key, 'in_app', (string) $member->user->email, 'sent', null, null, $data);
            }

            if ($mail && ! empty($member->user->email)) {
                $this->log($member, $key, 'email', (string) $member->user->email, 'sent', null, null, $data);
            }
        } catch (Throwable $e) {
            $channel = $inApp ? 'in_app' : 'email';
            $this->log($member, $key, $channel, (string) $member->user?->email, 'failed', null, $e->getMessage(), $data);
        }
    }

    /**
     * Make sure the message carries the actionable portal link even when an
     * admin has customised the channel template.
     */
    private function withPortalLink(string $message, string $url): string
    {
        if (str_contains($message, $url)) {
            return $message;
        }

        return rtrim($message).' Open: '.$url;
    }

    private function sms(Member $member, Group $group, string $key, string $phone, string $message, array $data): void
    {
        try {
            $result = $this->sms->send($phone, $message);
            $this->log(
                $member,
                $key,
                'sms',
                $phone,
                $result['success'] ? 'sent' : 'failed',
                $result['reference'],
                $result['error'],
                $data
            );
        } catch (Throwable $e) {
            $this->log($member, $key, 'sms', $phone, 'failed', null, $e->getMessage(), $data);
        }
    }

    private function push(Member $member, Group $group, string $key, string $title, string $body, array $data): void
    {
        $tokens = DeviceToken::where('user_id', $member->user_id)->pluck('token')->all();

        if (empty($tokens)) {
            return;
        }

        try {
            $result = $this->push->send($tokens, $title, $body, $data);
            $this->log(
                $member,
                $key,
                'push',
                (string) $member->user_id,
                $result['success'] ? 'sent' : 'failed',
                $result['reference'],
                $result['error'],
                $data
            );
        } catch (Throwable $e) {
            $this->log($member, $key, 'push', (string) $member->user_id, 'failed', null, $e->getMessage(), $data);
        }
    }

    private function log(
        Member $member,
        string $key,
        string $channel,
        string $recipient,
        string $status,
        ?string $reference,
        ?string $error,
        array $payload
    ): void {
        try {
            NotificationLog::withoutGlobalScopes()->create([
                'group_id' => $member->group_id,
                'member_id' => $member->id,
                'channel' => $channel,
                'recipient' => $recipient,
                'template_key' => $key,
                'payload' => $payload,
                'status' => $status,
                'provider_reference' => $reference,
                'error' => $error,
                'sent_at' => $status === 'sent' ? now() : null,
            ]);
        } catch (Throwable $e) {
            Log::warning('notification.log.failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * @return array<string, NotificationTemplate>
     */
    private function templates(Group $group, string $key): array
    {
        return NotificationTemplate::query()
            ->where('key', $key)
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('group_id', $group->id)->orWhereNull('group_id'))
            ->orderByRaw('group_id IS NULL')
            ->get()
            ->keyBy('channel')
            ->all();
    }

    private function render(string $template, array $data): string
    {
        $replacements = [];

        foreach ($data as $key => $value) {
            $replacements['{{'.$key.'}}'] = (string) $value;
            $replacements['{{ '.$key.' }}'] = (string) $value;
        }

        return strtr($template, $replacements);
    }

    /**
     * @return array<string, array{title:string, body:string, channels:array<int,string>}>
     */
    private function defaults(): array
    {
        return [
            'welcome' => [
                'title' => 'Welcome to {{group_name}}',
                'body' => 'Hello {{member_name}}, your membership has been approved. Welcome to {{group_name}}.',
                'channels' => ['in_app', 'email', 'sms'],
            ],
            'guarantor_request' => [
                'title' => 'Guarantee request',
                'body' => '{{borrower_name}} has requested you to guarantee {{amount}} for loan {{loan_no}}. Accept or reject the request in the member portal: {{portal_url}}',
                'channels' => ['in_app', 'sms', 'push', 'email'],
            ],
            'guarantee_savings_required' => [
                'title' => 'Deposit savings to fund a guarantee',
                'body' => 'Your membership is approved. Deposit at least {{savings_amount}} in qualifying savings to fund the {{amount}} guarantee for {{borrower_name}} (loan {{loan_no}}). {{portal_url}}',
                'channels' => ['in_app', 'sms', 'push', 'email'],
            ],
            'loan_submitted' => [
                'title' => 'Loan application received',
                'body' => 'Your loan application {{loan_no}} for {{amount}} has been received and is being processed.',
                'channels' => ['in_app', 'sms'],
            ],
            'loan_ready_for_review' => [
                'title' => 'Loan ready for review',
                'body' => 'Loan {{loan_no}} has all guarantor responses and is now awaiting approval.',
                'channels' => ['in_app'],
            ],
            'loan_approved' => [
                'title' => 'Loan approved',
                'body' => 'Your loan {{loan_no}} for {{amount}} has been approved.',
                'channels' => ['in_app', 'sms', 'push'],
            ],
            'loan_rejected' => [
                'title' => 'Loan application declined',
                'body' => 'Your loan {{loan_no}} was declined. {{comments}}',
                'channels' => ['in_app', 'sms'],
            ],
            'loan_info_requested' => [
                'title' => 'More information required',
                'body' => 'More information is required for loan {{loan_no}}. {{comments}}',
                'channels' => ['in_app', 'sms'],
            ],
            'loan_approval_progress' => [
                'title' => 'Loan approval progress',
                'body' => 'Loan {{loan_no}} passed the {{step}} approval step.',
                'channels' => ['in_app'],
            ],
            'loan_disbursed' => [
                'title' => 'Loan disbursed',
                'body' => '{{amount}} has been disbursed for loan {{loan_no}}. First repayment is due {{first_due_date}}.',
                'channels' => ['in_app', 'sms', 'push'],
            ],
            'loan_repayment_received' => [
                'title' => 'Repayment received',
                'body' => 'We received {{amount}} for loan {{loan_no}}. Outstanding balance: {{outstanding}}.',
                'channels' => ['in_app', 'sms'],
            ],
            'loan_due_reminder' => [
                'title' => 'Loan repayment due',
                'body' => 'Your loan {{loan_no}} repayment of {{amount}} is due on {{due_date}}.',
                'channels' => ['in_app', 'sms', 'push'],
            ],
            'loan_closed' => [
                'title' => 'Loan fully repaid',
                'body' => 'Congratulations, loan {{loan_no}} is fully repaid and closed.',
                'channels' => ['in_app', 'sms'],
            ],
            'loan_restructured' => [
                'title' => 'Loan restructured',
                'body' => 'Loan {{loan_no}} has been restructured. Your new repayment schedule is available in the portal.',
                'channels' => ['in_app', 'sms'],
            ],
            'employer_finance_form_uploaded' => [
                'title' => 'Employer finance form received',
                'body' => 'Your stamped form for loan {{loan_no}} was received. We have sent it to your employer\'s finance office for confirmation.',
                'channels' => ['in_app'],
            ],
            'employer_finance_confirmed' => [
                'title' => 'Employer finance approval confirmed',
                'body' => 'The finance office confirmed your employer approval for loan {{loan_no}}. The loan can now proceed to disbursement.',
                'channels' => ['in_app', 'sms'],
            ],
            'employer_finance_declined' => [
                'title' => 'Employer finance approval declined',
                'body' => 'The finance office declined the employer approval for loan {{loan_no}}. {{comments}}',
                'channels' => ['in_app', 'sms'],
            ],
            'savings_deposit' => [
                'title' => 'Savings deposit',
                'body' => '{{amount}} was credited to your {{product}} savings account. Balance: {{balance}}.',
                'channels' => ['in_app', 'sms'],
            ],
            'savings_withdrawal' => [
                'title' => 'Savings withdrawal',
                'body' => '{{amount}} was debited from your {{product}} savings account. Balance: {{balance}}.',
                'channels' => ['in_app', 'sms'],
            ],
            'investment_opened' => [
                'title' => 'Investment placed',
                'body' => 'Your {{amount}} investment in {{product}} is active. Maturity: {{maturity_date}}. Expected interest: {{expected_interest}}.',
                'channels' => ['in_app', 'sms'],
            ],
            'investment_matured' => [
                'title' => 'Investment matured',
                'body' => 'Your {{product}} investment {{reference_no}} has matured. {{amount}} has been paid out.',
                'channels' => ['in_app', 'sms'],
            ],
            'investment_withdrawn' => [
                'title' => 'Investment withdrawn',
                'body' => 'Your {{product}} investment {{reference_no}} was settled early. {{amount}} has been paid out.',
                'channels' => ['in_app', 'sms'],
            ],
            'mgr_contribution_reminder' => [
                'title' => 'Merry-go-round contribution',
                'body' => 'Your {{cycle}} contribution of {{amount}} is due on {{due_date}}.',
                'channels' => ['in_app', 'sms'],
            ],
            'mgr_contribution_received' => [
                'title' => 'Merry-go-round contribution received',
                'body' => 'We received {{amount}} for {{cycle}} (due {{due_date}}). Reference {{reference}}.',
                'channels' => ['in_app', 'sms'],
            ],
            'mgr_payout_reminder' => [
                'title' => 'Merry-go-round payout',
                'body' => 'Your {{cycle}} payout of {{amount}} is scheduled for {{payout_date}}.',
                'channels' => ['in_app', 'sms'],
            ],
            'bill_due_reminder' => [
                'title' => 'Bill due',
                'body' => 'Bill {{bill_no}} from {{payee}} for {{amount}} is due on {{due_date}}.',
                'channels' => ['in_app', 'sms'],
            ],
            'investment_maturity_reminder' => [
                'title' => 'Investment maturing',
                'body' => 'Your {{product}} investment {{reference_no}} matures on {{maturity_date}}. Expected value: {{amount}}.',
                'channels' => ['in_app', 'sms'],
            ],
            'admin_reminder' => [
                'title' => '{{title}}',
                'body' => '{{body}}',
                'channels' => ['in_app', 'sms'],
            ],
            'mgr_payout' => [
                'title' => 'Merry-go-round payout',
                'body' => 'Your merry-go-round payout of {{amount}} for {{cycle}} has been paid.',
                'channels' => ['in_app', 'sms', 'push'],
            ],
            'fine_issued' => [
                'title' => 'Fine issued',
                'body' => 'A fine of {{amount}} was issued: {{reason}}.',
                'channels' => ['in_app', 'sms'],
            ],
            'welfare_claim' => [
                'title' => 'Welfare claim update',
                'body' => 'Your welfare claim of {{amount}} is now {{status}}.',
                'channels' => ['in_app', 'sms'],
            ],
            'subscription_invoice' => [
                'title' => 'Subscription invoice',
                'body' => 'Invoice {{invoice_no}} for {{amount}} is due on {{due_date}}.',
                'channels' => ['in_app', 'email'],
            ],
            'document_verified' => [
                'title' => 'Document verified',
                'body' => 'Your document "{{title}}" has been verified.',
                'channels' => ['in_app'],
            ],
            'float_purchase_completed' => [
                'title' => 'M-Pesa float delivered',
                'body' => 'Your {{amount}} M-Pesa float for agent {{agent_number}}, store {{store_number}} is complete. Reference {{reference}}.',
                'channels' => ['in_app', 'sms'],
            ],
            'float_purchase_failed' => [
                'title' => 'M-Pesa float refunded',
                'body' => 'Your {{amount}} float purchase for agent {{agent_number}}, store {{store_number}} failed: {{reason}}. The amount was returned to your wallet. Reference {{reference}}.',
                'channels' => ['in_app', 'sms'],
            ],
        ];
    }
}
