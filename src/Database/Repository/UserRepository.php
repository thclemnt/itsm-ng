<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use itsmng\Database\CookieCredential;
use itsmng\Database\Entity\ProfileUser;
use itsmng\Database\Entity\User;
use itsmng\Database\Entity\UserEmail;
use itsmng\Database\Entity\GroupMembership;
use itsmng\Database\RecordCriteria;

final class UserRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /**
     * Complete API records for visible account identities. EXISTS avoids grant
     * fan-out in both pages and totals; null scope also admits ungranted accounts.
     */
    public function apiPage(array $params, ?array $scope, ?array $parent = null): array
    {
        $metadata = $this->em->getClassMetadata(User::class);
        $query = $this->em->createQueryBuilder()->from(User::class, 'r');
        $compiler = new RecordCriteria($query, $metadata, legacyValues: false);
        $deleted = \itsmng\Database\BooleanValue::normalize($params['is_deleted'] ?? false, false, 'is_deleted');
        $query->where($compiler->where(['is_deleted' => $deleted]));
        if ($scope !== null) {
            $entities = array_values(array_unique(array_map('intval', $scope['entities'])));
            $ancestors = array_values(array_unique(array_map('intval', $scope['ancestors'])));
            $visible = [];
            if ($entities !== []) {
                $visible[] = 'IDENTITY(grant.entities) IN (:visibleEntities)';
                $query->setParameter('visibleEntities', $entities, \Doctrine\DBAL\ArrayParameterType::INTEGER);
            }
            if ($entities !== [] && $ancestors !== []) {
                $visible[] = '(grant.is_recursive = :recursive AND IDENTITY(grant.entities) IN (:ancestorEntities))';
                $query->setParameter('recursive', true, Types::BOOLEAN)
                    ->setParameter('ancestorEntities', $ancestors, \Doctrine\DBAL\ArrayParameterType::INTEGER);
            }
            $query->andWhere($visible === [] ? '1 = 0' : 'EXISTS (SELECT grant.id FROM '
                . ProfileUser::class . ' grant WHERE grant.users = r AND (' . implode(' OR ', $visible) . '))');
        }
        if ($parent !== null) {
            $this->apiParent($query, $compiler, $parent);
        }
        $filters = $params['searchText'] ?? [];
        if (is_array($filters)) {
            if (array_keys($filters) === ['all']) {
                // The existing API combines name and comment with AND.
                $filters = ['name' => $filters['all'], 'comment' => $filters['all']];
            }
            foreach ($filters as $field => $value) {
                // Preserve the API's empty-value treatment, including "0".
                if (empty($value)) {
                    continue;
                }
                if (!is_scalar($value)) {
                    throw new \InvalidArgumentException('Collection text filters must be scalar.');
                }
                $pattern = \Search::makeTextSearchValue(str_replace('\\', '\\\\', (string)$value));
                $property = $metadata->fieldNames[$field] ?? null;
                if ($property !== null && $metadata->getTypeOfField($property) === Types::BOOLEAN) {
                    // searchText describes the public zero/one presentation, including
                    // anchors/wildcards/NULL; it is not a boolean assignment.
                    $column = $compiler->column($field);
                    if ($pattern === null || $pattern === '') {
                        $query->andWhere($column . ' IS NULL');
                    } else {
                        $parameter = 'booleanText' . count($query->getParameters());
                        $text = 'CASE WHEN ' . $column . ' IS NULL THEN NULL WHEN '
                            . $column . " = true THEN '1' ELSE '0' END";
                        $query->andWhere('LOWER(' . $text . ') LIKE :' . $parameter)
                            ->setParameter($parameter, $pattern, Types::STRING);
                    }
                    continue;
                }
                if ($property !== null && $metadata->getTypeOfField($property) === Types::JSON) {
                    // The API accepts text patterns over JSON's public serialized
                    // representation, not JSON equality or containment predicates.
                    $column = $compiler->column($field);
                    if ($pattern === null || $pattern === '') {
                        $query->andWhere($column . ' IS NULL');
                    } else {
                        $parameter = 'jsonText' . count($query->getParameters());
                        $text = "CONCAT('', " . $column . ')';
                        if ($this->em->getConnection()->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform) {
                            $predicate = 'LOWER(' . $text . ') LIKE LOWER(:' . $parameter . ')';
                        } else {
                            // Preserve the JSON column's MySQL/MariaDB collation.
                            $predicate = $text . ' LIKE :' . $parameter;
                        }
                        $query->andWhere($predicate)->setParameter($parameter, $pattern, Types::STRING);
                    }
                    continue;
                }
                $query->andWhere($compiler->where([$field => $pattern === null || $pattern === '' ? null : ['LIKE', $pattern]]));
            }
        }
        $total = (int)(clone $query)->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();
        $query->select('r');
        $sort = $params['sort'] ?? 'id';
        $compiler->order([$sort . ' ' . strtoupper($params['order'] ?? 'ASC')]);
        if ($sort !== 'id') {
            $query->addOrderBy('r.id');
        }
        $query->setFirstResult(max(0, (int)($params['start'] ?? 0)))
            ->setMaxResults(max(1, (int)($params['list_limit'] ?? 50)));
        $rows = [];
        $records = new RecordRepository($this->em);
        foreach ($query->getQuery()->toIterable() as $record) {
            $rows[] = $records->toRow($record);
            $this->em->detach($record);
        }
        return ['rows' => $rows, 'total' => $total];
    }

    /** Preserve the API's exact direct-FK, then reverse-FK/polymorphic precedence. */
    private function apiParent(\Doctrine\ORM\QueryBuilder $query, RecordCriteria $compiler, array $parent): void
    {
        $metadata = $this->em->getClassMetadata(User::class);
        foreach ($metadata->associationMappings as $mapping) {
            if ($mapping->isToOneOwningSide() && $mapping->joinColumns[0]->name === $parent['foreignKey']) {
                $query->andWhere($compiler->where([$parent['foreignKey'] => $parent['id']]));
                return;
            }
        }
        if ($metadata->hasField($parent['foreignKey'])) {
            $query->andWhere($compiler->where([$parent['foreignKey'] => $parent['id']]));
            return;
        }
        $class = \itsmng\Database\EntityRegistry::tables()[$parent['table']]
            ?? throw new \InvalidArgumentException('Parent collection requires a mapped record.');
        $parentMetadata = $this->em->getClassMetadata($class);
        foreach ($parentMetadata->associationMappings as $field => $mapping) {
            if ($mapping->isToOneOwningSide() && $mapping->joinColumns[0]->name === $parent['userForeignKey']) {
                $query->andWhere('EXISTS (SELECT parent.id FROM ' . $class
                    . ' parent WHERE parent.id = :parentId AND parent.' . $field . ' = r)')
                    ->setParameter('parentId', $parent['id'], Types::BIGINT);
                return;
            }
        }
        if ($parentMetadata->hasField('itemtype') && $parentMetadata->hasField('items_id')) {
            $query->andWhere('EXISTS (SELECT parent.id FROM ' . $class
                . ' parent WHERE parent.id = :parentId AND parent.itemtype = :parentKind AND parent.items_id = r.id)')
                ->setParameter('parentId', $parent['id'], Types::BIGINT)
                ->setParameter('parentKind', $parent['kind'], Types::STRING);
        }
        // Historically an unrelated admitted parent added no further User filter.
    }

    /** Display-only values, without hydrating unrelated account fields or associations. */
    public function displayData(int $user): ?array
    {
        $rows = $this->em->createQueryBuilder()
            ->select('u.id, u.name, u.realname, u.firstname, u.phone, u.mobile, u.picture')
            ->addSelect('IDENTITY(u.locations) AS locations_id, IDENTITY(u.usertitles) AS usertitles_id, IDENTITY(u.usercategories) AS usercategories_id')
            ->from(User::class, 'u')->where('u.id = :user')->setParameter('user', $user, Types::INTEGER)
            ->getQuery()->getScalarResult();
        return $rows[0] ?? null;
    }

    public function profiles(int $user): array
    {
        $rows = $this->em->createQueryBuilder()->select('DISTINCT p.id, p.name')->from(ProfileUser::class, 'a')
            ->join('a.profiles', 'p')->where('IDENTITY(a.users) = :user')->setParameter('user', $user, Types::INTEGER)
            ->orderBy('p.id')->getQuery()->getScalarResult();
        return array_column($rows, 'name', 'id');
    }

    /** Replacements are preferences only; this query never grants a profile. */
    public function defaultProfileReplacements(int $profile, int $replacement): array
    {
        return $this->em->createQueryBuilder()->select('DISTINCT u.id, IDENTITY(a.profiles) AS profiles_id')->from(User::class, 'u')
            ->leftJoin(ProfileUser::class, 'a', 'WITH', 'a.users = u AND IDENTITY(a.profiles) = :replacement')
            ->where('IDENTITY(u.profiles) = :profile')->setParameter('profile', $profile, Types::INTEGER)
            ->setParameter('replacement', $replacement === $profile ? 0 : $replacement, Types::INTEGER)
            ->orderBy('u.id')->getQuery()->getScalarResult();
    }

    public function emails(int $user): array
    {
        return $this->em->createQueryBuilder()->select('e.id, e.is_default, e.email, e.is_dynamic, IDENTITY(e.users) AS users_id')->from(UserEmail::class, 'e')
            ->where('IDENTITY(e.users) = :user')->setParameter('user', $user, Types::INTEGER)
            ->orderBy('e.id')->getQuery()->getScalarResult();
    }

    public function preferredByEmail(string $email): ?int
    {
        $row = $this->em->createQueryBuilder()->select('u.id')->from(UserEmail::class, 'e')->join('e.users', 'u')
            ->where('LOWER(e.email) = LOWER(:email)')->setParameter('email', $email)
            ->orderBy('u.is_active', 'DESC')->addOrderBy('u.is_deleted')->addOrderBy('u.id')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
        return $row === null ? null : (int)$row['id'];
    }

    /** Fetch at most two IDs so ambiguity never resolves to an arbitrary account. */
    public function uniqueId(string $field, mixed $value, bool $legacyValues = false): ?int
    {
        $query = $this->em->createQueryBuilder()->select('r.id')->from(User::class, 'r');
        $criteria = new RecordCriteria($query, $this->em->getClassMetadata(User::class), $legacyValues);
        $query->where($criteria->where([$field => $value]))->setMaxResults(2);
        $rows = $query->getQuery()->getScalarResult();
        return count($rows) === 1 ? (int)$rows[0]['id'] : null;
    }

    public function exists(array $criteria, bool $legacyValues = false): bool
    {
        $query = $this->em->createQueryBuilder()->select('r.id')->from(User::class, 'r');
        $query->where((new RecordCriteria($query, $this->em->getClassMetadata(User::class), $legacyValues))->where($criteria));
        return $query->setMaxResults(1)->getQuery()->getOneOrNullResult() !== null;
    }

    /** Read credential bytes without entity/model caches or collation equality. */
    public function tokenValue(int $user, string $column): ?string
    {
        $query = $this->em->createQueryBuilder()->from(User::class, 'r');
        $compiler = new RecordCriteria($query, $this->em->getClassMetadata(User::class), false);
        $rows = $query->select($compiler->column($column) . ' AS token')
            ->where('r.id = :user')->setParameter('user', $user, Types::INTEGER)
            ->setMaxResults(1)->getQuery()->getScalarResult();
        return $rows[0]['token'] ?? null;
    }

    /** Read both owning properties together, without a managed User snapshot. */
    public function cookieCredential(int $user): ?CookieCredential
    {
        $rows = $this->em->createQueryBuilder()->from(User::class, 'u')
            ->select('u.cookie_token AS hash, u.cookie_token_date AS issuedAt')
            ->where('u.id = :user')->setParameter('user', $user, Types::INTEGER)
            ->setMaxResults(1)->getQuery()->getArrayResult();
        return $rows === [] ? null : new CookieCredential($rows[0]['hash'], $rows[0]['issuedAt']);
    }

    /** Directory imports accept either a login or any of the account's email addresses. */
    public function authenticationMatch(array $criteria): ?array
    {
        $query = $this->em->createQueryBuilder()->select('DISTINCT r.id, r.password, r.user_dn')->from(User::class, 'r')
            ->leftJoin(UserEmail::class, 'email', 'WITH', 'email.users = r');
        $compiler = (new RecordCriteria($query, $this->em->getClassMetadata(User::class)))
            ->withJoinedMetadata($this->em->getClassMetadata(UserEmail::class), 'email');
        $qualifyEmails = static function (array $conditions) use (&$qualifyEmails): array {
            $qualified = [];
            foreach ($conditions as $column => $value) {
                if ((is_int($column) || in_array($column, ['AND', 'OR', 'NOT'], true)) && is_array($value)) {
                    $value = $qualifyEmails($value);
                }
                $qualified[$column === 'email' ? 'glpi_useremails.email' : $column] = $value;
            }
            return $qualified;
        };
        return $query->where($compiler->where($qualifyEmails($criteria)))->orderBy('r.id')->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();
    }

    public function memberships(int $user): array
    {
        return $this->em->createQueryBuilder()->select('m.id, IDENTITY(m.groups) AS groups_id, m.is_dynamic')
            ->from(GroupMembership::class, 'm')->where('IDENTITY(m.users) = :user')->setParameter('user', $user, Types::INTEGER)
            ->orderBy('m.id')->getQuery()->getScalarResult();
    }

    /** Partial account deletion removes grants only in an authorized entity. */
    public function removeEntityGrants(int $user, int $entity): void
    {
        $this->detachEntityGrants($user, [$entity]);
    }

    /** Explicit scoped detachment keeps the global account and inaccessible grants. */
    public function detachEntityGrants(int $user, array $entities): void
    {
        if (!$entities) {
            return;
        }
        $this->em->createQueryBuilder()->delete(ProfileUser::class, 'p')
            ->where('IDENTITY(p.users) = :user AND IDENTITY(p.entities) IN (:entities)')
            ->setParameter('user', $user, Types::INTEGER)
            ->setParameter('entities', array_map('intval', $entities), \Doctrine\DBAL\ArrayParameterType::INTEGER)
            ->getQuery()->execute();
    }

    /** Authentication maintenance deliberately bypasses the external-directory update hooks. */
    public function clearPassword(string $login): void
    {
        $this->em->createQueryBuilder()->update(User::class, 'u')->set('u.password', ':empty')
            ->where('u.name = :login')->setParameter('empty', '')->setParameter('login', $login)->getQuery()->execute();
    }

    public function changeAuthentication(array $users, int $type, int $server): void
    {
        if (!$users) {
            return;
        }
        $values = (new User())->normalizeInput(['authtype' => $type, 'auths_id' => $server]);
        $query = $this->em->createQueryBuilder()->update(User::class, 'u')
            ->set('u.authtype', ':type')->set('u.password', ':empty')->set('u.is_deleted_ldap', ':no')
            ->where('u.id IN (:users)')->setParameter('users', array_values(array_map('intval', $users)))
            ->setParameter('type', $type, Types::INTEGER)->setParameter('empty', '')->setParameter('no', false, Types::BOOLEAN);
        $metadata = $this->em->getClassMetadata(User::class);
        foreach ($metadata->associationMappings as $field => $mapping) {
            $column = $mapping->joinColumns[0]->name;
            if (array_key_exists($column, $values)) {
                $query->set('u.' . $field, ':' . $field)->setParameter($field, $values[$column] === null ? null : $this->em->getReference($mapping->targetEntity, $values[$column]));
            }
        }
        $query->set('u.auth_source_code', ':code')->setParameter('code', $values['auth_source_code'], Types::INTEGER)->getQuery()->execute();
    }

    /** Purge/replacement changes the selected source without connecting to a remote server. */
    public function reassignMailServer(int $server, int $replacement): void
    {
        $this->em->createQueryBuilder()->update(User::class, 'u')->set('u.authmail', ':replacement')
            ->where('IDENTITY(u.authmail) = :server')->setParameter('server', $server, Types::INTEGER)
            ->setParameter('replacement', $replacement > 0 ? $this->em->getReference(\itsmng\Database\Entity\AuthMail::class, $replacement) : null)
            ->getQuery()->execute();
    }

    public function defaultPasswordCandidates(array $logins): array
    {
        if (!$logins) {
            return [];
        }
        return $this->em->createQueryBuilder()->select('u.name, u.password')->from(User::class, 'u')
            ->where('u.is_active = :yes AND u.is_deleted = :no AND LOWER(u.name) IN (:logins)')
            ->setParameter('yes', true, Types::BOOLEAN)->setParameter('no', false, Types::BOOLEAN)
            ->setParameter('logins', array_values(array_map('strtolower', $logins)))->orderBy('u.id')->getQuery()->getScalarResult();
    }
}
