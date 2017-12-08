<?php

namespace App\Command\Handler\User;

use App\Command\User\DeleteOrganizationCommand;
use App\Event\CommandEvent;
use JMS\DiExtraBundle\Annotation as DI;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Class DeleteOrganizationHandler
 *
 * @DI\Service()
 */
class DeleteOrganizationHandler extends BaseUserHandler
{
    /**
     * @DI\Observe(App\Command\User\DeleteOrganizationCommand::class)
     *
     * @param CommandEvent $event
     * @param string $eventName
     * @param EventDispatcherInterface $dispatcher
     *
     * @throws \Exception
     */
    public function handle(CommandEvent $event, string $eventName, EventDispatcherInterface $dispatcher): void
    {
        /** @var DeleteOrganizationCommand $command */
        $command = $event->getCommand();
        $this->validate($command, $command);

        $organization = $command->getOrg();

        $this->em->remove($organization);

//        $dispatcher->dispatch(DeleteOrganizationEvent::class, new DeleteOrganizationEvent());
    }
}
