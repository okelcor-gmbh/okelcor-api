<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\OrderLog;
use App\Services\PaymentStateCorrectionService;
use Illuminate\Console\Command;

/**
 * Inspect, sweep for, and correct orders whose payment state says money arrived
 * when nothing records that it did.
 *
 * Three modes, and the default is the one that writes nothing:
 *
 *   php artisan orders:payment-state "AB - 1182"
 *       Everything known about one order's payment state, and the log history
 *       that produced it. Reading this first is the point — the state is wrong,
 *       but which of the two columns is wrong, and how it got that way, decides
 *       what to correct it to.
 *
 *   php artisan orders:payment-state --audit
 *       Every order across the whole table that presents as paid with no
 *       gateway, no marketplace, no confirmation stamp and no audit row behind
 *       it. Answers the half of the order manager's question that is about the
 *       orders she has not looked at yet.
 *
 *   php artisan orders:payment-state "AB - 1182" --stage=pending_proforma \
 *       --reset-status --reason="deposit not received; state was never confirmed"
 *       Applies the correction. Same service, same guards and same audit row as
 *       the admin panel button — this exists for the orders that are already
 *       wrong on production, not as a second way of doing the same job.
 *
 * The sweep reports and never repairs. Nothing here can tell whether the money
 * actually arrived, only whether anybody wrote down that it did, and a rule
 * that guessed would eventually guess against the bank.
 */
class CorrectOrderPaymentState extends Command
{
    protected $signature = 'orders:payment-state
                            {ref?            : Order reference, e.g. "AB - 1182"}
                            {--audit         : Sweep every order for a paid state nothing evidences}
                            {--stage=        : Correct this order to this payment stage}
                            {--reset-status  : Also put payment_status back to pending}
                            {--reason=       : Why (required with --stage, recorded in the order log)}
                            {--force         : Skip the confirmation prompt}';

    protected $description = 'Inspect or correct an order whose payment state shows paid without a payment behind it.';

    public function __construct(private PaymentStateCorrectionService $corrections)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('audit')) {
            return $this->audit();
        }

        $ref = (string) ($this->argument('ref') ?? '');

        if ($ref === '') {
            $this->error('Name an order reference, or pass --audit to sweep them all.');

            return self::FAILURE;
        }

        // Refs on this system are hand-typed as often as generated ("AB - 1182"
        // carries spaces around its dash), so an exact match that misses is
        // more likely to be a transcription difference than a missing order.
        $order = Order::where('ref', $ref)->first()
            ?? Order::where('ref', 'like', '%' . trim(str_replace([' ', '-'], '', $ref)) . '%')->first();

        if (! $order) {
            $candidates = Order::where('ref', 'like', '%' . preg_replace('/\D+/', '', $ref) . '%')
                ->limit(10)->pluck('ref');

            $this->error("Order '{$ref}' not found.");

            if ($candidates->isNotEmpty()) {
                $this->line('Did you mean: ' . $candidates->implode(', '));
            }

            return self::FAILURE;
        }

        $this->show($order);

        if (! $this->option('stage')) {
            $this->line('');
            $this->info('Nothing written. Pass --stage=<stage> --reason="..." to correct it.');
            $this->line('Stages: ' . implode(', ', PaymentStateCorrectionService::STAGES));

            return self::SUCCESS;
        }

        return $this->correct($order);
    }

    // -------------------------------------------------------------------------

    private function show(Order $order): void
    {
        $unevidenced = $this->corrections->unevidencedReason($order);

        $this->line('');
        $this->table(['Field', 'Value'], [
            ['Ref',                 $order->ref],
            ['Source',              $order->source ?? 'website'],
            ['Payment method',      $order->payment_method ?? '(none — settled off-platform)'],
            ['Order status',        $order->status],
            ['Payment status',      $order->payment_status],
            ['Payment stage',       $order->payment_stage ?? '(null — treated as pending_proforma)'],
            ['Milestones active',   $order->paymentMilestonesActive() ? 'yes — the customer sees the ladder' : 'no'],
            ['Shows as fully paid', $order->isFullyPaid() ? 'YES — the customer portal says he has paid' : 'no'],
            ['Total',               number_format((float) $order->total, 2)],
            ['Deposit amount',      $order->deposit_amount !== null ? number_format((float) $order->deposit_amount, 2) : '—'],
            ['Deposit paid at',     $order->deposit_paid_at?->toDateTimeString() ?? '—'],
            ['Balance paid at',     $order->balance_paid_at?->toDateTimeString() ?? '—'],
            ['Shipment released',   $order->shipment_released_at?->toDateTimeString() ?? '—'],
            ['Created',             $order->created_at?->toDateTimeString() ?? '—'],
        ]);

        if ($unevidenced) {
            $this->line('');
            $this->warn('UNEVIDENCED: ' . $unevidenced . '.');
            $this->warn('Nothing on this order records a person, a gateway or a marketplace confirming receipt.');
        }

        $logs = OrderLog::where('order_id', $order->id)
            ->orderBy('created_at')
            ->get(['created_at', 'action', 'old_value', 'new_value', 'admin_user_email']);

        $this->line('');
        $this->line('History — how it got here:');

        if ($logs->isEmpty()) {
            $this->line('  (no order log rows at all)');
            $this->line('  Note: milestone actions were rejected by the ENUM until migration #31, so an');
            $this->line('  order older than 2026-08-12 having no payment history proves nothing either way.');

            return;
        }

        $this->table(
            ['When', 'Action', 'From', 'To', 'By'],
            $logs->map(fn ($l) => [
                $l->created_at?->toDateTimeString() ?? '—',
                $l->action,
                $l->old_value ?? '—',
                $l->new_value ?? '—',
                $l->admin_user_email ?? '(system)',
            ])->toArray()
        );
    }

    private function correct(Order $order): int
    {
        $stage  = (string) $this->option('stage');
        $reset  = (bool) $this->option('reset-status');
        $reason = (string) ($this->option('reason') ?: '');

        if (strlen($reason) < 5) {
            $this->error('--reason is required (min 5 characters) — this withdraws a claim that a customer paid.');

            return self::FAILURE;
        }

        if ($refusal = $this->corrections->refuse($order, $stage, $reset)) {
            $this->error($refusal['message'] . "  [{$refusal['code']}]");

            return self::FAILURE;
        }

        $this->line('');
        $this->table(['Field', 'Now', 'After'], [
            ['Payment stage',  $order->payment_stage ?? 'pending_proforma', $stage],
            ['Payment status', $order->payment_status, $reset ? 'pending' : $order->payment_status . ' (unchanged)'],
        ]);

        if (! $this->option('force') && ! $this->confirm('Write this correction?', false)) {
            $this->info('Nothing written.');

            return self::SUCCESS;
        }

        $result = $this->corrections->apply(
            $order, $stage, $reset, $reason, null, null, 'orders:payment-state'
        );

        $this->line('');
        $this->info("Corrected {$order->ref}: {$result['stage_from']} → {$result['stage_to']}"
            . ($result['status_from'] !== $result['status_to'] ? ", payment_status {$result['status_from']} → {$result['status_to']}" : ''));

        if ($result['cleared']) {
            $this->line('Cleared: ' . implode(', ', $result['cleared']));
        }

        $this->line('The customer was not e-mailed. Recorded as payment_state_corrected on the order.');

        return self::SUCCESS;
    }

    private function audit(): int
    {
        $rows = [];

        // Chunked because this reads the whole orders table and the ledger of a
        // wholesale business is not a thing to load into memory at once.
        Order::query()
            ->where(fn ($q) => $q->where('payment_status', 'paid')
                ->orWhereIn('payment_stage', ['deposit_paid', 'balance_due', 'balance_paid', 'shipment_released']))
            ->orderBy('id')
            ->chunk(200, function ($orders) use (&$rows) {
                foreach ($orders as $order) {
                    if ($reason = $this->corrections->unevidencedReason($order)) {
                        $rows[] = [
                            $order->ref,
                            $order->source ?? 'website',
                            $order->status,
                            $order->payment_status,
                            $order->payment_stage ?? '—',
                            $order->created_at?->toDateString() ?? '—',
                            $reason,
                        ];
                    }
                }
            });

        $this->line('');

        if (! $rows) {
            $this->info('No orders present as paid without something recording the payment. Nothing to review.');

            return self::SUCCESS;
        }

        $this->warn(count($rows) . ' order(s) show as paid with nothing recording who confirmed it:');
        $this->line('');
        $this->table(['Ref', 'Source', 'Status', 'Pay status', 'Stage', 'Created', 'Why flagged'], $rows);

        $this->line('');
        $this->line('This is a report, not a fault list. An order recorded from a paper backlog is');
        $this->line('supposed to look like this; a live order is not. Only somebody who can check the');
        $this->line('bank can tell them apart, which is why nothing here corrects anything.');
        $this->line('');
        $this->line('To correct one:  php artisan orders:payment-state "<ref>" --stage=pending_proforma --reset-status --reason="..."');

        return self::SUCCESS;
    }
}
