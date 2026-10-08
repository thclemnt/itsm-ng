<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\Entity\Config;
use itsmng\Database\Orm;
use LogicException;

/** Configuration names and contexts are literal values, including the string NULL. */
final class ConfigurationRepository
{
    private bool $nativeRead = false;

    public function __construct(private EntityManager $em)
    {
    }

    /** @internal Optional manager belongs to the enclosing value-only application scope. */
    public static function forConnection(Connection $connection, ?EntityManager $manager = null): self
    {
        if ($manager !== null && $manager->getConnection() !== $connection) {
            throw new LogicException('A configuration read must use its selected physical connection.');
        }
        $repository = new self($manager ?? Orm::forConnection($connection));
        $repository->nativeRead = Orm::ownsReadMapping($connection);
        return $repository;
    }

    public function values(string $context, array $names = []): array
    {
        if ($this->nativeRead) {
            return $this->nativeValues($context, $names);
        }
        $query = $this->em->createQueryBuilder()->select('r.name', 'r.value')->from(Config::class, 'r')
            ->where('r.context = :context')->setParameter('context', $context, Types::STRING)->orderBy('r.id');
        if ($names) {
            $query->andWhere('r.name IN (:names)')->setParameter('names', array_values($names), ArrayParameterType::STRING);
        }
        $values = [];
        foreach ($query->getQuery()->getScalarResult() as $row) {
            $values[$row['name']] = $row['value'];
        }
        return $values;
    }

    private function nativeValues(string $context, array $names): array
    {
        $connection = $this->em->getConnection();
        $metadata = $this->em->getClassMetadata(Config::class);
        $platform = $connection->getDatabasePlatform();
        $quote = $this->em->getConfiguration()->getQuoteStrategy();
        $name = 'r.' . $quote->getColumnName('name', $metadata, $platform);
        $value = 'r.' . $quote->getColumnName('value', $metadata, $platform);
        $query = $connection->createQueryBuilder();
        $nameSelection = Type::getType($metadata->getTypeOfField('name'))
            ->convertToPHPValueSQL($name, $platform) . ' AS ' . $platform->quoteSingleIdentifier('name');
        $valueSelection = Type::getType($metadata->getTypeOfField('value'))
            ->convertToPHPValueSQL($value, $platform) . ' AS ' . $platform->quoteSingleIdentifier('value');
        $query->select($nameSelection, $valueSelection)
            ->from($quote->getTableName($metadata, $platform), 'r');
        $contextColumn = 'r.' . $quote->getColumnName('context', $metadata, $platform);
        $contextPredicate = $contextColumn . ' = '
            . Type::getType(Types::STRING)->convertToDatabaseValueSQL('?', $platform);
        $query->where($contextPredicate)
            ->setParameter(0, $context, Types::STRING)
            ->orderBy('r.' . $quote->getColumnName('id', $metadata, $platform));
        if ($names) {
            // Array binding retains raw literal names, independently of mapped STRING SQL conversion.
            $query->andWhere($name . ' IN (?)')->setParameter(1, array_values($names), ArrayParameterType::STRING);
        }
        $values = [];
        // ScalarHydrator uses SQL conversion only; PHP Type converters do not run.
        foreach ($query->executeQuery()->iterateAssociative() as $row) {
            $values[$row['name']] = $row['value'];
        }
        return $values;
    }
}
