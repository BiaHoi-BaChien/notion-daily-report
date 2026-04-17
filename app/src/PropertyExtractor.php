<?php

declare(strict_types=1);

namespace App;

use App\Exception\PropertyExtractionException;
use DateTimeImmutable;
use DateTimeZone;

final class PropertyExtractor
{
    public function __construct(private readonly DateTimeZone $timezone)
    {
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public function extract(array $page, array $source): array
    {
        $properties = $page['properties'] ?? null;
        if (!is_array($properties)) {
            throw new PropertyExtractionException('Page properties are missing or invalid.');
        }

        $dateInfo = $this->extractDateInfo($properties, (string) $source['date_property']);

        return [
            'source_name' => (string) $source['name'],
            'source_role' => (string) $source['role'],
            'title' => $this->extractTitle($properties),
            'date' => $dateInfo['date'],
            'date_start' => $dateInfo['date_start'],
            'date_end' => $dateInfo['date_end'],
            'date_has_time' => $dateInfo['date_has_time'],
            'date_end_has_time' => $dateInfo['date_end_has_time'],
            'status' => $this->extractStatus($properties, $source['status_property'] ?? null),
            'genre' => $this->extractGenre($properties, $source['genre_property'] ?? null),
            'project' => $this->extractProject($properties, $source['project_property'] ?? null),
            'project_relation_ids' => $this->extractRelationIds($properties, $source['project_property'] ?? null),
            'classification' => null,
            'url' => $page['url'] ?? null,
            'last_edited_time' => $page['last_edited_time'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function extractTitle(array $properties): string
    {
        foreach ($properties as $property) {
            if (($property['type'] ?? null) === 'title') {
                $chunks = $property['title'] ?? [];
                if (!is_array($chunks)) {
                    return '無題';
                }

                $title = '';
                foreach ($chunks as $chunk) {
                    $title .= (string) ($chunk['plain_text'] ?? '');
                }

                return trim($title) === '' ? '無題' : trim($title);
            }
        }

        return '無題';
    }

    /**
     * @param array<string, mixed> $properties
     * @return array{date: ?string, date_start: ?string, date_end: ?string, date_has_time: bool, date_end_has_time: bool}
     */
    private function extractDateInfo(array $properties, string $propertyName): array
    {
        $property = $this->requiredProperty($properties, $propertyName);
        if (($property['type'] ?? null) !== 'date') {
            throw new PropertyExtractionException(sprintf(
                'Property "%s" must be a Notion date property.',
                $propertyName
            ));
        }

        $date = $property['date'] ?? null;
        if ($date === null) {
            return [
                'date' => null,
                'date_start' => null,
                'date_end' => null,
                'date_has_time' => false,
                'date_end_has_time' => false,
            ];
        }

        if (!is_array($date) || empty($date['start'])) {
            return [
                'date' => null,
                'date_start' => null,
                'date_end' => null,
                'date_has_time' => false,
                'date_end_has_time' => false,
            ];
        }

        $start = (string) $date['start'];
        $end = isset($date['end']) && is_string($date['end']) && trim($date['end']) !== ''
            ? $date['end']
            : null;

        return [
            'date' => $this->normalizeDate($start),
            'date_start' => $this->normalizeDateTime($start),
            'date_end' => $end === null ? null : $this->normalizeDateTime($end),
            'date_has_time' => $this->hasTime($start),
            'date_end_has_time' => $end !== null && $this->hasTime($end),
        ];
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function extractStatus(array $properties, mixed $propertyName): ?string
    {
        if ($propertyName === null || $propertyName === '') {
            return null;
        }

        $property = $this->requiredProperty($properties, (string) $propertyName);
        $type = $property['type'] ?? null;

        if ($type === 'status') {
            return $property['status']['name'] ?? null;
        }

        if ($type === 'select') {
            return $property['select']['name'] ?? null;
        }

        throw new PropertyExtractionException(sprintf(
            'Property "%s" must be a Notion status or select property.',
            (string) $propertyName
        ));
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function extractGenre(array $properties, mixed $propertyName): ?string
    {
        return $this->extractOptionalTextProperty($properties, $propertyName);
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function extractProject(array $properties, mixed $propertyName): ?string
    {
        return $this->extractOptionalTextProperty($properties, $propertyName);
    }

    /**
     * @param array<string, mixed> $properties
     * @return array<int, string>
     */
    private function extractRelationIds(array $properties, mixed $propertyName): array
    {
        if ($propertyName === null || $propertyName === '') {
            return [];
        }

        $propertyName = (string) $propertyName;
        if (!array_key_exists($propertyName, $properties) || !is_array($properties[$propertyName])) {
            return [];
        }

        $property = $properties[$propertyName];
        if (($property['type'] ?? null) !== 'relation' || !is_array($property['relation'] ?? null)) {
            return [];
        }

        $ids = [];
        foreach ($property['relation'] as $relation) {
            if (!is_array($relation)) {
                continue;
            }

            $id = $this->nonEmptyString($relation['id'] ?? null);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function extractOptionalTextProperty(array $properties, mixed $propertyName): ?string
    {
        if ($propertyName === null || $propertyName === '') {
            return null;
        }

        $propertyName = (string) $propertyName;
        if (!array_key_exists($propertyName, $properties) || !is_array($properties[$propertyName])) {
            return null;
        }

        return $this->extractTextFromProperty($properties[$propertyName]);
    }

    /**
     * @param array<string, mixed> $property
     */
    private function extractTextFromProperty(array $property): ?string
    {
        $type = $property['type'] ?? null;

        if ($type === 'status') {
            return $this->nonEmptyString($property['status']['name'] ?? null);
        }

        if ($type === 'select') {
            return $this->nonEmptyString($property['select']['name'] ?? null);
        }

        if ($type === 'multi_select') {
            return $this->joinNames($property['multi_select'] ?? null);
        }

        if ($type === 'rich_text' || $type === 'title') {
            return $this->plainTextFromChunks($property[$type] ?? null);
        }

        if ($type === 'formula') {
            return $this->extractFormulaText($property['formula'] ?? null);
        }

        if ($type === 'rollup') {
            return $this->extractRollupText($property['rollup'] ?? null);
        }

        if ($type === 'relation') {
            return $this->joinNames($property['relation'] ?? null);
        }

        return null;
    }

    private function joinNames(mixed $values): ?string
    {
        if (!is_array($values)) {
            return null;
        }

        $names = [];
        foreach ($values as $value) {
            if (!is_array($value)) {
                continue;
            }

            $name = $this->nonEmptyString($value['name'] ?? null)
                ?? $this->nonEmptyString($value['plain_text'] ?? null);
            if ($name !== null) {
                $names[] = $name;
            }
        }

        return $names === [] ? null : implode('、', $names);
    }

    private function plainTextFromChunks(mixed $chunks): ?string
    {
        if (!is_array($chunks)) {
            return null;
        }

        $text = '';
        foreach ($chunks as $chunk) {
            if (!is_array($chunk)) {
                continue;
            }

            $text .= (string) ($chunk['plain_text'] ?? '');
        }

        return $this->nonEmptyString($text);
    }

    private function extractFormulaText(mixed $formula): ?string
    {
        if (!is_array($formula)) {
            return null;
        }

        $type = $formula['type'] ?? null;
        if (in_array($type, ['string', 'number', 'boolean'], true)) {
            return $this->nonEmptyString((string) ($formula[$type] ?? ''));
        }

        return null;
    }

    private function extractRollupText(mixed $rollup): ?string
    {
        if (!is_array($rollup)) {
            return null;
        }

        $type = $rollup['type'] ?? null;
        if ($type === 'array') {
            $values = $rollup['array'] ?? [];
            if (!is_array($values)) {
                return null;
            }

            $texts = [];
            foreach ($values as $value) {
                if (!is_array($value)) {
                    continue;
                }

                $text = $this->extractTextFromProperty($value);
                if ($text !== null) {
                    $texts[] = $text;
                }
            }

            return $texts === [] ? null : implode('、', $texts);
        }

        if (in_array($type, ['number', 'date'], true)) {
            return null;
        }

        return $this->nonEmptyString((string) ($rollup[$type] ?? ''));
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function normalizeDate(string $value): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            return $value;
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone($this->timezone)->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }

    private function normalizeDateTime(string $value): ?string
    {
        try {
            return (new DateTimeImmutable($value))->setTimezone($this->timezone)->format(DATE_ATOM);
        } catch (\Exception) {
            return null;
        }
    }

    private function hasTime(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1;
    }

    /**
     * @param array<string, mixed> $properties
     * @return array<string, mixed>
     */
    private function requiredProperty(array $properties, string $propertyName): array
    {
        if (!array_key_exists($propertyName, $properties)) {
            throw new PropertyExtractionException(sprintf('Property "%s" was not found.', $propertyName));
        }

        if (!is_array($properties[$propertyName])) {
            throw new PropertyExtractionException(sprintf('Property "%s" is invalid.', $propertyName));
        }

        return $properties[$propertyName];
    }
}
