Hi {{ $recipientName }},

Your finance tasks — {{ $summary['open'] }} open@if(($summary['overdue'] ?? 0) > 0), {{ $summary['overdue'] }} overdue@endif@if(($summary['due_today'] ?? 0) > 0), {{ $summary['due_today'] }} due today@endif.

@foreach($tasks as $t)
- {{ $t['ref'] }} · {{ ucwords(strtolower($t['category'])) }}@if($t['client']) — {{ $t['client'] }}@endif · {{ number_format($t['amount'], 2) }}@if(($t['overdue_days'] ?? 0) > 0) · {{ $t['date'] }} ({{ $t['overdue_days'] }}d OVERDUE)@elseif($t['date']) · due {{ $t['date'] }}@endif
@if($t['comment'])  {{ $t['comment'] }}
@endif
@endforeach

Update each item's status from My Work — finance is notified automatically:
{{ $panelUrl }}

One report per day, only while you have open finance tasks.
