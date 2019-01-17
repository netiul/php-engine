<?php
/**
 * This file is part of event-engine/php-engine.
 * (c) 2018-2019 prooph software GmbH <contact@prooph.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace EventEngine\Projecting;

use EventEngine\Aggregate\Exception\AggregateNotFound;
use EventEngine\DocumentStore\DocumentStore;
use EventEngine\DocumentStore\Index;
use EventEngine\Exception\RuntimeException;
use EventEngine\Messaging\GenericEvent;
use EventEngine\Messaging\Message;
use EventEngine\Persistence\AggregateStateStore;
use EventEngine\Persistence\DeletableState;
use EventEngine\Runtime\Flavour;
use EventEngine\Runtime\PrototypingFlavour;

final class AggregateProjector implements Projector, FlavourAware, DocumentStoreIndexAware
{
    /**
     * @var DocumentStore
     */
    private $documentStore;

    /**
     * @var AggregateStateStore
     */
    private $stateStore;

    /**
     * @var Index[]
     */
    private $indices = [];

    /**
     * Set to true, if document should only be the aggregate state.
     *
     * By default document has the structure ['state' => $aggregateState, 'version' => $aggregateVersion]
     * which can be used as a snapshot. However, it adds an extra level of structure that makes it slightly harder to query.
     *
     * @var bool
     */
    private $storeStateOnly;

    /**
     * @var Flavour
     */
    private $flavour;

    public static function aggregateCollectionName(string $projectionVersion, string $aggregateType): string
    {
        return self::generateCollectionName($projectionVersion, self::generateProjectionName($aggregateType));
    }

    public static function generateProjectionName(string $aggregateType): string
    {
        return $aggregateType . '.Projection';
    }

    public static function generateCollectionName(string $projectionVersion, string $projectionName): string
    {
        return \str_replace('.', '_', $projectionName.'_'.$projectionVersion);
    }

    public function __construct(DocumentStore $documentStore, AggregateStateStore $stateStore, $storeStateOnly = false)
    {
        $this->documentStore = $documentStore;
        $this->stateStore = $stateStore;
        $this->storeStateOnly = $storeStateOnly;
    }

    public function setFlavour(Flavour $flavour): void
    {
        $this->flavour = $flavour;
    }

    public function setDocumentStoreIndices(Index ...$indices): void
    {
        $this->indices = $indices;
    }

    private function flavour(): Flavour
    {
        if (null === $this->flavour) {
            $this->flavour = new PrototypingFlavour();
        }

        return $this->flavour;
    }

    /**
     * @param string $appVersion
     * @param string $projectionName
     * @param Message $event
     * @throws \Throwable
     */
    public function handle(string $appVersion, string $projectionName, Message $event): void
    {
        if (! $event instanceof Message) {
            throw new RuntimeException(__METHOD__ . ' can only handle events of type: ' . Message::class);
        }

        $aggregateId = $event->metadata()[GenericEvent::META_AGGREGATE_ID] ?? null;

        if (! $aggregateId) {
            return;
        }

        $aggregateType = $event->metadata()[GenericEvent::META_AGGREGATE_TYPE] ?? null;

        if (! $aggregateType) {
            return;
        }

        $aggregateVersion = $event->metadata()[GenericEvent::META_AGGREGATE_VERSION] ?? 0;

        $this->assertProjectionNameMatchesWithAggregateType($projectionName, (string) $aggregateType);

        try {
            $aggregateState = $this->stateStore->loadAggregateState((string) $aggregateType, (string) $aggregateId);
        } catch (AggregateNotFound $e) {
            return;
        }

        if (is_object($aggregateState) && $aggregateState instanceof DeletableState && $aggregateState->deleted()) {
            $this->documentStore->deleteDoc(
                $this->generateCollectionName($appVersion, $projectionName),
                (string) $aggregateId
            );

            return;
        }

        $document = $this->flavour()->convertAggregateStateToArray((string)$aggregateType, $aggregateState);

        if(!$this->storeStateOnly) {
            $document = [
                'state' => $document,
                'version' => $aggregateVersion
            ];
        }

        $this->documentStore->upsertDoc(
            $this->generateCollectionName($appVersion, $projectionName),
            (string) $aggregateId,
            $document
        );
    }

    public function prepareForRun(string $appVersion, string $projectionName): void
    {
        if (! $this->documentStore->hasCollection($this->generateCollectionName($appVersion, $projectionName))) {
            $this->documentStore->addCollection($this->generateCollectionName($appVersion, $projectionName), ...$this->indices);
        }
    }

    public function deleteReadModel(string $appVersion, string $projectionName): void
    {
        if ($this->documentStore->hasCollection(self::generateCollectionName($appVersion, $projectionName))) {
            $this->documentStore->dropCollection(self::generateCollectionName($appVersion, $projectionName));
        }
    }

    private function assertProjectionNameMatchesWithAggregateType(string $projectionName, string $aggregateType): void
    {
        if ($projectionName !== self::generateProjectionName($aggregateType)) {
            throw new \RuntimeException(\sprintf(
                'Wrong projection name configured for %s. Should be %s but got %s',
                __CLASS__,
                self::generateProjectionName($aggregateType),
                $projectionName
            ));
        }
    }
}