<?php

// SPDX-License-Identifier: GPL-2.0-or-later

namespace itsmng\Domain;

use itsmng\Database\Entity\Contract;

/** Calendar-only policy; persistence and public notification hooks remain outside. */
final class ContractSchedule
{
    public function __construct(private Contract $contract)
    {
    }

    /** Legacy models and notification payloads adapt to the same entity-owned dates. */
    public static function fromFields(array $fields): self
    {
        $contract = new Contract();
        $contract->begin_date = empty($fields['begin_date']) ? null : ($fields['begin_date'] instanceof \DateTimeInterface ? \DateTimeImmutable::createFromInterface($fields['begin_date']) : new \DateTimeImmutable($fields['begin_date']));
        $contract->duration = (int)($fields['duration'] ?? 0);
        $contract->notice = (int)($fields['notice'] ?? 0);
        $contract->periodicity = (int)($fields['periodicity'] ?? 0);
        return new self($contract);
    }

    public function deadline(bool $notice = false, bool $automaticRenewal = false, ?\DateTimeImmutable $today = null): ?\DateTimeImmutable
    {
        $deadline = $notice ? $this->contract->noticeStartsOn() : $this->contract->endsOn();
        if ($deadline === null || !$automaticRenewal || $this->contract->duration <= 0) {
            return $deadline;
        }
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $renewal = max(0, intdiv(max(0, self::monthsBetween($deadline, $today)), $this->contract->duration));
        $deadline = $this->contract->renewedDeadline($renewal, $notice);
        while ($deadline <= $today) {
            $deadline = $this->contract->renewedDeadline(++$renewal, $notice);
        }
        return $deadline;
    }

    /** One due renewal after the previous alert's calendar day; same-day calls do not repeat. */
    public function duePeriod(int $daysBefore, ?\DateTimeInterface $previous = null, bool $notice = false, ?\DateTimeImmutable $today = null): ?\DateTimeImmutable
    {
        $deadline = $this->contract->periodEndsOn(0, $notice);
        if ($deadline === null) {
            return null;
        }
        $previous = $previous === null ? new \DateTimeImmutable('1970-01-01') : \DateTimeImmutable::createFromInterface($previous)->setTime(0, 0);
        $today = ($today ?? new \DateTimeImmutable('today'))->setTime(0, 0);
        $due = $deadline->modify(sprintf('%+d days', -$daysBefore));
        $period = max(0, intdiv(max(0, self::monthsBetween($due, $previous)), $this->contract->periodicity));
        do {
            $deadline = $this->contract->periodEndsOn($period++, $notice);
            $due = $deadline->modify(sprintf('%+d days', -$daysBefore));
        } while ($due <= $previous);
        return $due <= $today ? $deadline : null;
    }

    private static function monthsBetween(\DateTimeInterface $start, \DateTimeInterface $end): int
    {
        return ((int)$end->format('Y') - (int)$start->format('Y')) * 12 + (int)$end->format('m') - (int)$start->format('m');
    }
}
