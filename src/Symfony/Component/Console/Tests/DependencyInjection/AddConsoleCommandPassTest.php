<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Console\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\CommandLoader\ContainerCommandLoader;
use Symfony\Component\Console\DependencyInjection\AddConsoleCommandPass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\TypedReference;

class AddConsoleCommandPassTest extends TestCase
{
    /**
     * @dataProvider visibilityProvider
     */
    public function testProcess($public)
    {
        $container = new ContainerBuilder();
        $container->addCompilerPass(new AddConsoleCommandPass());
        $container->setParameter('my-command.class', 'Symfony\Component\Console\Tests\DependencyInjection\MyCommand');

        $definition = new Definition('%my-command.class%');
        $definition->setPublic($public);
        $definition->addTag('console.command');
        $container->setDefinition('my-command', $definition);

        $container->compile();

        $alias = 'console.command.symfony_component_console_tests_dependencyinjection_mycommand';

        if ($public) {
            $this->assertFalse($container->hasAlias($alias));
            $id = 'my-command';
        } else {
            $id = $alias;
            // The alias is replaced by a Definition by the ReplaceAliasByActualDefinitionPass
            // in case the original service is private
            $this->assertFalse($container->hasDefinition('my-command'));
            $this->assertTrue($container->hasDefinition($alias));
        }

        $this->assertTrue($container->hasParameter('console.command.ids'));
        $this->assertSame(array($alias => $id), $container->getParameter('console.command.ids'));
    }

    public function testProcessRegistersLazyCommands()
    {
        $container = new ContainerBuilder();
        $command = $container
            ->register('my-command', MyCommand::class)
            ->setPublic(false)
            ->addTag('console.command', array('command' => 'my:command'))
            ->addTag('console.command', array('command' => 'my:alias'))
        ;

        (new AddConsoleCommandPass())->process($container);

        $commandLoader = $container->getDefinition('console.command_loader');
        $commandLocator = $container->getDefinition((string) $commandLoader->getArgument(0));

        $this->assertSame(ContainerCommandLoader::class, $commandLoader->getClass());
        $this->assertSame(array('my:command' => 'my-command', 'my:alias' => 'my-command'), $commandLoader->getArgument(1));
        $this->assertEquals(array(array('my-command' => new ServiceClosureArgument(new TypedReference('my-command', MyCommand::class)))), $commandLocator->getArguments());
        $this->assertSame(array('console.command.symfony_component_console_tests_dependencyinjection_mycommand' => false), $container->getParameter('console.command.ids'));
        $this->assertSame(array(array('setName', array('my:command')), array('setAliases', array(array('my:alias')))), $command->getMethodCalls());
    }

    public function visibilityProvider()
    {
        return array(
            array(true),
            array(false),
        );
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage The service "my-command" tagged "console.command" must not be abstract.
     */
    public function testProcessThrowAnExceptionIfTheServiceIsAbstract()
    {
        $container = new ContainerBuilder();
        $container->setResourceTracking(false);
        $container->addCompilerPass(new AddConsoleCommandPass());

        $definition = new Definition('Symfony\Component\Console\Tests\DependencyInjection\MyCommand');
        $definition->addTag('console.command', array('command' => 'my:command'));
        $definition->setAbstract(true);
        $container->setDefinition('my-command', $definition);

        $container->compile();
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage The service "my-command" tagged "console.command" must be a subclass of "Symfony\Component\Console\Command\Command".
     */
    public function testProcessThrowAnExceptionIfTheServiceIsNotASubclassOfCommand()
    {
        $container = new ContainerBuilder();
        $container->setResourceTracking(false);
        $container->addCompilerPass(new AddConsoleCommandPass());

        $definition = new Definition('SplObjectStorage');
        $definition->addTag('console.command', array('command' => 'my:command'));
        $container->setDefinition('my-command', $definition);

        $container->compile();
    }

    public function testProcessPrivateServicesWithSameCommand()
    {
        $container = new ContainerBuilder();
        $className = 'Symfony\Component\Console\Tests\DependencyInjection\MyCommand';

        $definition1 = new Definition($className);
        $definition1->addTag('console.command')->setPublic(false);

        $definition2 = new Definition($className);
        $definition2->addTag('console.command')->setPublic(false);

        $container->setDefinition('my-command1', $definition1);
        $container->setDefinition('my-command2', $definition2);

        (new AddConsoleCommandPass())->process($container);

        $alias1 = 'console.command.symfony_component_console_tests_dependencyinjection_mycommand';
        $alias2 = $alias1.'_my-command2';
        $this->assertTrue($container->hasAlias($alias1));
        $this->assertTrue($container->hasAlias($alias2));
    }
}

class MyCommand extends Command
{
}
