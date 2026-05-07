<?php
declare(strict_types=1);

/**
 * Zentrale Elevaro-Curriculum-Struktur.
 *
 * Ziel:
 * - keine unsinnigen Kombinationen wie Grundschule Klasse 4 + Chemie
 * - stabile Keys fuer Wizard, Seeder und spaetere Filter
 * - erstmal pragmatisch deutschlandweit, spaeter pro Bundesland verfeinerbar
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
            'grades' => [
                ['key' => '1_2', 'from' => 1, 'to' => 2, 'label' => 'Klasse 1/2'],
                ['key' => '3_4', 'from' => 3, 'to' => 4, 'label' => 'Klasse 3/4'],
            ],
            'subjects_by_grade' => [
                '1_2' => [
                    'deutsch' => 'Deutsch',
                    'mathematik' => 'Mathematik',
                    'sachunterricht' => 'Sachunterricht',
                    'kunst_werken' => 'Kunst/Werken',
                    'musik' => 'Musik',
                    'bewegung_spiel_und_sport' => 'Bewegung, Spiel und Sport',
                    'religion_ethik' => 'Religion/Ethik',
                ],
                '3_4' => [
                    'deutsch' => 'Deutsch',
                    'mathematik' => 'Mathematik',
                    'sachunterricht' => 'Sachunterricht',
                    'englisch' => 'Englisch',
                    'kunst_werken' => 'Kunst/Werken',
                    'musik' => 'Musik',
                    'bewegung_spiel_und_sport' => 'Bewegung, Spiel und Sport',
                    'religion_ethik' => 'Religion/Ethik',
                ],
            ],
        ],

        'hauptschule_werkrealschule' => [
            'label' => 'Hauptschule/Werkrealschule',
            'grades' => elevaro_curriculum_numeric_grade_jobs(5, 10),
            'subjects_by_grade' => elevaro_curriculum_secondary_subjects_by_grade('hs_rs_gms'),
        ],

        'realschule' => [
            'label' => 'Realschule',
            'grades' => elevaro_curriculum_numeric_grade_jobs(5, 10),
            'subjects_by_grade' => elevaro_curriculum_secondary_subjects_by_grade('hs_rs_gms'),
        ],

        'gemeinschaftsschule' => [
            'label' => 'Gemeinschaftsschule',
            'grades' => elevaro_curriculum_numeric_grade_jobs(5, 10),
            'subjects_by_grade' => elevaro_curriculum_secondary_subjects_by_grade('hs_rs_gms'),
        ],

        'gymnasium' => [
            'label' => 'Gymnasium',
            'grades' => array_merge(elevaro_curriculum_numeric_grade_jobs(5, 10), [
                ['key' => 'kursstufe_1', 'from' => 11, 'to' => 11, 'label' => 'Kursstufe 1'],
                ['key' => 'kursstufe_2', 'from' => 12, 'to' => 12, 'label' => 'Kursstufe 2'],
            ]),
            'subjects_by_grade' => elevaro_curriculum_gymnasium_subjects_by_grade(),
        ],

        'berufliches_gymnasium' => [
            'label' => 'Berufliches Gymnasium',
            'grades' => [
                ['key' => 'eingangsklasse', 'from' => 11, 'to' => 11, 'label' => 'Eingangsklasse'],
                ['key' => 'kursstufe_1', 'from' => 12, 'to' => 12, 'label' => 'Kursstufe 1'],
                ['key' => 'kursstufe_2', 'from' => 13, 'to' => 13, 'label' => 'Kursstufe 2'],
            ],
            'subjects_by_grade' => [
                'eingangsklasse' => elevaro_curriculum_vocational_gymnasium_subjects(),
                'kursstufe_1' => elevaro_curriculum_vocational_gymnasium_subjects(),
                'kursstufe_2' => elevaro_curriculum_vocational_gymnasium_subjects(),
            ],
        ],

        'berufskolleg' => [
            'label' => 'Berufskolleg',
            'grades' => [
                ['key' => 'bk1', 'from' => null, 'to' => null, 'label' => 'BK I'],
                ['key' => 'bk2', 'from' => null, 'to' => null, 'label' => 'BK II'],
            ],
            'subjects_by_grade' => [
                'bk1' => elevaro_curriculum_berufskolleg_subjects(),
                'bk2' => elevaro_curriculum_berufskolleg_subjects(),
            ],
        ],

        'sbbz' => [
            'label' => 'Sonderpädagogisches Bildungs- und Beratungszentrum',
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
        $out[] = [
            'key' => (string)$grade,
            'from' => $grade,
            'to' => $grade,
            'label' => 'Klasse ' . $grade,
        ];
    }
    return $out;
}

function elevaro_curriculum_secondary_subjects_by_grade(string $profile = 'hs_rs_gms'): array
{
    $base = [
        'deutsch' => 'Deutsch',
        'mathematik' => 'Mathematik',
        'englisch' => 'Englisch',
        'geographie' => 'Geographie',
        'biologie' => 'Biologie',
        'bildende_kunst' => 'Bildende Kunst',
        'musik' => 'Musik',
        'sport' => 'Sport',
        'religion_ethik' => 'Religion/Ethik',
    ];

    return [
        '5' => $base + [
            'bnt' => 'Biologie, Naturphänomene und Technik',
        ],
        '6' => $base + [
            'franzoesisch' => 'Französisch',
            'geschichte' => 'Geschichte',
            'bnt' => 'Biologie, Naturphänomene und Technik',
        ],
        '7' => $base + [
            'franzoesisch' => 'Französisch',
            'geschichte' => 'Geschichte',
            'gemeinschaftskunde' => 'Gemeinschaftskunde',
            'physik' => 'Physik',
            'technik' => 'Technik',
            'aes' => 'Alltagskultur, Ernährung, Soziales',
        ],
        '8' => $base + [
            'franzoesisch' => 'Französisch',
            'geschichte' => 'Geschichte',
            'gemeinschaftskunde' => 'Gemeinschaftskunde',
            'chemie' => 'Chemie',
            'physik' => 'Physik',
            'technik' => 'Technik',
            'aes' => 'Alltagskultur, Ernährung, Soziales',
            'wirtschaft_berufs_und_studienorientierung' => 'Wirtschaft/Berufs- und Studienorientierung',
        ],
        '9' => $base + [
            'franzoesisch' => 'Französisch',
            'geschichte' => 'Geschichte',
            'gemeinschaftskunde' => 'Gemeinschaftskunde',
            'chemie' => 'Chemie',
            'physik' => 'Physik',
            'technik' => 'Technik',
            'aes' => 'Alltagskultur, Ernährung, Soziales',
            'wirtschaft_berufs_und_studienorientierung' => 'Wirtschaft/Berufs- und Studienorientierung',
        ],
        '10' => $base + [
            'franzoesisch' => 'Französisch',
            'geschichte' => 'Geschichte',
            'gemeinschaftskunde' => 'Gemeinschaftskunde',
            'chemie' => 'Chemie',
            'physik' => 'Physik',
            'technik' => 'Technik',
            'aes' => 'Alltagskultur, Ernährung, Soziales',
            'wirtschaft_berufs_und_studienorientierung' => 'Wirtschaft/Berufs- und Studienorientierung',
        ],
    ];
}

function elevaro_curriculum_gymnasium_subjects_by_grade(): array
{
    $secondary = elevaro_curriculum_secondary_subjects_by_grade('gymnasium');

    $secondary['5']['latein'] = 'Latein';
    $secondary['6']['latein'] = 'Latein';
    $secondary['6']['informatik'] = 'Informatik';
    $secondary['7']['latein'] = 'Latein';
    $secondary['7']['griechisch'] = 'Griechisch';
    $secondary['7']['informatik'] = 'Informatik';
    $secondary['8']['latein'] = 'Latein';
    $secondary['8']['griechisch'] = 'Griechisch';
    $secondary['8']['informatik'] = 'Informatik';
    $secondary['8']['nwt'] = 'Naturwissenschaft und Technik';
    $secondary['9']['latein'] = 'Latein';
    $secondary['9']['griechisch'] = 'Griechisch';
    $secondary['9']['informatik'] = 'Informatik';
    $secondary['9']['nwt'] = 'Naturwissenschaft und Technik';
    $secondary['9']['wirtschaft'] = 'Wirtschaft';
    $secondary['10']['latein'] = 'Latein';
    $secondary['10']['griechisch'] = 'Griechisch';
    $secondary['10']['informatik'] = 'Informatik';
    $secondary['10']['nwt'] = 'Naturwissenschaft und Technik';
    $secondary['10']['wirtschaft'] = 'Wirtschaft';

    $courseSubjects = [
        'deutsch' => 'Deutsch',
        'mathematik' => 'Mathematik',
        'englisch' => 'Englisch',
        'franzoesisch' => 'Französisch',
        'latein' => 'Latein',
        'geschichte' => 'Geschichte',
        'geographie' => 'Geographie',
        'gemeinschaftskunde' => 'Gemeinschaftskunde',
        'wirtschaft' => 'Wirtschaft',
        'biologie' => 'Biologie',
        'chemie' => 'Chemie',
        'physik' => 'Physik',
        'informatik' => 'Informatik',
        'bildende_kunst' => 'Bildende Kunst',
        'musik' => 'Musik',
        'sport' => 'Sport',
        'religion_ethik' => 'Religion/Ethik',
    ];

    $secondary['kursstufe_1'] = $courseSubjects;
    $secondary['kursstufe_2'] = $courseSubjects;

    return $secondary;
}

function elevaro_curriculum_vocational_gymnasium_subjects(): array
{
    return [
        'deutsch' => 'Deutsch',
        'mathematik' => 'Mathematik',
        'englisch' => 'Englisch',
        'geschichte_gemeinschaftskunde' => 'Geschichte/Gemeinschaftskunde',
        'religion_ethik' => 'Religion/Ethik',
        'biologie' => 'Biologie',
        'chemie' => 'Chemie',
        'physik' => 'Physik',
        'informatik' => 'Informatik',
        'bwl' => 'Betriebswirtschaftslehre',
        'vwl' => 'Volkswirtschaftslehre',
        'profilfach_wirtschaft' => 'Profilfach Wirtschaft',
        'profilfach_technik' => 'Profilfach Technik',
        'profilfach_soziales' => 'Profilfach Soziales',
    ];
}

function elevaro_curriculum_berufskolleg_subjects(): array
{
    return [
        'deutsch' => 'Deutsch',
        'mathematik' => 'Mathematik',
        'englisch' => 'Englisch',
        'gemeinschaftskunde' => 'Gemeinschaftskunde',
        'religion_ethik' => 'Religion/Ethik',
        'bwl' => 'Betriebswirtschaftslehre',
        'vwl' => 'Volkswirtschaftslehre',
        'rechnungswesen' => 'Rechnungswesen',
        'datenverarbeitung' => 'Datenverarbeitung',
        'projektkompetenz' => 'Projektkompetenz',
    ];
}

function elevaro_curriculum_sbbz_subjects(): array
{
    return [
        'deutsch' => 'Deutsch/Kommunikation',
        'mathematik' => 'Mathematik',
        'sachunterricht' => 'Sachunterricht',
        'lebensgestaltung' => 'Lebensgestaltung',
        'bewegung' => 'Bewegung',
        'kunst_musik' => 'Kunst/Musik',
    ];
}

function elevaro_curriculum_subjects_for_grade(string $schoolTypeKey, string $gradeKey): array
{
    $profiles = elevaro_curriculum_school_types();

    if (!isset($profiles[$schoolTypeKey])) {
        return [];
    }

    return $profiles[$schoolTypeKey]['subjects_by_grade'][$gradeKey] ?? [];
}

function elevaro_curriculum_is_valid_combination(string $stateCode, string $schoolTypeKey, string $gradeKey, string $subjectKey): bool
{
    $stateCode = strtoupper($stateCode);

    if (!isset(elevaro_curriculum_states()[$stateCode])) {
        return false;
    }

    $subjects = elevaro_curriculum_subjects_for_grade($schoolTypeKey, $gradeKey);

    return isset($subjects[$subjectKey]);
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

                $subjects = $profile['subjects_by_grade'][$grade['key']] ?? [];
                foreach ($subjects as $subjectKey => $subjectLabel) {
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
