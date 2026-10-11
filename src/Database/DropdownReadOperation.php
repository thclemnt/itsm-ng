<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\ORM\EntityRepository;
use itsmng\Database\Repository\DropdownChoiceRepository;
use itsmng\Database\Repository\DropdownTranslationRepository;
use ReflectionClass;
use Throwable;

/** Own only the built-in scalar choice query, never arbitrary repository overrides. */
final class DropdownReadOperation implements ReadQueryOwner
{
    use PrivateReadOwnership;

    /** Only untranslated, explicit scalar labels bypass ORM query compilation. */
    public function label(string $table, int $id, string $type, string $language, array $translations, ?array $columns = null): ?array
    {
        $this->beginRead();
        if ($translations === [] && $columns !== null) {
            $metadata = $this->metadata($table);
            $scalarColumns = $this->ownedMapping && $this->defaultIdentifiers($metadata) !== null
                && $metadata->isInheritanceTypeNone()
                && empty($metadata->fieldMappings['id']->enumType);
            foreach ($columns as $column) {
                $field = $metadata->getFieldName($column);
                $scalarColumns = $scalarColumns && $metadata->hasField($field)
                    && empty($metadata->fieldMappings[$field]->enumType);
            }
            if ($scalarColumns) {
                return (new DropdownTranslationRepository($this->manager))->nativeLabel($table, $id, $columns);
            }
        }
        $fallback = $this->fallbackManager();
        $primary = null;
        try {
            return (new DropdownTranslationRepository($fallback))->dropdownRow($table, $id, $type, $language, $translations, $columns);
        } catch (Throwable $error) {
            $primary = $error;
            throw $error;
        } finally {
            $this->clearFallback($fallback, $primary);
        }
    }

    public function choices(string $table, array $criteria, array $order, array $translations, string $kind, string $language, int $limit, int $offset): array
    {
        $this->beginRead();
        $metadata = $this->metadata($table);
        $repository = $metadata->customRepositoryClassName ?? DropdownChoiceRepository::class;
        $reflection = new ReflectionClass($repository);
        $origin = $reflection->getFileName();
        $directory = realpath(__DIR__ . '/Repository');
        $trusted = $origin !== false && $directory !== false
            && ($origin = realpath($origin)) !== false
            && str_starts_with($origin, $directory . '/')
            && ($repository === DropdownChoiceRepository::class || $reflection->isFinal());
        // Establish all invoked virtual implementations before constructing a
        // repository with the private manager. Domain-specific overrides stay local.
        foreach (['__construct', 'getEntityManager', 'getClassMetadata', 'createQueryBuilder', 'choices', 'ownedChoices', 'choiceQuery', 'choiceCriteria', 'presentChoice'] as $method) {
            $expected = in_array($method, ['__construct', 'getEntityManager', 'getClassMetadata', 'createQueryBuilder'], true) ? EntityRepository::class : DropdownChoiceRepository::class;
            $trusted = $trusted && $reflection->getMethod($method)->getDeclaringClass()->getName() === $expected;
        }
        $trusted = $trusted && $this->scalar($metadata)
            && !array_filter($translations, static fn (array $translation): bool => preg_match('/^value[0-9]+$/i', $translation['output']) === 1);
        if (!$trusted) {
            $fallback = $this->fallbackManager();
            $primary = null;
            try {
                return $fallback->getRepository($metadata->name)->choices($criteria, $order, $translations, $kind, $language, $limit, $offset);
            } catch (Throwable $error) {
                $primary = $error;
                throw $error;
            } finally {
                $this->clearFallback($fallback, $primary);
            }
        }
        return $this->manager->getRepository($metadata->name)->ownedChoices(
            $criteria,
            $order,
            $translations,
            $kind,
            $language,
            $limit,
            $offset,
            $this->defaultIdentifiers($metadata),
            $this,
        );
    }
}
