<?php

use Doctrine\ORM\EntityManager;
use itsmng\Database\Orm;
use itsmng\Database\Repository\TicketCollectionRepository;
use itsmng\Database\Repository\TicketVisibility;

include('../inc/includes.php');
global $DB;

Session::checkLoginUser();

// Get the last 6 months
$months = [];
$currentMonth = new DateTimeImmutable('first day of this month');
for ($i = 5; $i >= 0; $i--) {
    $months[] = $currentMonth->modify("-$i months")->format('Y-m');
}

$visibility = TicketVisibility::fromSession();
$counts = Orm::withConnection($DB->getDoctrineConnection(), static fn (EntityManager $manager): array =>
    (new TicketCollectionRepository($manager))->monthlyCounts($visibility));

// Initialize ticketData array with all months set to 0
$ticketData = array_fill(0, 6, 0);
$monthIndex = array_flip($months);

// Fetch results and populate ticketData array with actual ticket counts
foreach ($counts as $month => $count) {
    if (!isset($monthIndex[$month])) {
        continue;
    }
    $ticketData[$monthIndex[$month]] = $count;
}

// json parsing
header('Content-Type: application/json');
echo json_encode(array_values($ticketData));
