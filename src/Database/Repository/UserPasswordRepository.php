<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Database\Repository;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use itsmng\Database\Entity\Alert;
use itsmng\Database\Entity\User;

/** Local password policy queries; delivery and account history stay in the application. */
final class UserPasswordRepository
{
    public function __construct(private EntityManager $em)
    {
    }

    /** Only a local account with no selected external source can supply a password. */
    public function localCredentials(string $login, int $expirationDays, int $lockDays): ?array
    {
        if (str_contains($login, "\0")) {
            return null;
        }
        $rows = $this->em->createQueryBuilder()->select('u.id, u.password')
            ->addSelect("DATE_ADD(u.password_last_update, :expiration, 'DAY') AS password_expiration_date")
            ->addSelect("DATE_ADD(u.password_last_update, :lock, 'DAY') AS lock_date")
            ->from(User::class, 'u')->where('u.name = :login AND u.authtype = :local AND u.auth_source_code = :none')
            ->setParameter('login', $login)->setParameter('local', \Auth::DB_GLPI, Types::INTEGER)
            ->setParameter('none', 0, Types::INTEGER)->setParameter('expiration', $expirationDays, Types::INTEGER)
            ->setParameter('lock', $expirationDays + $lockDays, Types::INTEGER)->setMaxResults(2)
            ->getQuery()->getScalarResult();
        if (count($rows) !== 1) {
            return null;
        }
        $row = $rows[0];
        // Computed date scalars are not ORM-hydrated fields. Preserve the local
        // wall-clock string contract, including PostgreSQL's session offset.
        foreach (['password_expiration_date', 'lock_date'] as $field) {
            if ($row[$field] !== null) {
                $row[$field] = (new \DateTimeImmutable($row[$field]))->format('Y-m-d H:i:s');
            }
        }
        return $row;
    }

    /** An ambiguous or expired reset token must never select an account. */
    public function forgottenTokenUser(string $token): ?int
    {
        if ($token === '' || str_contains($token, "\0")) {
            return null;
        }
        $rows = $this->em->createQueryBuilder()->select('u.id')->from(User::class, 'u')
            ->where("u.password_forget_token = :token AND CURRENT_TIMESTAMP() < DATE_ADD(u.password_forget_token_date, 1, 'DAY')")
            ->setParameter('token', $token)->setMaxResults(2)->getQuery()->getScalarResult();
        return count($rows) === 1 ? (int)$rows[0]['id'] : null;
    }

    public function noticeCount(int $days): int
    {
        return (int)$this->noticeQuery($days)->select('COUNT(u.id)')->getQuery()->getSingleScalarResult();
    }

    public function notices(int $days, int $limit): array
    {
        $query = $this->noticeQuery($days)->select('u.id AS user_id, a.id AS alert_id')->orderBy('u.id')->addOrderBy('a.id');
        // The existing cron contract uses a nonpositive limit for an unrestricted batch.
        if ($limit > 0) {
            $query->setMaxResults($limit);
        }
        return $query->getQuery()->getScalarResult();
    }

    /** Bulk expiry is atomic and must not invoke user hooks or reconnect external sources. */
    public function disableExpired(int $days): int
    {
        return $this->eligible($this->em->createQueryBuilder()->update(User::class, 'u'), $days)
            ->set('u.is_active', ':no')->set('u.cookie_token', 'NULL')->set('u.cookie_token_date', 'NULL')
            ->getQuery()->execute();
    }

    private function noticeQuery(int $days): QueryBuilder
    {
        $query = $this->eligible($this->em->createQueryBuilder()->from(User::class, 'u'), $days);
        $query->leftJoin(Alert::class, 'a', 'WITH', 'a.user = u')

            ->andWhere("a.date IS NULL OR a.date < DATE_SUB(CURRENT_TIMESTAMP(), 1, 'DAY')");
        return $query;
    }

    private function eligible(QueryBuilder $query, int $days): QueryBuilder
    {
        return $query->where('u.is_deleted = :no AND u.is_active = :yes AND u.authtype = :local')
            ->andWhere("CURRENT_TIMESTAMP() > DATE_ADD(u.password_last_update, :days, 'DAY')")
            ->setParameter('no', false, Types::BOOLEAN)->setParameter('yes', true, Types::BOOLEAN)
            ->setParameter('local', \Auth::DB_GLPI, Types::INTEGER)->setParameter('days', $days, Types::INTEGER);
    }
}
