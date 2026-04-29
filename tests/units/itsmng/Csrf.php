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

namespace tests\units\itsmng;

use itsmng\Csrf as CsrfService;

/* Test for src/Csrf.php */

class Csrf extends \GLPITestCase
{
    public function testGeneratedTokensArePooled()
    {
        $_SESSION = [];

        $token = CsrfService::generate();

        $this->string($token)->matches('/^[a-f0-9]{64}$/');
        $this->array($_SESSION)->hasKey('glpicsrftokens');
        $this->array($_SESSION['glpicsrftokens'])->hasKey($token);
        $this->string($_SESSION['_glpi_csrf_token'])->isIdenticalTo($token);
        $this->integer($_SESSION['csrf_token_time'])->isGreaterThan(time());
    }

    public function testMultipleTokensRemainValidForOpenTabs()
    {
        $_SESSION = [];

        $firstTabToken = CsrfService::generate();
        $secondTabToken = CsrfService::generate();

        $this->boolean(CsrfService::verify(['_glpi_csrf_token' => $firstTabToken]))->isTrue();
        $this->boolean(CsrfService::verify(['_glpi_csrf_token' => $secondTabToken]))->isTrue();
        $this->boolean(CsrfService::verify(['_glpi_csrf_token' => $firstTabToken]))->isTrue();
    }

    public function testStandaloneTokenDoesNotReplaceCurrentToken()
    {
        $_SESSION = [];

        $currentToken = CsrfService::generate();
        $standaloneToken = CsrfService::generate(true);

        $this->string($_SESSION['_glpi_csrf_token'])->isIdenticalTo($currentToken);
        $this->array($_SESSION['glpicsrftokens'])->hasKey($standaloneToken);
        $this->boolean(CsrfService::verify(['_glpi_csrf_token' => $standaloneToken]))->isTrue();
    }

    public function testExpiredTokenIsRejectedAndCleaned()
    {
        $_SESSION = [
           'glpicsrftokens' => [
              'expired-token' => time() - 1,
           ],
        ];

        $this->boolean(CsrfService::verify(['_glpi_csrf_token' => 'expired-token']))->isFalse();
        $this->array($_SESSION['glpicsrftokens'])->notHasKey('expired-token');
    }

    public function testInvalidTokenShapeIsRejected()
    {
        $_SESSION = [];

        CsrfService::generate();

        $this->boolean(CsrfService::verify(['_glpi_csrf_token' => ['invalid']]))->isFalse();
    }
}
