<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One staff member's finance task report — every open record tagged to
 * them, in a single email. Deliberately a digest and not one mail per
 * record: finance tags in batches, and thirty emails about thirty rows is
 * how notifications get ignored.
 */
class FinanceTaskDigest extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $tasks  each: ref, category, client, amount, date, status, comment, overdue_days
     * @param  array<string, int|float>  $summary  open, overdue, due_today, total_amount
     */
    public function __construct(
        public readonly string $recipientName,
        public readonly array $tasks,
        public readonly array $summary,
        public readonly string $panelUrl,
    ) {}

    public function envelope(): Envelope
    {
        $overdue = (int) ($this->summary['overdue'] ?? 0);

        return new Envelope(
            subject: 'Your finance tasks — ' . ($this->summary['open'] ?? count($this->tasks)) . ' open'
                . ($overdue > 0 ? ", {$overdue} overdue" : ''),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.finance-task-digest',
            text: 'emails.finance-task-digest-text',
            with: [
                'recipientName' => $this->recipientName,
                'tasks'         => $this->tasks,
                'summary'       => $this->summary,
                'panelUrl'      => $this->panelUrl,
            ],
        );
    }
}
