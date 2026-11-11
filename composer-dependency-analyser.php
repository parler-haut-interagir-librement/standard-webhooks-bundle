<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

/**
 * The bundle's declared dependencies, checked against the bundle installed on its own.
 *
 * - Symfony's HTTP client and its contracts, Doctrine DBAL and ORM, and Symfony Console are optional: their
 *   services are used only when the application has them; `NoPrivateNetworkHttpClient` guards the sender's client,
 *   which only `symfony/http-client` provides in a Symfony application.
 * - The `service()` function the test kernel wires with lives in the same file as `ContainerConfigurator`, from
 *   `symfony/dependency-injection`, which is required: it is loaded with the class, not by Composer's autoloader.
 */
return new Configuration()
    ->addPathToScan(__DIR__.'/src', isDev: false)
    ->addPathToScan(__DIR__.'/tests', isDev: true)
    ->ignoreErrorsOnPackages(['symfony/http-client', 'symfony/http-client-contracts', 'doctrine/dbal', 'doctrine/orm', 'symfony/console'], [ErrorType::DEV_DEPENDENCY_IN_PROD])
    ->ignoreUnknownFunctions(['Symfony\Component\DependencyInjection\Loader\Configurator\service']);
