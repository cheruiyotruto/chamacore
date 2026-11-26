<?php

namespace App\Mail;

use App\Models\Loan;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmployerFinanceRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Loan $loan,
        public readonly string $confirmationUrl,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Employer finance approval required · '.$this->loan->loan_no,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.employer-finance-request',
        );
    }
}
