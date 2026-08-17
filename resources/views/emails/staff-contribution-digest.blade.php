<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Team contribution — {{ $report['from'] }} to {{ $report['to'] }}</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 0; }
        .container { max-width: 720px; margin: 40px auto; background: #ffffff; border-radius: 8px; overflow: hidden; }
        .header { background: #1a1a1a; padding: 28px 30px; }
        .header h1 { color: #ffffff; margin: 0; font-size: 20px; }
        .header p { color: #9a9a9a; margin: 6px 0 0; font-size: 13px; }
        .body { padding: 30px; }
        .lede { color: #444444; line-height: 1.7; margin: 0 0 24px; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        th { text-align: left; color: #777777; font-size: 11px; text-transform: uppercase;
             letter-spacing: .06em; padding: 0 8px 8px 0; border-bottom: 2px solid #1a1a1a; }
        td { padding: 11px 8px 11px 0; border-bottom: 1px solid #ececec; color: #222222; vertical-align: top; }
        td.num, th.num { text-align: right; padding-right: 0; }
        .who { font-weight: bold; }
        .job { color: #777777; font-size: 12px; display: block; margin-top: 2px; }
        .totals { margin: 26px 0 0; padding: 16px 18px; background: #fafafa; border-radius: 6px; }
        .totals p { margin: 0 0 6px; font-size: 13px; color: #444444; }
        .totals p:last-child { margin-bottom: 0; }
        .caveats { margin: 26px 0 0; padding: 16px 18px; background: #fffaf3; border-left: 3px solid #E85C1A; }
        .caveats h2 { margin: 0 0 8px; font-size: 12px; text-transform: uppercase; letter-spacing: .06em; color: #8a5a2b; }
        .caveats ul { margin: 0; padding-left: 18px; }
        .caveats li { color: #6b5540; font-size: 12.5px; line-height: 1.6; margin-bottom: 5px; }
        .cta { margin: 26px 0 0; }
        .cta a { display: inline-block; background: #E85C1A; color: #ffffff; text-decoration: none;
                 padding: 11px 20px; border-radius: 6px; font-size: 13px; font-weight: bold; }
        .footer { padding: 20px 30px; background: #f9f9f9; }
        .footer p { color: #999999; font-size: 11.5px; margin: 0; line-height: 1.6; }
    </style>
</head>
<body>
<div class="container">
    <div class="header">
        <h1>Team contribution</h1>
        <p>{{ $report['from'] }} &rarr; {{ $report['to'] }}</p>
    </div>

    <div class="body">
        <p class="lede">
            What each person worked on over the period, taken from the records the system
            already keeps. Ordered alphabetically &mdash; nobody is ranked here.
        </p>

        <table>
            <thead>
            <tr>
                <th>Person</th>
                <th class="num">Recorded</th>
                <th class="num">Logged</th>
                <th class="num">Awaiting review</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($report['people'] as $person)
                <tr>
                    <td>
                        <span class="who">{{ $person['name'] }}</span>
                        {{-- The job, not the permission set. Two order managers and the
                             person running operations all hold `admin`. --}}
                        <span class="job">{{ $person['job_title'] }}</span>
                    </td>
                    <td class="num">{{ $person['recorded']['total'] }}</td>
                    <td class="num">{{ $person['self_reported']['total'] }}</td>
                    <td class="num">{{ $person['self_reported']['pending'] ?: '—' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>

        <div class="totals">
            <p><strong>{{ $report['totals']['people_with_activity'] }}</strong> of
                {{ $report['totals']['people'] }} people have recorded activity in this period.</p>
            <p><strong>{{ $report['totals']['recorded'] }}</strong> recorded actions,
                <strong>{{ $report['totals']['self_reported'] }}</strong> self-reported entries.
                The two are separate figures and are not added together.</p>
            @if ($report['totals']['awaiting_review'] > 0)
                <p><strong>{{ $report['totals']['awaiting_review'] }}</strong>
                    self-reported {{ $report['totals']['awaiting_review'] === 1 ? 'entry is' : 'entries are' }}
                    waiting for someone to review {{ $report['totals']['awaiting_review'] === 1 ? 'it' : 'them' }}.</p>
            @endif
        </div>

        <div class="caveats">
            <h2>Before reading anything into the numbers</h2>
            <ul>
                @foreach ($report['caveats'] as $caveat)
                    <li>{{ $caveat }}</li>
                @endforeach
            </ul>
        </div>

        @if ($panelUrl)
            <div class="cta">
                <a href="{{ $panelUrl }}">Open the full breakdown</a>
            </div>
        @endif
    </div>

    <div class="footer">
        <p>
            Okelcor &mdash; team contribution report.<br>
            Sent because your address is listed in <code>STAFF_DIGEST_RECIPIENTS</code>.
            Everyone named above can see their own record in the admin panel, in full,
            exactly as it appears here.
        </p>
    </div>
</div>
</body>
</html>
