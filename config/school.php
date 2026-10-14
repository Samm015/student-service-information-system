<?php

/*
| The school's CURRENT term. The student portal uses it for clearance and as
| the first choice in the enrollment form. Change it in .env when a new
| semester opens (it becomes an admin setting in a later session):
|
|   SCHOOL_YEAR=2026-2027
|   SCHOOL_SEMESTER="1st Semester"
*/

return [
    'school_year' => env('SCHOOL_YEAR', '2026-2027'),
    'semester'    => env('SCHOOL_SEMESTER', '1st Semester'),

    // Replace or extend this list with the institution's approved programs.
    'programs' => ['BSIT', 'BSCS'],

    // Service windows staff can call students to.
    'counters' => ['Window 1', 'Window 2', 'Window 3'],
];