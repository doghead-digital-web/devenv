<?php

declare(strict_types=1);

use Tests\TestCase;

pest()->extend(TestCase::class)->in('Laravel');

function module_path(string $path = ''): string
{
    return dirname(__DIR__).($path === '' ? '' : '/'.$path);
}
