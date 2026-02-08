<?php

declare(strict_types=1);

namespace ForbiddenChecker\Tests;

use ForbiddenChecker\Domain\Auth\TotpService;

final class TotpServiceTest extends TestCase
{
    public function run(): void
    {
        $totp = new TotpService();
        $secret = 'JBSWY3DPEHPK3PXP';

        $timeSlice = (int) floor(time() / 30);
        $code = $totp->at($secret, $timeSlice);

        $this->assertTrue($totp->verify($secret, $code), 'Expected generated TOTP code to verify.');
        $this->assertTrue(!$totp->verify($secret, '000000'), 'Expected invalid TOTP code to fail.');
    }
}
