<?php

declare(strict_types=1);

use Deptrac\Deptrac\Contract\Config\Collector\DirectoryConfig;
use Deptrac\Deptrac\Contract\Config\DeptracConfig;
use Deptrac\Deptrac\Contract\Config\Layer;
use Deptrac\Deptrac\Contract\Config\Ruleset;

return static function (DeptracConfig $config): void {
    $config
        ->paths('./src')
        ->layers(
            $domain = Layer::withName('Domain')->collectors(
                DirectoryConfig::create('src/Domain/.*'),
            ),
            $infrastructure = Layer::withName('Infrastructure')->collectors(
                DirectoryConfig::create('src/Infrastructure/.*'),
            ),
            $repository = Layer::withName('Repository')->collectors(
                DirectoryConfig::create('src/Repository/.*'),
            ),
            $service = Layer::withName('Service')->collectors(
                DirectoryConfig::create('src/Service/.*'),
            ),
            $controller = Layer::withName('Controller')->collectors(
                DirectoryConfig::create('src/Http/Controller/.*'),
            ),
            $httpKernel = Layer::withName('HttpKernel')->collectors(
                DirectoryConfig::create('src/Http/(Middleware|Router|Validation|Exception)/.*'),
                DirectoryConfig::create('src/Http/Session\.php$'),
            ),
        )
        ->rulesets(
            // Domain depends on nothing (README Design Constraint #1).
            Ruleset::forLayer($domain),
            // Infrastructure (the PDO connection) depends on nothing app-level.
            Ruleset::forLayer($infrastructure),
            // Repository never depends on Service.
            Ruleset::forLayer($repository)->accesses($domain, $infrastructure),
            // Service never depends on Http\Controller.
            Ruleset::forLayer($service)->accesses($domain, $repository, $infrastructure),
            // Controller may depend on Repository directly (documented exception),
            // plus Service via ServiceFactory. Not Infrastructure: all DB access
            // must go through a Repository.
            Ruleset::forLayer($controller)->accesses($domain, $repository, $service, $httpKernel),
            // Supporting HTTP pieces (Middleware/Router/Validation/Exception/Session)
            // aren't individually constrained by the README, so this bucket stays
            // permissive — Router legitimately needs to reference Controllers to dispatch.
            Ruleset::forLayer($httpKernel)->accesses($domain, $controller, $service, $repository, $infrastructure),
        )
    ;
};
