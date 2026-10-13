<?php

// SPDX-License-Identifier: GPL-2.0-or-later

use atoum\atoum;

$runner->removeReports();
$tap = new atoum\reports\realtime\tap();
$tap->addWriter($script->getOutputWriter());
$runner->addReport($tap);

$report = new atoum\reports\asynchronous\xunit();
$report->addWriter(new atoum\writers\file(getenv('ITSM_MIGRATION_XUNIT')));
$runner->addReport($report);
