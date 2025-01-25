<?php

declare(strict_types=1);

namespace App\Infrastructure\Symfony\Messenger\Async\Debug;

use App\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * @todo
 * Кст где то в листенерах также можно хранить (вычислять?) время последней обработки конкретных сообщений
 * И тогда ....
 * => можно будет добавить единую точку для проверки:
 *    этой точкой будет лог единственного воркера, глядя на который можно будет сказать: всё остальное работает, раз тут с периодичсностью в 5 секунд есть сообщения
 *      сам воркер должен проверять, что сообщеньки генерируются / обрабатываются максимум с каким-то интервалом
 *         в противном случае писать в app_error (для начала туда, чтобы видеть вообще все проблемы)
 */
#[AsEventListener]
final readonly class LogReceivedMessageTimestampDebugListener
{
    public function __construct(
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(WorkerMessageReceivedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();

        if (in_array(MessageWithDispatchingTimeTrait::class, class_uses($message), true)) {
            /** @var MessageWithDispatchingTimeTrait $message */

            $messageClass = explode('\\', get_class($message));
            $messageClass = end($messageClass);

            if ($dispatchedAt = $message->getDispatchedDateTime()) {
                $this->logger->debug(sprintf('%s created at: %s', $messageClass, $dispatchedAt->format('H:i:s.u')));
            }

            if ($receivedAt = $this->clock->now()) {
                $this->logger->debug(sprintf('%s handled at: %s', $messageClass, $receivedAt->format('H:i:s.u')));
            }

            if ($receivedAt && $dispatchedAt) {
                $delta = (float)$receivedAt->format('U.u') - (float)$dispatchedAt->format('U.u');
                $this->logger->debug(sprintf('% ' . strlen($messageClass . ' handled at') . 's: %s', 'delta', $delta));
            }
        }
    }
}
