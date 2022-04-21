<?php

namespace Tests\Feature\Offers\Leads\TrackerTasks;

use App\Entities\TrackerTask;
use Illuminate\Support\Carbon;
use Modules\Offers\Jobs\Leads\TrackerTasks\SendTaskLeadsJob;
use Tests\Feature\App\Components\BaseTrackerTestCase;
use Tests\Traits\DummyLeadsTrait;

class SendTaskLeadsTest extends BaseTrackerTestCase
{
    use DummyLeadsTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestLeads();
    }

    public function testSuccessSendApproveLeadsTask()
    {
        $this->createApproveLeadTask();

        // Добавляется ожидание запросов к трекеру
        $this->trackerMock()
            ->expects('post')
            ->times(2)
            ->withArgs(function (string $method, array $actualParams) {
                $this->assertEquals('leads.approve', $method);

                $expectedIds = [
                    '1d1b8c27-05e3-40f7-ae50-8bc33aebe6a1', // hold -> approve
                    'cdb1615b-64a7-40b8-8c18-bbf629fd2daf', // rejected -> approve
                ];

                if (! in_array($actualParams['id'], $expectedIds)) {
                    $this->fail('Unexpected test lead id');
                }

                $expectedParams = [
                    'id'    => $actualParams['id'],
                    'force' => true,
                    'price' => 10.0,
                ];

                $this->assertArraysEqual($expectedParams, $actualParams, true);
                return true;
            });

        SendTaskLeadsJob::dispatch('ea06197b-987d-473e-ad26-2cd59838991e');
    }

    protected function createApproveLeadTask(): void
    {
        /** @var TrackerTask $task */
        $task = factory(TrackerTask::class)->create([
            'uuid'    => 'ea06197b-987d-473e-ad26-2cd59838991e',
            'status'  => TrackerTask::STATUS_PENDING,
            'action'  => 'approve',
            'type'    => 'lead',
            'ids'     => [7777, 8888, 9999],
            'params'  => ['cost' => 10.00],
            'user_id' => 100321,
        ]);

        $task->leads()->sync(array_fill_keys($task->ids, [
            'status'     => TrackerTask::STATUS_PENDING,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]));
    }

    public function testSuccessSendRejectLeadsTask()
    {
        $this->createRejectLeadTask();

        // Добавляется ожидание запросов к трекеру
        $this->trackerMock()
            ->expects('post')
            ->times(2)
            ->withArgs(function (string $method, array $actualParams) {
                $this->assertEquals('leads.reject', $method);

                $expectedIds = [
                    '1d1b8c27-05e3-40f7-ae50-8bc33aebe6a1', // hold -> reject
                    'b9541c21-5821-4730-b0dc-69cce68382b3', // approved -> reject
                ];

                if (! in_array($actualParams['id'], $expectedIds)) {
                    $this->fail('Unexpected test lead id');
                }

                $expectedParams = [
                    'id'    => $actualParams['id'],
                    'force' => true,
                ];

                $this->assertArraysEqual($expectedParams, $actualParams, true);
                return true;
            });

        SendTaskLeadsJob::dispatch('26e54564-6aed-43ce-8c1d-a5641e32dd86');
    }

    protected function createRejectLeadTask(): void
    {
        /** @var TrackerTask $task */
        $task = factory(TrackerTask::class)->create([
            'uuid'    => '26e54564-6aed-43ce-8c1d-a5641e32dd86',
            'status'  => TrackerTask::STATUS_PENDING,
            'action'  => 'reject',
            'type'    => 'lead',
            'ids'     => [7777, 8888, 9999],
            'params'  => ['comment' => 'test reject'],
            'user_id' => 100321,
        ]);

        $task->leads()->sync(array_fill_keys($task->ids, [
            'status'     => TrackerTask::STATUS_PENDING,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]));
    }

    public function updateLeadDataProvider(): array
    {
        return [
            [
                'update.fixed',
                [
                    'merchant_amount' => 200.0,
                ],
            ],
            [
                'update.percent',
                [
                    'price' => 100.0,
                ],
            ],
            [
                'update.retariffication',
                [],
            ],
        ];
    }

    /**
     * @dataProvider updateLeadDataProvider
     *
     * @param string $testAction
     * @param array  $testParams
     *
     * @return void
     */
    public function testUpdateFixedLeadTask(string $testAction, array $testParams)
    {
        $this->createFixedLeadTask($testAction, $testParams);

        // Добавляется ожидание запросов к трекеру
        $this->trackerMock()
            ->expects('post')
            ->withArgs(function (string $method, array $actualParams) use ($testParams, $testAction) {
                $this->assertEquals('leads.' . $testAction, $method);

                $this->assertEquals('cdb1615b-64a7-40b8-8c18-bbf629fd2daf', $actualParams['id']);

                $expectedParams = match ($testAction) {
                    'update.fixed' => [
                        'id'              => $actualParams['id'],
                        'merchant_amount' => $testParams['merchant_amount'],
                        'force'           => true,
                    ],
                    'update.percent' => [
                        'id'    => $actualParams['id'],
                        'price' => $testParams['price'],
                        'force' => true,
                    ],
                    'update.retariffication' => [
                        'id' => $actualParams['id'],
                    ]
                };

                $this->assertArraysEqual($expectedParams, $actualParams, true);
                return true;
            });

        SendTaskLeadsJob::dispatch('9fc4fba0-eecf-47b5-8289-2eafe2c58b85');
    }

    /**
     * @param string $action
     * @param array  $params
     *
     * @return void
     */
    protected function createFixedLeadTask(string $action, array $params)
    {
        /** @var TrackerTask $task */
        $task = factory(TrackerTask::class)->create([
            'uuid'    => '9fc4fba0-eecf-47b5-8289-2eafe2c58b85',
            'status'  => TrackerTask::STATUS_PENDING,
            'action'  => $action,
            'type'    => 'lead',
            'ids'     => [7777],
            'params'  => $params,
            'user_id' => 100321,
        ]);

        $task->leads()->sync(array_fill_keys($task->ids, [
            'status'     => TrackerTask::STATUS_PENDING,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]));
    }
}
