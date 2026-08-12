<?php

declare(strict_types=1);

namespace App\Radio\SmartBlock;

use App\Entity\CustomField;
use App\Entity\Enums\SmartBlockCriteriaComparison;
use App\Entity\Enums\SmartBlockCriteriaField;
use App\Entity\StationMediaCategory;
use App\Entity\StationPlaylist;
use App\Entity\StationPlaylistSmartBlockCriteria;
use App\Exception\ValidationException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SmartBlockCriteriaPayloadParser
{
    public const int MAX_CRITERIA = 100;

    public function __construct(
        private EntityManagerInterface $em
    ) {
    }

    /**
     * @return list<StationPlaylistSmartBlockCriteria>
     */
    public function parse(StationPlaylist $playlist, mixed $payload): array
    {
        if (!is_array($payload) || !array_is_list($payload)) {
            throw new ValidationException('criteria must be a list.');
        }
        if (count($payload) > self::MAX_CRITERIA) {
            throw new ValidationException('A Smart Block cannot contain more than 100 criteria.');
        }

        $rows = [];
        $seen = [];

        foreach ($payload as $index => $definition) {
            if (!is_array($definition)) {
                throw new ValidationException('Every Smart Block criterion must be an object.');
            }

            $field = $this->parseField($definition['field'] ?? null);
            $comparison = $this->parseComparison($definition['comparison'] ?? null);
            $this->validateComparison($field, $comparison);

            $value = $this->parseRequiredValue($definition['value'] ?? null);
            $value2 = $comparison->needsSecondValue()
                ? $this->parseRequiredValue($definition['value2'] ?? null, 'value2')
                : null;

            $row = new StationPlaylistSmartBlockCriteria($playlist);
            $row->field = $field;
            $row->comparison = $comparison;
            $row->weight = $index;

            if (SmartBlockCriteriaField::Category === $field) {
                $row->value = (string)$this->resolveCategoryId($playlist, $value);
            } elseif ($field->isNumeric()) {
                $row->value = $this->normalizeNumber($value);
                $row->value2 = null !== $value2 ? $this->normalizeNumber($value2, 'value2') : null;
            } else {
                $row->value = $value;
                $row->value2 = $value2;
            }

            if (SmartBlockCriteriaField::CustomField === $field) {
                $row->custom_field = $this->resolveCustomField($definition['custom_field_id'] ?? null);
            }

            $fingerprint = implode('|', [
                $row->field->value,
                $row->comparison->value,
                $row->value ?? '',
                $row->value2 ?? '',
                $row->custom_field?->id ?? '',
            ]);
            if (isset($seen[$fingerprint])) {
                continue;
            }

            $seen[$fingerprint] = true;
            $row->weight = count($rows);
            $rows[] = $row;
        }

        return $rows;
    }

    private function parseField(mixed $value): SmartBlockCriteriaField
    {
        if (!is_string($value) || null === ($field = SmartBlockCriteriaField::tryFrom($value))) {
            throw new ValidationException('field is invalid.');
        }

        return $field;
    }

    private function parseComparison(mixed $value): SmartBlockCriteriaComparison
    {
        if (!is_string($value) || null === ($comparison = SmartBlockCriteriaComparison::tryFrom($value))) {
            throw new ValidationException('comparison is invalid.');
        }

        return $comparison;
    }

    private function validateComparison(
        SmartBlockCriteriaField $field,
        SmartBlockCriteriaComparison $comparison
    ): void {
        $allowed = match ($field) {
            SmartBlockCriteriaField::Category => [
                SmartBlockCriteriaComparison::Is,
                SmartBlockCriteriaComparison::IsNot,
            ],
            SmartBlockCriteriaField::Duration,
            SmartBlockCriteriaField::LastPlayed => SmartBlockCriteriaComparison::numericComparisons(),
            SmartBlockCriteriaField::CustomField => SmartBlockCriteriaComparison::cases(),
            default => SmartBlockCriteriaComparison::textComparisons(),
        };

        if (!in_array($comparison, $allowed, true)) {
            throw new ValidationException('comparison is not supported for this field.');
        }
    }

    private function parseRequiredValue(mixed $value, string $name = 'value'): string
    {
        if ((!is_string($value) && !is_int($value) && !is_float($value)) || '' === trim((string)$value)) {
            throw new ValidationException($name . ' is required.');
        }

        return trim((string)$value);
    }

    private function normalizeNumber(string $value, string $name = 'value'): string
    {
        if (!is_numeric($value)) {
            throw new ValidationException($name . ' must be numeric.');
        }

        return $value;
    }

    private function resolveCategoryId(StationPlaylist $playlist, string $value): int
    {
        if (!ctype_digit($value) || (int)$value <= 0) {
            throw new ValidationException('category value must be a positive category ID.');
        }

        $category = $this->em->find(StationMediaCategory::class, (int)$value);
        if (!$category instanceof StationMediaCategory || $category->station !== $playlist->station) {
            throw new ValidationException('category is invalid for this station.');
        }

        return $category->id;
    }

    private function resolveCustomField(mixed $value): CustomField
    {
        if (!is_int($value) || $value <= 0) {
            throw new ValidationException('custom_field_id must be a positive integer.');
        }

        $customField = $this->em->find(CustomField::class, $value);
        if (!$customField instanceof CustomField) {
            throw new ValidationException('custom_field_id is invalid.');
        }

        return $customField;
    }
}
