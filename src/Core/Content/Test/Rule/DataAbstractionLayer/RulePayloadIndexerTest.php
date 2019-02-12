<?php declare(strict_types=1);

namespace Shopware\Core\Content\Test\Rule\DataAbstractionLayer;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Content\Rule\DataAbstractionLayer\Indexing\RulePayloadIndexer;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Plugin\Event\PluginPostActivateEvent;
use Shopware\Core\Framework\Plugin\Event\PluginPostDeactivateEvent;
use Shopware\Core\Framework\Plugin\Event\PluginPostInstallEvent;
use Shopware\Core\Framework\Plugin\Event\PluginPostUninstallEvent;
use Shopware\Core\Framework\Plugin\Event\PluginPostUpdateEvent;
use Shopware\Core\Framework\Rule\Container\AndRule;
use Shopware\Core\Framework\Rule\Container\OrRule;
use Shopware\Core\Framework\Rule\CurrencyRule;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Rule\SalesChannelRule;
use Shopware\Core\Framework\Struct\Uuid;
use Shopware\Core\Framework\Test\TestCaseBase\IntegrationTestBehaviour;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class RulePayloadIndexerTest extends TestCase
{
    use IntegrationTestBehaviour;

    /**
     * @var Context
     */
    private $context;

    /**
     * @var EntityRepositoryInterface
     */
    private $repository;

    /**
     * @var RulePayloadIndexer
     */
    private $indexer;

    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    protected function setUp(): void
    {
        $this->repository = $this->getContainer()->get('rule.repository');
        $this->indexer = $this->getContainer()->get(RulePayloadIndexer::class);
        $this->connection = $this->getContainer()->get(Connection::class);
        $this->context = Context::createDefaultContext();
        $this->eventDispatcher = $this->getContainer()->get('event_dispatcher');
    }

    public function testIndex(): void
    {
        $id = Uuid::uuid4()->getHex();
        $currencyId1 = Uuid::uuid4()->getHex();
        $currencyId2 = Uuid::uuid4()->getHex();

        $data = [
            'id' => $id,
            'name' => 'test rule',
            'priority' => 1,
            'conditions' => [
                [
                    'type' => (new OrRule())->getName(),
                    'children' => [
                        [
                            'type' => (new CurrencyRule())->getName(),
                            'value' => [
                                'currencyIds' => [
                                    $currencyId1,
                                    $currencyId2,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->repository->create([$data], $this->context);

        $this->connection->update('rule', ['payload' => null, 'invalid' => '1'], ['1' => '1']);
        $rule = $this->repository->search(new Criteria([$id]), $this->context)->get($id);
        static::assertNull($rule->get('payload'));
        $this->indexer->index(new \DateTime());
        $rule = $this->repository->search(new Criteria([$id]), $this->context)->get($id);
        static::assertNotNull($rule->getPayload());
        static::assertInstanceOf(Rule::class, $rule->getPayload());
        static::assertEquals(
            new AndRule([new OrRule([(new CurrencyRule())->assign(['currencyIds' => [$currencyId1, $currencyId2]])])]),
            $rule->getPayload()
        );
    }

    public function testRefresh(): void
    {
        $id = Uuid::uuid4()->getHex();
        $currencyId1 = Uuid::uuid4()->getHex();
        $currencyId2 = Uuid::uuid4()->getHex();

        $data = [
            'id' => $id,
            'name' => 'test rule',
            'priority' => 1,
            'conditions' => [
                [
                    'type' => (new OrRule())->getName(),
                    'children' => [
                        [
                            'type' => (new CurrencyRule())->getName(),
                            'value' => [
                                'currencyIds' => [
                                    $currencyId1,
                                    $currencyId2,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->repository->create([$data], $this->context);

        $rule = $this->repository->search(new Criteria([$id]), $this->context)->get($id);
        static::assertNotNull($rule->getPayload());
        static::assertInstanceOf(Rule::class, $rule->getPayload());
        static::assertEquals(
            new AndRule([new OrRule([(new CurrencyRule())->assign(['currencyIds' => [$currencyId1, $currencyId2]])])]),
            $rule->getPayload()
        );
    }

    public function testRefreshWithMultipleRules(): void
    {
        $id = Uuid::uuid4()->getHex();
        $rule2Id = Uuid::uuid4()->getHex();
        $currencyId1 = Uuid::uuid4()->getHex();
        $currencyId2 = Uuid::uuid4()->getHex();
        $salesChannelId1 = Uuid::uuid4()->getHex();
        $salesChannelId2 = Uuid::uuid4()->getHex();

        $data = [
            [
                'id' => $id,
                'name' => 'test rule',
                'priority' => 1,
                'conditions' => [
                    [
                        'type' => (new OrRule())->getName(),
                        'children' => [
                            [
                                'type' => (new CurrencyRule())->getName(),
                                'value' => [
                                    'currencyIds' => [
                                        $currencyId1,
                                        $currencyId2,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => $rule2Id,
                'name' => 'second rule',
                'priority' => 42,
                'conditions' => [
                    [
                        'type' => (new SalesChannelRule())->getName(),
                        'value' => [
                            'salesChannelIds' => [
                                $salesChannelId1,
                                $salesChannelId2,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->repository->create($data, $this->context);

        $this->connection->update('rule', ['payload' => null, 'invalid' => '1'], ['1' => '1']);
        $rule = $this->repository->search(new Criteria([$id]), $this->context)->get($id);
        static::assertNull($rule->get('payload'));
        $this->indexer->index(new \DateTime());
        $rules = $this->repository->search(new Criteria([$id, $rule2Id]), $this->context);
        $rule = $rules->get($id);
        static::assertNotNull($rule->getPayload());
        static::assertInstanceOf(Rule::class, $rule->getPayload());
        static::assertEquals(
            new AndRule([new OrRule([(new CurrencyRule())->assign(['currencyIds' => [$currencyId1, $currencyId2]])])]),
            $rule->getPayload()
        );
        $rule = $rules->get($rule2Id);
        static::assertNotNull($rule->getPayload());
        static::assertInstanceOf(Rule::class, $rule->getPayload());
        static::assertEquals(
            new AndRule([(new SalesChannelRule())->assign(['salesChannelIds' => [$salesChannelId1, $salesChannelId2]])]),
            $rule->getPayload()
        );
    }

    public function testIndexWithMultipleRules(): void
    {
        $id = Uuid::uuid4()->getHex();
        $rule2Id = Uuid::uuid4()->getHex();
        $currencyId1 = Uuid::uuid4()->getHex();
        $currencyId2 = Uuid::uuid4()->getHex();
        $salesChannelId1 = Uuid::uuid4()->getHex();
        $salesChannelId2 = Uuid::uuid4()->getHex();

        $data = [
            [
                'id' => $id,
                'name' => 'test rule',
                'priority' => 1,
                'conditions' => [
                    [
                        'type' => (new OrRule())->getName(),
                        'children' => [
                            [
                                'type' => (new CurrencyRule())->getName(),
                                'value' => [
                                    'currencyIds' => [
                                        $currencyId1,
                                        $currencyId2,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            [
                'id' => $rule2Id,
                'name' => 'second rule',
                'priority' => 42,
                'conditions' => [
                    [
                        'type' => (new SalesChannelRule())->getName(),
                        'value' => [
                            'salesChannelIds' => [
                                $salesChannelId1,
                                $salesChannelId2,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $this->repository->create($data, $this->context);

        $rules = $this->repository->search(new Criteria([$id, $rule2Id]), $this->context);
        $rule = $rules->get($id);
        static::assertNotNull($rule->getPayload());
        static::assertInstanceOf(Rule::class, $rule->getPayload());
        static::assertEquals(
            new AndRule([new OrRule([(new CurrencyRule())->assign(['currencyIds' => [$currencyId1, $currencyId2]])])]),
            $rule->getPayload()
        );
        $rule = $rules->get($rule2Id);
        static::assertNotNull($rule->getPayload());
        static::assertInstanceOf(Rule::class, $rule->getPayload());
        static::assertEquals(
            new AndRule([(new SalesChannelRule())->assign(['salesChannelIds' => [$salesChannelId1, $salesChannelId2]])]),
            $rule->getPayload()
        );
    }

    public function testIndexWithMultipleRootConditions(): void
    {
        $id = Uuid::uuid4()->getHex();

        $data = [
            'id' => $id,
            'name' => 'test rule',
            'priority' => 1,
            'conditions' => [
                [
                    'type' => (new OrRule())->getName(),
                    'children' => [
                        [
                            'type' => (new AndRule())->getName(),
                            'children' => [
                                [
                                    'type' => (new CurrencyRule())->getName(),
                                    'value' => [
                                        'currencyIds' => [
                                            Uuid::uuid4()->getHex(),
                                            Uuid::uuid4()->getHex(),
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'type' => (new OrRule())->getName(),
                ],
            ],
        ];

        $this->repository->create([$data], $this->context);

        $this->connection->update('rule', ['payload' => null, 'invalid' => '1'], ['1' => '1']);
        $rule = $this->repository->search(new Criteria([$id]), $this->context)->get($id);
        static::assertNull($rule->get('payload'));
        $this->indexer->index(new \DateTime());
        $rule = $this->repository->search(new Criteria([$id]), $this->context)->get($id);
        static::assertNotNull($rule->getPayload());
        static::assertInstanceOf(AndRule::class, $rule->getPayload());

        static::assertCount(2, $rule->getPayload()->getRules());
        static::assertContainsOnlyInstancesOf(OrRule::class, $rule->getPayload()->getRules());
    }

    public function testIndexWithRootRuleNotAndRule(): void
    {
        $id = Uuid::uuid4()->getHex();
        $currencyId1 = Uuid::uuid4()->getHex();
        $currencyId2 = Uuid::uuid4()->getHex();

        $data = [
            'id' => $id,
            'name' => 'test rule',
            'priority' => 1,
            'conditions' => [
                [
                    'type' => (new CurrencyRule())->getName(),
                    'value' => [
                        'currencyIds' => [
                            $currencyId1,
                            $currencyId2,
                        ],
                    ],
                ],
            ],
        ];

        $this->repository->create([$data], $this->context);

        $this->connection->update('rule', ['payload' => null, 'invalid' => '1'], ['1' => '1']);
        $rule = $this->repository->search(new Criteria([$id]), $this->context)->get($id);
        static::assertNull($rule->get('payload'));
        $this->indexer->index(new \DateTime());
        $rule = $this->repository->search(new Criteria([$id]), $this->context)->get($id);
        static::assertNotNull($rule->getPayload());
        static::assertInstanceOf(Rule::class, $rule->getPayload());
        static::assertEquals(
            new AndRule([(new CurrencyRule())->assign(['currencyIds' => [$currencyId1, $currencyId2]])]),
            $rule->getPayload()
        );
    }

    public function testRefreshWithRootRuleNotAndRule(): void
    {
        $id = Uuid::uuid4()->getHex();
        $currencyId1 = Uuid::uuid4()->getHex();
        $currencyId2 = Uuid::uuid4()->getHex();

        $data = [
            'id' => $id,
            'name' => 'test rule',
            'priority' => 1,
            'conditions' => [
                [
                    'type' => (new CurrencyRule())->getName(),
                    'value' => [
                        'currencyIds' => [
                            $currencyId1,
                            $currencyId2,
                        ],
                    ],
                ],
            ],
        ];

        $this->repository->create([$data], $this->context);

        $rule = $this->repository->search(new Criteria([$id]), $this->context)->get($id);
        static::assertNotNull($rule->getPayload());
        static::assertInstanceOf(Rule::class, $rule->getPayload());
        static::assertEquals(
            new AndRule([(new CurrencyRule())->assign(['currencyIds' => [$currencyId1, $currencyId2]])]),
            $rule->getPayload()
        );
    }

    /**
     * @dataProvider dataProviderForTestPostEventNullsPayload
     */
    public function testPostEventNullsPayload(string $eventName): void
    {
        $payload = serialize(new AndRule());

        for ($i = 0; $i < 21; ++$i) {
            $this->connection->createQueryBuilder()
                ->insert('rule')
                ->values(['id' => ':id', 'name' => ':name', 'priority' => 1, 'payload' => ':payload', 'created_at' => 'NOW()'])
                ->setParameter('id', Uuid::uuid4()->getBytes())
                ->setParameter('payload', $payload)
                ->setParameter('name', 'Rule' . $i)
                ->execute();
        }

        $this->eventDispatcher->dispatch($eventName);

        $rules = $this->connection->createQueryBuilder()->select(['id', 'payload', 'invalid'])->from('rule')->execute()->fetchAll();

        foreach ($rules as $rule) {
            static::assertEquals(0, $rule['invalid']);
            static::assertNull($rule['payload']);
            static::assertNotNull($rule['id']);
        }
    }

    public function dataProviderForTestPostEventNullsPayload(): array
    {
        return [
            [PluginPostInstallEvent::NAME],
            [PluginPostActivateEvent::NAME],
            [PluginPostUpdateEvent::NAME],
            [PluginPostDeactivateEvent::NAME],
            [PluginPostUninstallEvent::NAME],
        ];
    }
}
