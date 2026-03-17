<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Assignment grading scheme resolver for assignfeedback_aifeedback.
 *
 * @package   assignfeedback_aifeedback
 * @copyright 2026 Daniel McCluskey
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignfeedback_aifeedback\local;

/**
 * Resolves the assignment grading scheme into an AI-friendly structure.
 */
class grading_scheme_resolver {
    /** @var string Numeric grading type identifier. */
    private const TYPE_NUMERIC = 'numeric';
    /** @var string Scale grading type identifier. */
    private const TYPE_SCALE = 'scale';
    /** @var string No grade grading type identifier. */
    private const TYPE_NOGRADE = 'no_grade';
    /** @var string Unknown grading type identifier. */
    private const TYPE_UNKNOWN = 'unknown';

    /**
     * Resolve the current assignment grading scheme.
     *
     * @param \assign $assignment Assignment instance.
     * @return array
     */
    public static function resolve_from_assignment(\assign $assignment): array {
        try {
            $gradeitem = $assignment->get_grade_item();
        } catch (\Throwable $exception) {
            return [
                'type' => self::TYPE_UNKNOWN,
                'isgradable' => false,
                'description' => 'Grading scheme could not be resolved from the assignment settings.',
                'aiprompt' => 'No grade scheme is available. Set suggested_grade to ' .
                    '"No advisory grade available (grading scheme unavailable)".',
                'validoptions' => [],
                'min' => null,
                'max' => null,
                'gradeitemtype' => null,
            ];
        }

        $gradetype = (int) $gradeitem->gradetype;

        if ($gradetype === \GRADE_TYPE_VALUE) {
            $min = (float) $gradeitem->grademin;
            $max = (float) $gradeitem->grademax;
            if ($max < $min) {
                [$min, $max] = [$max, $min];
            }

            $minlabel = self::format_number($min);
            $maxlabel = self::format_number($max);

            return [
                'type' => self::TYPE_NUMERIC,
                'isgradable' => true,
                'description' => 'Numeric grade from ' . $minlabel . ' to ' . $maxlabel . '.',
                'aiprompt' => 'Valid numeric range is ' . $minlabel . ' to ' . $maxlabel .
                    '. Return suggested_grade as a bounded numeric interval in the form "X - X + 3", fully inside this range. ' .
                    'Use a narrow interval width of 3 to 5 marks where possible. ' .
                    'If the grading range is too small, use the narrowest valid interval. ' .
                    'Do not return a single exact mark.',
                'validoptions' => [],
                'min' => $min,
                'max' => $max,
                'gradeitemtype' => $gradetype,
            ];
        }

        if ($gradetype === \GRADE_TYPE_SCALE) {
            $scale = $gradeitem->load_scale();
            $options = [];
            if ($scale && is_array($scale->scale_items)) {
                foreach ($scale->scale_items as $item) {
                    $itemtext = trim((string) $item);
                    if ($itemtext !== '') {
                        $options[] = $itemtext;
                    }
                }
            }

            if (empty($options)) {
                return [
                    'type' => self::TYPE_UNKNOWN,
                    'isgradable' => false,
                    'description' => 'Scale grading is configured but no valid scale items were found.',
                    'aiprompt' => 'Scale options are unavailable. Set suggested_grade to ' .
                        '"No advisory grade available (scale options unavailable)".',
                    'validoptions' => [],
                    'min' => null,
                    'max' => null,
                    'gradeitemtype' => $gradetype,
                ];
            }

            $optionslist = implode(', ', $options);

            return [
                'type' => self::TYPE_SCALE,
                'isgradable' => true,
                'description' => 'Scale with valid options (lowest to highest): ' . $optionslist . '.',
                'aiprompt' => 'Valid scale options are: ' . $optionslist .
                    '. Suggest one option exactly as written, with no invented variants.',
                'validoptions' => $options,
                'min' => 1,
                'max' => count($options),
                'gradeitemtype' => $gradetype,
            ];
        }

        if ($gradetype === \GRADE_TYPE_NONE || $gradetype === \GRADE_TYPE_TEXT) {
            return [
                'type' => self::TYPE_NOGRADE,
                'isgradable' => false,
                'description' => 'No numeric or scale grading scheme is configured for this assignment.',
                'aiprompt' => 'No grade scheme is configured. Set suggested_grade to ' .
                    '"No advisory grade available (no grade scheme configured)".',
                'validoptions' => [],
                'min' => null,
                'max' => null,
                'gradeitemtype' => $gradetype,
            ];
        }

        return [
            'type' => self::TYPE_UNKNOWN,
            'isgradable' => false,
            'description' => 'Grading scheme type is unsupported for AI suggestion.',
            'aiprompt' => 'Grading scheme is unsupported. Set suggested_grade to ' .
                '"No advisory grade available (unsupported grading scheme)".',
            'validoptions' => [],
            'min' => null,
            'max' => null,
            'gradeitemtype' => $gradetype,
        ];
    }

    /**
     * Format numeric values with trimmed trailing zeroes.
     *
     * @param float $value Numeric value.
     * @return string
     */
    private static function format_number(float $value): string {
        if ((float) ((int) $value) === $value) {
            return (string) ((int) $value);
        }

        $formatted = sprintf('%.10F', $value);
        return rtrim(rtrim($formatted, '0'), '.');
    }
}
