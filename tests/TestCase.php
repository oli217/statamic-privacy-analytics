<?php

namespace Oliweb\StatamicAnalytics\Tests;

use Oliweb\StatamicAnalytics\ServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;
}
