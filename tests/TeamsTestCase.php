<?php

declare(strict_types=1);

namespace Cbox\Id\Spatie\Tests;

/**
 * Runs the suite with Spatie's teams feature on, so each platform organization maps
 * onto a Spatie team and roles/assignments are scoped per organization.
 */
abstract class TeamsTestCase extends TestCase
{
    protected bool $teams = true;
}
