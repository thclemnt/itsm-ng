<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database;

use Doctrine\ORM\EntityRepository;
use itsmng\Database\Repository\DropdownChoiceRepository;
use ReflectionClass;

/** Own only the built-in scalar choice query, never arbitrary repository overrides. */
final class DropdownReadOperation implements ReadQueryOwner
{
    use PrivateReadOwnership;

    public function choices(string $table, array $criteria, array $order, array $translations, string $kind, string $language, int $limit, int $offset): array
    {
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
            try {
                return $fallback->getRepository($metadata->name)->choices($criteria, $order, $translations, $kind, $language, $limit, $offset);
            } finally {
                $fallback->clear();
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
