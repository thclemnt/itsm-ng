<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace tests\units\itsmng\Database\Migration;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use itsmng\Database\Migration\History as Releases;
use itsmng\Database\Migration\Version220;
use itsmng\Database\Migration\V220\Baseline;

class History extends \atoum\atoum\test
{
    public function testPreviewDefersDependentReleasesAndSkipsAppliedTargets(): void
    {
        $connection = new ReleaseJournalFixtureConnection();
        $calls = new \ArrayObject();
        $first = $this->release('fixture-first', $calls);
        $second = $this->release('fixture-second', $calls);
        $history = new Releases([$first, $second]);
        $plan = $history->plan($connection);
        $this->array($plan['pending'])->isIdenticalTo(['fixture-first', 'fixture-second']);
        $this->array($plan['deferred_releases'])->isIdenticalTo(['fixture-second']);
        $this->array($calls->getArrayCopy())->isIdenticalTo(['fixture-first']);
        $connection->states['fixture-first'] = ['complete' => false, 'applied' => true];
        $calls->exchangeArray([]);
        $plan = $history->plan($connection);
        $this->string($plan['planned_release'])->isIdenticalTo('fixture-second');
        $this->array($plan['pending'])->isIdenticalTo(['fixture-first', 'fixture-second']);
        $this->array($calls->getArrayCopy())->isIdenticalTo(['fixture-second']);
        $connection->states['fixture-first'] = $connection->states['fixture-second'] = ['complete' => true];
        $calls->exchangeArray([]);
        $this->boolean($history->plan($connection)['complete'])->isTrue();
        $this->array($calls->getArrayCopy())->isEmpty();
    }

    private function release(string $version, \ArrayObject $calls): \itsmng\Database\Migration\ReleaseMigration
    {
        return new class ($version, $calls) implements \itsmng\Database\Migration\ReleaseMigration {
            public function __construct(private string $name, private \ArrayObject $calls) {}
            public function version(): string { return $this->name; }
            public function plan(Connection $connection): array { $this->calls[] = $this->name; return []; }
            public function apply(Connection $connection, ?callable $progress = null): void { throw new \LogicException('Preview wrote a release.'); }
            public function verify(Connection $connection): void { throw new \LogicException('Preview inspected an unapplied target.'); }
        };
    }

    public function testExperimentalCheckpointsDoNotPublishAnOrmRelease(): void
    {
        $connection = new ReleaseJournalFixtureConnection();
        $this->array(Releases::pendingVersions($connection))->isIdenticalTo(['2.2.0']);
        $connection->states = array_fill_keys(Version220::PHASES, ['complete' => true]);
        $original = $connection->states;
        $this->array(Releases::pendingVersions($connection))->isIdenticalTo(['2.2.0']);
        $this->array($connection->states)->isIdenticalTo($original);
        $connection->states[Version220::VERSION] = ['complete' => true];
        $this->array(Releases::pendingVersions($connection))->isEmpty();
        $connection->states[Baseline::PHASE] = ['complete' => false, 'origin' => 'installed', 'next' => 1];
        $this->array(Releases::pendingVersions($connection))->isIdenticalTo(['2.2.0']);
        $this->integer($connection->catalogueReads)->isIdenticalTo(4);
        $this->integer($connection->journalReads)->isIdenticalTo(4);
    }
}

/** Read-only readiness double; no native driver, DDL or metadata schema exists. */
final class ReleaseJournalFixtureConnection extends Connection
{
    public array $states = [];
    public int $catalogueReads = 0;
    public int $journalReads = 0;

    public function __construct()
    {
    }

    public function getDatabasePlatform(): AbstractPlatform
    {
        return new PostgreSQLPlatform();
    }

    public function fetchOne(string $query, array $params = [], array $types = []): mixed
    {
        if ($query !== 'SELECT to_regclass(?)') {
            throw new \LogicException('Unexpected readiness query: ' . $query);
        }
        ++$this->catalogueReads;
        return 'itsmng_migrations';
    }

    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        if ($query !== 'SELECT version, state FROM itsmng_migrations') {
            throw new \LogicException('Unexpected readiness query: ' . $query);
        }
        ++$this->journalReads;
        $rows = [];
        foreach ($this->states as $version => $state) {
            $rows[] = ['version' => $version, 'state' => json_encode($state, JSON_THROW_ON_ERROR)];
        }
        return $rows;
    }
}
