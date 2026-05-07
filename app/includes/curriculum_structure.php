<?php
declare(strict_types=1);

/**
 * Elevaro Curriculum-Struktur, an die vorhandenen DB-Codes angepasst.
 *
 * Wichtig:
 * - Fach-Code Mathematik bleibt aus Kompatibilitaet zur bestehenden DB: "mathe".
 * - Schulart-Codes entsprechen `school_types.code`.
 * - Stufen-Codes entsprechen `school_type_levels.code`.
 */

function elevaro_curriculum_states(): array
{
    return [
        'BW' => 'Baden-Württemberg',
        'BY' => 'Bayern',
        'BE' => 'Berlin',
        'BB' => 'Brandenburg',
        'HB' => 'Bremen',
        'HH' => 'Hamburg',
        'HE' => 'Hessen',
        'MV' => 'Mecklenburg-Vorpommern',
        'NI' => 'Niedersachsen',
        'NW' => 'Nordrhein-Westfalen',
        'RP' => 'Rheinland-Pfalz',
        'SL' => 'Saarland',
        'SN' => 'Sachsen',
        'ST' => 'Sachsen-Anhalt',
        'SH' => 'Schleswig-Holstein',
        'TH' => 'Thüringen',
    ];
}

function elevaro_curriculum_school_types(): array
{
    return [
        'grundschule' => [
            'label' => 'Grundschule',
            'grades' => elevaro_curriculum_numeric_grade_jobs(1, 4),
            'subjects_by_grade' => elevaro_curriculum_primary_subjects_by_grade(),
        ],
        'hauptschule' => [
            'label' => 'Hauptschule',
            'grades' => elevaro_curriculum_numeric_grade_jobs(5, 10),
            'subjects_by_grade' => elevaro_curriculum_secondary_subjects_by_grade(false),
        ],
        'werkrealschule' => [
            'label' => 'Werkrealschule',
            'grades' => elevaro_curriculum_numeric_grade_jobs(5, 10),
            'subjects_by_grade' => elevaro_curriculum_secondary_subjects_by_grade(false),
        ],
        'realschule' => [
            'label' => 'Realschule',
            'grades' => elevaro_curriculum_numeric_grade_jobs(5, 10),
            'subjects_by_grade' => elevaro_curriculum_secondary_subjects_by_grade(false),
        ],
        'gemeinschaftsschule' => [
            'label' => 'Gemeinschaftsschule',
            'grades' => elevaro_curriculum_numeric_grade_jobs(5, 13),
            'subjects_by_grade' => elevaro_curriculum_secondary_subjects_by_grade(true),
        ],
        'gesamtschule' => [
            'label' => 'Gesamtschule',
            'grades' => elevaro_curriculum_numeric_grade_jobs(5, 13),
            'subjects_by_grade' => elevaro_curriculum_secondary_subjects_by_grade(true),
        ],
        'gymnasium' => [
            'label' => 'Gymnasium',
            'grades' => elevaro_curriculum_numeric_grade_jobs(5, 13),
            'subjects_by_grade' => elevaro_curriculum_gymnasium_subjects_by_grade(),
        ],
        'berufliches-gymnasium' => [
            'label' => 'Berufliches Gymnasium',
            'grades' => [
                ['key' => 'eingangsklasse', 'from' => 11, 'to' => 11, 'label' => 'Eingangsklasse'],
                ['key' => 'j1', 'from' => 12, 'to' => 12, 'label' => 'J1'],
                ['key' => 'j2', 'from' => 13, 'to' => 13, 'label' => 'J2'],
            ],
            'subjects_by_grade' => [
                'eingangsklasse' => elevaro_curriculum_vocational_subjects(),
                'j1' => elevaro_curriculum_vocational_subjects(),
                'j2' => elevaro_curriculum_vocational_subjects(),
            ],
        ],
        'berufskolleg' => [
            'label' => 'Berufskolleg',
            'grades' => [
                ['key' => 'bk1', 'from' => null, 'to' => null, 'label' => 'BK1'],
                ['key' => 'bk2', 'from' => null, 'to' => null, 'label' => 'BK2'],
            ],
            'subjects_by_grade' => [
                'bk1' => elevaro_curriculum_vocational_subjects(),
                'bk2' => elevaro_curriculum_vocational_subjects(),
            ],
        ],
        'berufsschule-dual' => [
            'label' => 'Berufsschule (duale Ausbildung)',
            'grades' => [
                ['key' => '1-lehrjahr', 'from' => null, 'to' => null, 'label' => '1. Lehrjahr'],
                ['key' => '2-lehrjahr', 'from' => null, 'to' => null, 'label' => '2. Lehrjahr'],
                ['key' => '3-lehrjahr', 'from' => null, 'to' => null, 'label' => '3. Lehrjahr'],
            ],
            'subjects_by_grade' => [
                '1-lehrjahr' => elevaro_curriculum_dual_vocational_subjects(),
                '2-lehrjahr' => elevaro_curriculum_dual_vocational_subjects(),
                '3-lehrjahr' => elevaro_curriculum_dual_vocational_subjects(),
            ],
        ],
        'berufsfachschule' => [
            'label' => 'Berufsfachschule',
            'grades' => [
                ['key' => '1', 'from' => null, 'to' => null, 'label' => '1. Jahr'],
                ['key' => '2', 'from' => null, 'to' => null, 'label' => '2. Jahr'],
            ],
            'subjects_by_grade' => [
                '1' => elevaro_curriculum_dual_vocational_subjects(),
                '2' => elevaro_curriculum_dual_vocational_subjects(),
            ],
        ],
        'sbbz' => [
            'label' => 'SBBZ',
            'grades' => [
                ['key' => 'basisstufe', 'from' => null, 'to' => null, 'label' => 'Basisstufe'],
                ['key' => 'hauptstufe', 'from' => null, 'to' => null, 'label' => 'Hauptstufe'],
                ['key' => 'berufsschulstufe', 'from' => null, 'to' => null, 'label' => 'Berufsschulstufe'],
            ],
            'subjects_by_grade' => [
                'basisstufe' => elevaro_curriculum_sbbz_subjects(),
                'hauptstufe' => elevaro_curriculum_sbbz_subjects(),
                'berufsschulstufe' => elevaro_curriculum_sbbz_subjects(),
            ],
        ],
    ];
}

function elevaro_curriculum_numeric_grade_jobs(int $from, int $to): array
{
    $out = [];
    for ($grade = $from; $grade <= $to; $grade++) {
        $out[] = ['key' => (string)$grade, 'from' => $grade, 'to' => $grade, 'label' => 'Klasse ' . $grade];
    }
    return $out;
}

function elevaro_curriculum_primary_subjects_by_grade(): array
{
    $oneTwo = [
        'deutsch' => 'Deutsch',
        'mathe' => 'Mathematik',
        'sachunterricht' => 'Sachunterricht',
        'kunst_werken' => 'Kunst/Werken',
        'musik' => 'Musik',
        'sport' => 'Sport',
        'religion_ethik' => 'Religion/Ethik',
    ];

    $threeFour = $oneTwo + ['englisch' => 'Englisch'];

    return ['1' => $oneTwo, '2' => $oneTwo, '3' => $threeFour, '4' => $threeFour];
}

function elevaro_curriculum_secondary_subjects_by_grade(bool $withUpperGrades = false): array
{
    $byGrade = [
        '5' => [
            'deutsch' => 'Deutsch', 'mathe' => 'Mathematik', 'englisch' => 'Englisch',
            'geographie' => 'Geographie', 'biologie' => 'Biologie', 'bnt' => 'BNT',
            'kunst_werken' => 'Kunst/Werken', 'musik' => 'Musik', 'sport' => 'Sport', 'religion_ethik' => 'Religion/Ethik',
        ],
        '6' => [
            'deutsch' => 'Deutsch', 'mathe' => 'Mathematik', 'englisch' => 'Englisch', 'franzoesisch' => 'Französisch',
            'geographie' => 'Geographie', 'biologie' => 'Biologie', 'geschichte' => 'Geschichte', 'bnt' => 'BNT',
            'kunst_werken' => 'Kunst/Werken', 'musik' => 'Musik', 'sport' => 'Sport', 'religion_ethik' => 'Religion/Ethik',
        ],
        '7' => [
            'deutsch' => 'Deutsch', 'mathe' => 'Mathematik', 'englisch' => 'Englisch', 'franzoesisch' => 'Französisch',
            'geographie' => 'Geographie', 'biologie' => 'Biologie', 'geschichte' => 'Geschichte', 'gemeinschaftskunde' => 'Gemeinschaftskunde',
            'physik' => 'Physik', 'technik' => 'Technik', 'aes' => 'AES', 'kunst_werken' => 'Kunst/Werken',
            'musik' => 'Musik', 'sport' => 'Sport', 'religion_ethik' => 'Religion/Ethik',
        ],
    ];

    $eightToTen = [
        'deutsch' => 'Deutsch', 'mathe' => 'Mathematik', 'englisch' => 'Englisch', 'franzoesisch' => 'Französisch',
        'geographie' => 'Geographie', 'biologie' => 'Biologie', 'geschichte' => 'Geschichte', 'gemeinschaftskunde' => 'Gemeinschaftskunde',
        'chemie' => 'Chemie', 'physik' => 'Physik', 'technik' => 'Technik', 'aes' => 'AES', 'wirtschaft' => 'Wirtschaft',
        'kunst_werken' => 'Kunst/Werken', 'musik' => 'Musik', 'sport' => 'Sport', 'religion_ethik' => 'Religion/Ethik',
    ];

    foreach (['8', '9', '10'] as $grade) {
        $byGrade[$grade] = $eightToTen;
    }

    if ($withUpperGrades) {
        $upper = elevaro_curriculum_upper_grade_subjects();
        foreach (['11', '12', '13'] as $grade) {
            $byGrade[$grade] = $upper;
        }
    }

    return $byGrade;
}

function elevaro_curriculum_gymnasium_subjects_by_grade(): array
{
    $byGrade = elevaro_curriculum_secondary_subjects_by_grade(false);

    $byGrade['5']['latein'] = 'Latein';
    $byGrade['6']['latein'] = 'Latein';
    $byGrade['6']['informatik'] = 'Informatik';
    $byGrade['7']['latein'] = 'Latein';
    $byGrade['7']['informatik'] = 'Informatik';
    $byGrade['8']['latein'] = 'Latein';
    $byGrade['8']['informatik'] = 'Informatik';
    $byGrade['8']['nwt'] = 'NWT';
    $byGrade['9']['latein'] = 'Latein';
    $byGrade['9']['informatik'] = 'Informatik';
    $byGrade['9']['nwt'] = 'NWT';
    $byGrade['10']['latein'] = 'Latein';
    $byGrade['10']['informatik'] = 'Informatik';
    $byGrade['10']['nwt'] = 'NWT';

    $upper = elevaro_curriculum_upper_grade_subjects() + ['latein' => 'Latein'];
    foreach (['11', '12', '13'] as $grade) {
        $byGrade[$grade] = $upper;
    }

    return $byGrade;
}

function elevaro_curriculum_upper_grade_subjects(): array
{
    return [
        'deutsch' => 'Deutsch', 'mathe' => 'Mathematik', 'englisch' => 'Englisch', 'franzoesisch' => 'Französisch',
        'geschichte' => 'Geschichte', 'geographie' => 'Geographie', 'gemeinschaftskunde' => 'Gemeinschaftskunde', 'wirtschaft' => 'Wirtschaft',
        'biologie' => 'Biologie', 'chemie' => 'Chemie', 'physik' => 'Physik', 'informatik' => 'Informatik',
        'bildende_kunst' => 'Bildende Kunst', 'musik' => 'Musik', 'sport' => 'Sport', 'religion_ethik' => 'Religion/Ethik',
    ];
}

function elevaro_curriculum_vocational_subjects(): array
{
    return [
        'deutsch' => 'Deutsch', 'mathe' => 'Mathematik', 'englisch' => 'Englisch',
        'geschichte' => 'Geschichte', 'gemeinschaftskunde' => 'Gemeinschaftskunde', 'religion_ethik' => 'Religion/Ethik', 'sport' => 'Sport',
        'biologie' => 'Biologie', 'chemie' => 'Chemie', 'physik' => 'Physik', 'informatik' => 'Informatik',
        'bwl' => 'BWL', 'vwl' => 'VWL', 'wirtschaft' => 'Wirtschaft', 'rechnungswesen' => 'Rechnungswesen',
        'datenverarbeitung' => 'Datenverarbeitung', 'projektkompetenz' => 'Projektkompetenz',
    ];
}

function elevaro_curriculum_dual_vocational_subjects(): array
{
    return [
        'deutsch' => 'Deutsch', 'mathe' => 'Mathematik', 'englisch' => 'Englisch',
        'gemeinschaftskunde' => 'Gemeinschaftskunde', 'religion_ethik' => 'Religion/Ethik',
        'wirtschaft' => 'Wirtschaft', 'bwl' => 'BWL', 'rechnungswesen' => 'Rechnungswesen',
        'datenverarbeitung' => 'Datenverarbeitung', 'projektkompetenz' => 'Projektkompetenz',
    ];
}

function elevaro_curriculum_sbbz_subjects(): array
{
    return [
        'deutsch' => 'Deutsch/Kommunikation', 'mathe' => 'Mathematik', 'sachunterricht' => 'Sachunterricht',
        'kunst_werken' => 'Kunst/Werken', 'musik' => 'Musik', 'sport' => 'Sport', 'religion_ethik' => 'Religion/Ethik',
    ];
}

function elevaro_curriculum_subjects_for_grade(string $schoolTypeKey, string $gradeKey): array
{
    $profiles = elevaro_curriculum_school_types();
    return $profiles[$schoolTypeKey]['subjects_by_grade'][$gradeKey] ?? [];
}

function elevaro_curriculum_is_valid_combination(string $stateCode, string $schoolTypeKey, string $gradeKey, string $subjectKey): bool
{
    $stateCode = strtoupper($stateCode);
    if (!isset(elevaro_curriculum_states()[$stateCode])) {
        return false;
    }
    return isset(elevaro_curriculum_subjects_for_grade($schoolTypeKey, $gradeKey)[$subjectKey]);
}

function elevaro_curriculum_build_jobs(?string $onlyState = null, ?string $onlySchoolType = null, ?string $onlyGrade = null, ?string $onlySubject = null): array
{
    $states = elevaro_curriculum_states();
    $profiles = elevaro_curriculum_school_types();
    $jobs = [];

    foreach ($states as $stateCode => $stateLabel) {
        if ($onlyState !== null && strtoupper($onlyState) !== $stateCode) {
            continue;
        }

        foreach ($profiles as $schoolKey => $profile) {
            if ($onlySchoolType !== null && $onlySchoolType !== $schoolKey) {
                continue;
            }

            foreach ($profile['grades'] as $grade) {
                if ($onlyGrade !== null && $onlyGrade !== $grade['key']) {
                    continue;
                }

                foreach (($profile['subjects_by_grade'][$grade['key']] ?? []) as $subjectKey => $subjectLabel) {
                    if ($onlySubject !== null && $onlySubject !== $subjectKey) {
                        continue;
                    }

                    $jobs[] = [
                        'state_code' => $stateCode,
                        'state_label' => $stateLabel,
                        'school_type_key' => $schoolKey,
                        'school_type_label' => $profile['label'],
                        'grade_key' => $grade['key'],
                        'grade_label' => $grade['label'],
                        'grade_from' => $grade['from'],
                        'grade_to' => $grade['to'],
                        'subject_key' => $subjectKey,
                        'subject_label' => $subjectLabel,
                    ];
                }
            }
        }
    }

    return $jobs;
}
