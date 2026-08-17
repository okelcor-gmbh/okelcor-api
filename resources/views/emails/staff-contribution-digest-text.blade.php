TEAM CONTRIBUTION
{{ $report['from'] }} to {{ $report['to'] }}

What each person worked on over the period, taken from the records the system
already keeps. Ordered alphabetically — nobody is ranked here.

@foreach ($report['people'] as $person)
{{ $person['name'] }} — {{ $person['job_title'] }}
  Recorded: {{ $person['recorded']['total'] }}   Logged: {{ $person['self_reported']['total'] }}   Awaiting review: {{ $person['self_reported']['pending'] ?: '0' }}
@endforeach

SUMMARY
{{ $report['totals']['people_with_activity'] }} of {{ $report['totals']['people'] }} people have recorded activity in this period.
{{ $report['totals']['recorded'] }} recorded actions, {{ $report['totals']['self_reported'] }} self-reported entries.
The two are separate figures and are not added together.
@if ($report['totals']['awaiting_review'] > 0)
{{ $report['totals']['awaiting_review'] }} self-reported entries are waiting for review.
@endif

BEFORE READING ANYTHING INTO THE NUMBERS
@foreach ($report['caveats'] as $caveat)
- {{ $caveat }}
@endforeach
@if ($panelUrl)

Full breakdown: {{ $panelUrl }}
@endif

--
Okelcor — team contribution report.
Sent because your address is listed in STAFF_DIGEST_RECIPIENTS. Everyone named
above can see their own record in the admin panel, in full, exactly as it
appears here.
