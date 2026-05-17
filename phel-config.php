<?php

declare(strict_types=1);

use Phel\Config\PhelConfig;
use Phel\Config\ProjectLayout;

return PhelConfig::forProject(ProjectLayout::Nested)
    ->withSrcDirs(['src/Phel'])
    ->withTestDirs(['tests/Phel'])
    ->withMainPhelNamespace('app.app');
