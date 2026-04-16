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

        return [
            'source_name' => (string) $source['name'],
            'source_role' => (string) $source['role'],
            'title' => $this->extractTitle($properties),
            'date' => $this->extractDate($properties, (string) $source['date_property']),
            'status' => $this->extractStatus($properties, $source['status_property'] ?? null),
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
     */
    private function extractDate(array $properties, string $propertyName): ?string
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
            return null;
        }

        if (!is_array($date) || empty($date['start'])) {
            return null;
        }

        return $this->normalizeDate((string) $date['start']);
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
