<?php

declare(strict_types=1);

namespace App\Trading\Infrastructure\Symfony\CompillerPass;

use App\Trading\Application\Symbol\SymbolProvider;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final readonly class AddSymbolEntityProviderToCommandsCompilerPass implements CompilerPassInterface
{
    private const string SYMBOL_ENTITY_PROVIDER_SERVICE_ID = SymbolProvider::class;
//    private const string INITIALIZE_SYMBOL_HANDLER_SERVICE_ID = InitializeSymbolsHandler::class;

    const string COMMAND_SYMBOL_DEPENDENT_TAG = 'command.symbol_dependent';

    public function process(ContainerBuilder $container): void
    {
        $symbolsProvider = $container->getDefinition(self::SYMBOL_ENTITY_PROVIDER_SERVICE_ID);

        foreach ($container->findTaggedServiceIds(self::COMMAND_SYMBOL_DEPENDENT_TAG) as $id => $params) {
            $container->findDefinition($id)->addMethodCall('withSymbolProvider', [$symbolsProvider]);
        }
    }
}
