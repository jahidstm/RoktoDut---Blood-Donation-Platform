<?php

namespace App\Services\RequestText;

/**
 * Turns a free-text blood request into form fields: language understanding from the
 * ML service, hospital and district from our own database.
 *
 * The result is a suggestion. Nothing here saves a request; a person confirms first.
 */
class RequestTextAnalyzer
{
    private const BLOOD_GROUPS = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];
    private const COMPONENTS = ['whole_blood', 'packed_rbc', 'platelets', 'plasma'];
    private const URGENCIES = ['urgent', 'emergency'];

    public function __construct(
        private readonly RequestTextParser $parser,
        private readonly PlaceResolver $places,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ParserUnavailable
     */
    public function analyze(string $text, bool $useLlm = false): array
    {
        $parsed = $this->parser->parse($text, $useLlm);
        $place = $this->places->resolve($text);

        // Never trust a remote service blindly: keep only values the form accepts.
        $bloodGroup = in_array($parsed['blood_group'] ?? null, self::BLOOD_GROUPS, true) ? $parsed['blood_group'] : null;
        $component = in_array($parsed['component_type'] ?? null, self::COMPONENTS, true) ? $parsed['component_type'] : null;
        $urgency = in_array($parsed['urgency'] ?? null, self::URGENCIES, true) ? $parsed['urgency'] : null;
        $units = is_int($parsed['units'] ?? null) && $parsed['units'] >= 1 && $parsed['units'] <= 20 ? $parsed['units'] : null;
        $phone = is_string($parsed['contact_number'] ?? null) && preg_match('/^01[3-9]\d{8}$/', $parsed['contact_number'])
            ? $parsed['contact_number'] : null;

        $hospital = $place['hospital'];
        $district = $place['district'];

        $result = [
            'blood_group' => $bloodGroup,
            'component_type' => $component,
            'units' => $units,
            'urgency' => $urgency,
            'contact_number' => $phone,
            'hospital' => $hospital ? [
                'id' => $hospital->id,
                'name' => $hospital->name,
                'display' => $hospital->name_bn ?? $hospital->name,
                'is_verified' => (bool) $hospital->is_verified,
            ] : null,
            'district_id' => $district?->id,
            'division_id' => $district?->division_id,
            'district_name' => $district?->name,
            'location_text' => is_string($parsed['location_text'] ?? null) ? $parsed['location_text'] : null,
            'source' => array_merge((array) ($parsed['source'] ?? []), $hospital ? ['hospital' => 'database'] : [], $district ? ['district' => 'database'] : []),
            'evidence' => array_merge((array) ($parsed['evidence'] ?? []), $place['evidence']),
            'warnings' => array_values(array_merge((array) ($parsed['warnings'] ?? []), $place['warnings'])),
            'llm_used' => (bool) ($parsed['llm_used'] ?? false),
        ];

        $result['missing'] = array_keys(array_filter([
            'blood_group' => $bloodGroup === null,
            'units' => $units === null,
            'urgency' => $urgency === null,
            'contact_number' => $phone === null,
            'hospital' => $hospital === null,
            'district' => $district === null,
        ]));

        $result['needs_review'] = $bloodGroup === null
            || ($result['source']['blood_group'] ?? null) === 'llm'
            || $result['warnings'] !== [];

        return $result;
    }
}
