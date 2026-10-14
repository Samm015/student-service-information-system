<?php

namespace App\Support;

use App\Models\Enrollment;

/**
 * Helpers for "school year + semester" pairs, e.g. 2026-2027 / 1st Semester.
 * The current term comes from config/school.php.
 */
final class AcademicTerm
{
    /** @return array{school_year: string, semester: string} */
    public static function current(): array
    {
        $semester = config('school.semester');

        return [
            'school_year' => (string) config('school.school_year'),
            'semester'    => in_array($semester, Enrollment::SEMESTERS, true) ? $semester : Enrollment::SEMESTERS[0],
        ];
    }

    /** The term right after the current one (1st -> 2nd -> Summer -> next year's 1st). */
    public static function next(): array
    {
        $current = self::current();
        $index   = array_search($current['semester'], Enrollment::SEMESTERS, true);

        if ($index < count(Enrollment::SEMESTERS) - 1) {
            return ['school_year' => $current['school_year'], 'semester' => Enrollment::SEMESTERS[$index + 1]];
        }

        [$from, $to] = array_map('intval', explode('-', $current['school_year']) + [0, 0]);

        return ['school_year' => ($from + 1) . '-' . ($to + 1), 'semester' => Enrollment::SEMESTERS[0]];
    }

    /** Terms a student may enroll in: the current one and the next one. */
    public static function enrollable(): array
    {
        return [self::current(), self::next()];
    }

    public static function isEnrollable(?string $schoolYear, ?string $semester): bool
    {
        foreach (self::enrollable() as $term) {
            if ($term['school_year'] === $schoolYear && $term['semester'] === $semester) {
                return true;
            }
        }

        return false;
    }

    public static function isCurrent(?string $schoolYear, ?string $semester): bool
    {
        $current = self::current();

        return $current['school_year'] === $schoolYear && $current['semester'] === $semester;
    }

    public static function label(string $schoolYear, string $semester): string
    {
        return "{$semester}, SY {$schoolYear}";
    }

    /** Sortable string: newer terms sort higher ("2026-2027-1"). */
    public static function sortKey(string $schoolYear, string $semester): string
    {
        $position = array_search($semester, Enrollment::SEMESTERS, true);

        return $schoolYear . '-' . (($position === false ? 0 : $position) + 1);
    }
}
