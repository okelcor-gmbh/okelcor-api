<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Job titles
    |--------------------------------------------------------------------------
    |
    | What each person actually does, keyed by the e-mail they log in with.
    |
    | This exists because `admin_users.role` is a permission set and has never
    | been a job description. Two order managers hold `admin` because they also
    | need customers, campaigns and quote requests; the person running
    | operations holds `admin` for the same reason. Grouping a contribution
    | report by role would put all three under "Admin" and describe none of
    | them.
    |
    | Seed only. `staff:sync-job-titles` applies these to accounts that have no
    | title yet, and will not overwrite one set by hand in the admin panel
    | unless it is run with --force. Editing a title in the panel is the normal
    | path; this list is what gets a new server to a sensible starting point.
    |
    | Add a person by adding their login e-mail. Anyone missing simply falls
    | back to a readable version of their role until somebody sets a title.
    |
    */
    'job_titles' => [
        'leojohnseyi@gmail.com'  => 'System Administrator',
        'solomon@okelcor.com'    => 'Managing Director',
        'edinah@okelcor.com'     => 'Order Manager',
        'yelzaveta@okelcor.com'  => 'Order Manager',
        'victor@okelcor.com'     => 'Operations Manager',
    ],

    /*
    |--------------------------------------------------------------------------
    | Repositories
    |--------------------------------------------------------------------------
    |
    | Where `staff:import-commits` reads development work from, as
    | label => absolute path. Defaults to this project when the list is empty.
    |
    | Only repositories that exist on the machine running the command can be
    | read directly. The frontend lives on Vercel and is not checked out beside
    | the API, so its history comes in through `--file=` instead — export it
    | locally, upload, import.
    |
    */
    'repositories' => array_filter([
        'okelcor-api' => base_path(),
        'okelcor-website' => env('STAFF_FRONTEND_REPO_PATH'),
    ]),

    /*
    |--------------------------------------------------------------------------
    | Git identities
    |--------------------------------------------------------------------------
    |
    | git e-mail => admin login e-mail.
    |
    | Committing from a personal address is the normal case, not an edge case —
    | people do it for years before they have a work account. Mapping the two
    | beats asking anybody to rewrite history, and a commit whose author matches
    | nothing is reported by identity so it is obvious what to add here.
    |
    */
    'git_aliases' => [
        // 'john@personal.example' => 'leojohnseyi@gmail.com',
    ],

    /*
    |--------------------------------------------------------------------------
    | Contribution digest
    |--------------------------------------------------------------------------
    |
    | Who receives the periodic team contribution report, and how far back it
    | looks. Recipients are e-mail addresses, comma-separated in the .env.
    |
    | Deliberately not "everyone with staff.view_team": a report that lands in
    | inboxes nobody asked for it in is the fastest way to make a performance
    | system feel like surveillance. It goes to the people who asked for it.
    |
    */
    'digest' => [
        'recipients' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('STAFF_DIGEST_RECIPIENTS', ''))
        ))),

        /* Days covered by a scheduled run. 30 matches the panel's default view. */
        'days' => (int) env('STAFF_DIGEST_DAYS', 30),

        /* Set false to stop the scheduled send without removing the schedule. */
        'enabled' => filter_var(env('STAFF_DIGEST_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    ],

];
