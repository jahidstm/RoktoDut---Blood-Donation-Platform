<?php

namespace App\Services\RequestText;

use App\Models\District;
use App\Models\Hospital;
use Illuminate\Support\Collection;

/**
 * Finds the hospital and district a blood-request message refers to, using the
 * hospitals and districts tables as the gazetteer (the database is the single source
 * of truth; the Python parser knows nothing about it).
 *
 * Safety rules, in the same spirit as the blood-group parser:
 *  - two different hospitals with an equally specific match -> no hospital;
 *  - two different districts named -> no district;
 *  - a hospital whose district differs from a district named in the text -> drop the
 *    hospital (e.g. "Evercare Chattogram" must not become Evercare Dhaka).
 */
class PlaceResolver
{
    /** Trailing words removed to get a hospital's short name ("Dhaka Medical College Hospital" -> "Dhaka Medical"). */
    private const GENERIC_TAIL = '/\s*(?:&|and|ও)?\s*(?:college|hospital|কলেজ|হাসপাতাল)\s*$/iu';

    /**
     * @return array{hospital: ?Hospital, district: ?District, warnings: list<string>, evidence: array<string, string>}
     */
    public function resolve(string $text): array
    {
        $norm = BanglaText::normalize($text);
        $warnings = [];
        $evidence = [];

        [$hospital, $hospitalMatch, $hospitalWarning] = $this->matchHospital($norm);
        [$textDistrict, $districtMatch, $districtWarning] = $this->matchDistrict($norm);

        if ($hospitalWarning) {
            $warnings[] = $hospitalWarning;
        }
        if ($districtWarning) {
            $warnings[] = $districtWarning;
        }

        if ($hospital && $textDistrict && $hospital->district_id && (int) $hospital->district_id !== (int) $textDistrict->id) {
            $warnings[] = "'{$hospitalMatch}' matched {$hospital->name}, which is not in {$textDistrict->name}; hospital left empty";
            $hospital = null;
            $hospitalMatch = null;
        }

        $district = $textDistrict ?? ($hospital?->district_id ? District::find($hospital->district_id) : null);

        if ($hospitalMatch) {
            $evidence['hospital'] = $hospitalMatch;
        }
        if ($districtMatch) {
            $evidence['district'] = $districtMatch;
        } elseif ($district && $hospital) {
            $evidence['district'] = 'from hospital';
        }

        return ['hospital' => $hospital, 'district' => $district, 'warnings' => $warnings, 'evidence' => $evidence];
    }

    /**
     * @return array{0: ?Hospital, 1: ?string, 2: ?string}
     */
    private function matchHospital(string $norm): array
    {
        $best = [];      // hospital id => [length, matched form]
        $hospitals = Hospital::query()->get(['id', 'name', 'name_bn', 'aliases', 'district_id', 'is_verified']);

        foreach ($hospitals as $h) {
            foreach ($this->hospitalForms($h) as $form) {
                if (BanglaText::contains($norm, $form)) {
                    $len = mb_strlen($form);
                    if (!isset($best[$h->id]) || $len > $best[$h->id][0]) {
                        $best[$h->id] = [$len, $form];
                    }
                }
            }
        }

        if ($best === []) {
            return [null, null, null];
        }

        uasort($best, fn ($a, $b) => $b[0] <=> $a[0]);
        $ids = array_keys($best);
        $top = $best[$ids[0]];

        // A tie between two different hospitals is not resolvable from the text.
        if (isset($ids[1]) && $best[$ids[1]][0] === $top[0]) {
            return [null, null, 'several hospitals match equally: ' . $top[1]];
        }

        return [$hospitals->firstWhere('id', $ids[0]), $top[1], null];
    }

    /** @return list<string> normalised names, aliases and short names worth matching */
    private function hospitalForms(Hospital $h): array
    {
        $forms = array_filter([$h->name, $h->name_bn, ...((array) ($h->aliases ?? []))]);

        foreach ([$h->name, $h->name_bn] as $name) {
            if (!$name) {
                continue;
            }
            $short = $name;
            // Strip "College Hospital", "& Hospital", "কলেজ হাসপাতাল" ... from the end.
            while (($next = preg_replace(self::GENERIC_TAIL, '', $short)) !== $short && $next !== null) {
                $short = $next;
            }
            // Only multi-word short names: "Square" alone is too generic to derive automatically.
            if ($short !== $name && count(preg_split('/\s+/u', trim($short))) >= 2) {
                $forms[] = $short;
            }
        }

        return array_values(array_unique(array_map([BanglaText::class, 'normalize'], $forms)));
    }

    /**
     * @return array{0: ?District, 1: ?string, 2: ?string}
     */
    private function matchDistrict(string $norm): array
    {
        $aliases = config('districts.aliases', []);
        $hits = [];

        /** @var Collection<int, District> $districts */
        $districts = District::query()->get(['id', 'division_id', 'name']);

        foreach ($districts as $d) {
            foreach ([$d->name, ...($aliases[$d->name] ?? [])] as $form) {
                $f = BanglaText::normalize($form);
                if (BanglaText::contains($norm, $f)) {
                    $hits[$d->id] = [$d, $form];
                    break;
                }
            }
        }

        if (count($hits) === 1) {
            $hit = reset($hits);

            return [$hit[0], $hit[1], null];
        }

        if (count($hits) > 1) {
            $names = implode(', ', array_map(fn ($h) => $h[0]->name, $hits));

            return [null, null, "several districts mentioned: {$names}"];
        }

        return [null, null, null];
    }
}
