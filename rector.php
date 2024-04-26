<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Utils\Rector\Rector\RenameVariablesToRandomNamesRector;

return RectorConfig::configure()
    ->withRules([
        RenameVariablesToRandomNamesRector::class,
    ]);
