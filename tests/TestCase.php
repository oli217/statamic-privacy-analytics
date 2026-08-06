<?php

namespace Oliweb\StatamicAnalytics\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Oliweb\StatamicAnalytics\ServiceProvider;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    use RefreshDatabase;

    protected string $addonServiceProvider = ServiceProvider::class;
}
