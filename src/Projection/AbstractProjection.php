<?php
/**
 * This file is part of the prooph/event-store.
 * (c) 2014-2016 prooph software GmbH <contact@prooph.de>
 * (c) 2015-2016 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Prooph\EventStore\Projection;

use ArrayIterator;
use Iterator;
use Prooph\Common\Messaging\Message;
use Prooph\EventStore\EventStore;
use Prooph\EventStore\Exception\RuntimeException;
use Prooph\EventStore\Exception\StreamNotFound;
use Prooph\EventStore\Stream;
use Prooph\EventStore\StreamName;

abstract class AbstractProjection extends AbstractQuery implements Projection
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @var bool
     */
    protected $emitEnabled;

    public function __construct(EventStore $eventStore, string $name, bool $emitEnabled)
    {
        parent::__construct($eventStore);

        $this->name = $name;
        $this->emitEnabled = $emitEnabled;
    }

    abstract protected function load(): void;

    abstract protected function persist(): void;

    protected function resetProjection(): void
    {
        $this->eventStore->delete(new StreamName($this->name));
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function emit(Message $event): void
    {
        if (! $this->emitEnabled) {
            throw new RuntimeException('Emit is disabled');
        }

        $this->linkTo($this->name, $event);
    }

    public function linkTo(string $streamName, Message $event): void
    {
        $this->eventStore->appendTo(new StreamName($streamName), new ArrayIterator([$event]));
    }

    public function reset(): void
    {
        parent::reset();

        $this->resetProjection();
    }

    public function run(): void
    {
        if (null === $this->position
            || (null === $this->handler && empty($this->handlers))
        ) {
            throw new RuntimeException('No handlers configured');
        }

        $this->load();

        if ($this->emitEnabled && ! $this->eventStore->hasStream(new StreamName($this->name))) {
            $this->eventStore->create(new Stream(new StreamName($this->name), new ArrayIterator()));
        }

        $singleHandler = null !== $this->handler;

        foreach ($this->position->streamPositions() as $streamName => $position) {
            try {
                $stream = $this->eventStore->load(new StreamName($streamName), $position + 1);
            } catch (StreamNotFound $e) {
                // no newer events found
                continue;
            }

            if ($singleHandler) {
                $this->handleStreamWithSingleHandler($streamName, $stream->streamEvents());
            } else {
                $this->handleStreamWithHandlers($streamName, $stream->streamEvents());
            }

            if ($this->isStopped) {
                break;
            }
        }
    }

    protected function handleStreamWithSingleHandler(string $streamName, Iterator $events): void
    {
        foreach ($events as $event) {
            /* @var Message $event */
            $this->position->inc($streamName);
            $handler = $this->handler;
            $result = $handler($this->state, $event);

            if (is_array($result)) {
                $this->state = $result;
            }

            $this->persist();

            if ($this->isStopped) {
                break;
            }
        }
    }

    protected function handleStreamWithHandlers(string $streamName, Iterator $events): void
    {
        foreach ($events as $event) {
            /* @var Message $event */
            $this->position->inc($streamName);
            if (! isset($this->handlers[$event->messageName()])) {
                continue;
            }
            $handler = $this->handlers[$event->messageName()];
            $result = $handler($this->state, $event);

            if (is_array($result)) {
                $this->state = $result;
            }

            $this->persist();

            if ($this->isStopped) {
                break;
            }
        }
    }
}