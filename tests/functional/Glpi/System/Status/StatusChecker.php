<?php

/**
 * ---------------------------------------------------------------------
 * GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2015-2022 Teclib' and contributors.
 *
 * http://glpi-project.org
 *
 * based on GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2003-2014 by the INDEPNET Development Team.
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * GLPI is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * GLPI is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with GLPI. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 */

namespace tests\units\Glpi\System\Status;

use AuthLDAP;
use AuthMail;
use CronTask;
use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use DbTestCase;
use Glpi\System\Status\StatusChecker as GlpiStatusChecker;
use itsmng\Database\Entity\CronTask as CronTaskEntity;
use itsmng\Database\Orm;

class StatusChecker extends DbTestCase
{
    public function testStatusFormats()
    {
        $status = GlpiStatusChecker::getFullStatus(true);
        $this->boolean(is_array($status))->isTrue();

        $status = GlpiStatusChecker::getFullStatus(true, false);
        $this->boolean(is_string($status))->isTrue();
    }

    public function testDefaultStatus()
    {
        $status = GlpiStatusChecker::getFullStatus(true);

        $known_services = ['db', 'cas', 'ldap', 'imap', 'mail_collectors', 'crontasks', 'filesystem', 'glpi', 'plugins'];
        // Check we are getting all of the expected services
        $this->array($status)->hasKeys($known_services);

        // Check each service has a status value
        foreach ($known_services as $service) {
            $this->boolean(is_array($status[$service]))->isTrue();
            $this->array($status[$service])->hasKey('status');
            $this->boolean(is_string($status[$service]['status']))->isTrue();
        }

        // Test overall status is OK
        $this->string($status['glpi']['status'])->isEqualTo(GlpiStatusChecker::STATUS_OK);

        // Test the overall status matches the combined status of the top-level services
        // NO_DATA should not count against the overall status.
        // If an administrator expects something to have data, that should be a separate service check in their monitoring system.
        $statuses = array_column($status, 'status');
        $all_ok = !in_array(GlpiStatusChecker::STATUS_PROBLEM, $statuses, true);
        $this->boolean($all_ok)->isTrue();

        // We have no plugins. Verify the status is NO_DATA
        $this->string($status['plugins']['status'])->isEqualTo(GlpiStatusChecker::STATUS_NO_DATA);
        // We should have a master DB for tests. Verify status is OK.
        $this->string($status['db']['master']['status'])->isEqualTo(GlpiStatusChecker::STATUS_OK);
        // We have no DB slaves. Verify the status is NO_DATA
        $this->string($status['db']['slaves']['status'])->isEqualTo(GlpiStatusChecker::STATUS_NO_DATA);
        // We have no CAS. Verify the status is NO_DATA
        $this->string($status['cas']['status'])->isEqualTo(GlpiStatusChecker::STATUS_NO_DATA);
        // We have no LDAP servers. Verify the status is NO_DATA
        $this->string($status['ldap']['status'])->isEqualTo(GlpiStatusChecker::STATUS_NO_DATA);
        // We have no IMAP servers. Verify the status is NO_DATA
        $this->string($status['imap']['status'])->isEqualTo(GlpiStatusChecker::STATUS_NO_DATA);
        // We have no mail collectors. Verify the status is NO_DATA
        $this->string($status['mail_collectors']['status'])->isEqualTo(GlpiStatusChecker::STATUS_NO_DATA);

        // Make sure no stuck cron tasks are reported
        $this->string($status['crontasks']['status'])->isEqualTo(GlpiStatusChecker::STATUS_OK);
        $this->array($status['crontasks']['stuck'])->size->isEqualTo(0);

        // Check filesystem and session_dir are OK
        $this->string($status['filesystem']['status'])->isEqualTo(GlpiStatusChecker::STATUS_OK);
        $this->string($status['filesystem']['session_dir']['status'])->isEqualTo(GlpiStatusChecker::STATUS_OK);
    }

    public function testCronTaskStatusRetainsCallerFrame()
    {
        global $DB;

        $connection = $DB->getDoctrineConnection();
        $depth = $connection->getTransactionNestingLevel();
        $this->integer($depth)->isGreaterThan(0);
        $em = Orm::create($DB);
        $em->createQuery('UPDATE ' . CronTaskEntity::class . ' t SET t.state = :waiting')
            ->setParameter('waiting', CronTask::STATE_WAITING)->execute();
        $prefix = 'Status overdue ' . bin2hex(random_bytes(6));
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        foreach ([
            ['Ticket', 'duplicate', 60, $now->modify('-1 day'), CronTask::STATE_RUNNING],
            ['Problem', 'duplicate', 86400, $now->modify('-1 day'), CronTask::STATE_RUNNING],
            ['CronTask', 'never run', 60, null, CronTask::STATE_RUNNING],
            ['CronTask', 'future', 60, $now->modify('+1 day'), CronTask::STATE_RUNNING],
            ['CronTask', 'waiting', 60, $now->modify('-1 day'), CronTask::STATE_WAITING],
            ['CronTask', 'disabled', 60, $now->modify('-1 day'), CronTask::STATE_DISABLE],
        ] as [$type, $name, $frequency, $lastrun, $state]) {
            $task = new CronTaskEntity();
            $task->itemtype = $type;
            $task->name = $prefix . ' ' . $name;
            $task->frequency = $frequency;
            $task->lastrun = $lastrun === null ? null : DateTime::createFromImmutable($lastrun);
            $task->state = $state;
            $em->persist($task);
        }
        $em->flush();
        $savedClock = $_SESSION['glpi_currenttime'];
        try {
            $_SESSION['glpi_currenttime'] = '1999-01-01 00:00:00';
            $status = GlpiStatusChecker::getCronTaskStatus();
            $this->array($status)->isEqualTo(['status' => GlpiStatusChecker::STATUS_PROBLEM,
                'stuck' => [$prefix . ' duplicate', $prefix . ' duplicate']]);
            $this->array(GlpiStatusChecker::getCronTaskStatus(false))->isEqualTo($status);
            $this->variable($DB->getDoctrineConnection())->isIdenticalTo($connection);
            $this->integer($connection->getTransactionNestingLevel())->isEqualTo($depth);
            $DB->assertManagedTransaction();
            // A real query proves the health read did not leave a PostgreSQL frame aborted.
            $this->integer((int)$connection->fetchOne('SELECT COUNT(*) FROM glpi_crontasks'))
                ->isGreaterThanOrEqualTo(6);
        } finally {
            $_SESSION['glpi_currenttime'] = $savedClock;
        }
    }

    public function testBadStatus()
    {
        global $DB;

        // Run a DB check first so the status checker knows the DB is available.
        // Future checks won't re-run that check then, so the DB changes aren't lost.
        GlpiStatusChecker::getDBStatus();

        // Manually start a transaction since this is a new connection
        $DB->beginTransaction();

        // Add a bunch of bad service items
        $auth_mail = new AuthMail();
        $authmail_id = $auth_mail->add([
           'name'            => 'testmail',
           'connect_string'  => '{smtp.localhost/imap/ssl/validate-cert/tls/secure}',
           'host'            => 'localhost',
           'is_active'       => 1
        ]);
        $this->integer($authmail_id)->isGreaterThan(0);

        $auth_ldap = new AuthLDAP();
        $authlap_id = $auth_ldap->add([
           'name'            => 'testldap',
           'host'            => 'localhost',
           'is_active'       => 1,
           'rootdn'          => 'cn=Manager,dc=glpi,dc=org',
           'rootdn_passwd'   => md5(mt_rand())
        ]);
        $this->integer($authlap_id)->isGreaterThan(0);

        $crontask = new CronTask();
        // A definitely stuck crontask running for over an hour.
        $crontask->add([
           'itemtype'     => 'Ticket',
           'name'         => 'stucktest1',
           'frequency'    => 60,
           'lastrun'      => date('Y-m-d 00:00:00', strtotime('-61 minute')),
           'state'        => \CronTask::STATE_RUNNING,
        ]);
        // A probably stuck crontask running for longer than the run interval.
        $crontask->add([
           'itemtype'     => 'Ticket',
           'name'         => 'stucktest2',
           'frequency'    => 60,
           'lastrun'      => date('Y-m-d 00:00:00', strtotime('-5 minute')),
           'state'        => \CronTask::STATE_RUNNING,
        ]);

        $status = GlpiStatusChecker::getFullStatus(true);

        // Test overall status is PROBLEM
        $this->string($status['glpi']['status'])->isEqualTo(GlpiStatusChecker::STATUS_PROBLEM);

        // Test there is at least one service with a problem
        $statuses = array_column($status, 'status');
        $all_ok = !in_array(GlpiStatusChecker::STATUS_PROBLEM, $statuses, true);
        $this->boolean($all_ok)->isFalse();

        // We have a non-existent LDAP server. Verify the status is PROBLEM.
        $this->string($status['ldap']['status'])->isEqualTo(GlpiStatusChecker::STATUS_PROBLEM);
        // We have a non-existent IMAP server. Verify the status is PROBLEM.
        $this->string($status['imap']['status'])->isEqualTo(GlpiStatusChecker::STATUS_PROBLEM);

        // Make sure there are two stuck tasks
        $this->string($status['crontasks']['status'])->isEqualTo(GlpiStatusChecker::STATUS_PROBLEM);
        $this->array($status['crontasks']['stuck'])->size->isEqualTo(2);

        // afterTestMethod will rollback the DB changes for us
    }
}
