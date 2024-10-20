<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\EventListener\Messenger\WorkerMessageReceivedEvent;

use App\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;

/**
 *  Кст где то в листенерах также можно хранить время последней обработки конкретных сообщений и спрашивать у конкретного хэндлера слепок условий, в которых оно было обработано (записывать а кэш)
 *  Далее по этому же интрфейсу спрашивать надо ли обраб
 *
 *
 *  А нужно ли UpdateTickerHandler ориентироваться
 *  1) на BuyOrder
 *      нет
 *  2) на Stop
 *      нет
 *
 *  Обновление раз в секунду было актуально для случая, когда цена рядом с ликвидацией
 *
 */
#[AsEventListener]
// кст где то в листенерах также можно хранить (вычислять?) время последней обработки конкретных сообщений
final readonly class CheckShouldMessageRunHandlerListener
{
    public function __construct(
        private HandlersLocatorInterface $handlersLocator,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(WorkerMessageReceivedEvent $event): void
    {
//        $message = $event->getEnvelope()->getMessage();
//
//        /**
//         * Полученный хэндлер ничего не знает об обработке в другом потоке
//         * => UpdateTicker в зависимости от BO сделать не получится
//         *
//         *   Наверное раз он будет имплементить какой-то интерфейс, то можно отдельным слоем после обработки сообщения дёргать какой-то метод для сохранения нужной инфы в кэш
//         *   И возможно статическим методом возвращать имя какой-то проверки, которая сможет сделать check
//         */
//        $handlers = $this->handlersLocator->getHandlers($event->getEnvelope());
//        foreach ($handlers as $handler) {
//            var_dump($handler->getName());
//        }
    }
}
