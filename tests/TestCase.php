<?php

namespace Oliweb\StatamicAnalytics\Tests;

use Mohammedshuaau\EnhancedAnalytics\ServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;
}
