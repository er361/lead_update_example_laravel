<?php

namespace Tests\Feature\Offers\Leads\LeadActions;

use Mockery\MockInterface;
use Modules\Offers\Actions\Leads\UpdateLeadTrackerTaskCreateAction;
use Modules\Offers\Entities\Lead;
use Modules\Users\Entities\User;
use Tests\TestCase;
use Tests\Traits\DummyLeadsTrait;
use Tests\Traits\JwtAuthHeaders;
use Tests\Traits\MocksPolicies;

class LeadsUpdateControllerTest extends TestCase
{
    use JwtAuthHeaders, MocksPolicies, DummyLeadsTrait;

    protected MockInterface $updateLeadTrackerTaskCreateActionMock;

    public function updateLeadDataProvider(): array
    {
        return [
            //#0retatif
            [
                [
                    'update_type' => 'retariffication',
                ],
                204,
            ],
            //#1fixed
            [
                [
                    'update_type'     => 'fixed',
                    'merchant_amount' => 200.0,
                ],
                204,
            ],
            //#2percent
            [
                [
                    'update_type' => 'percent',
                    'price'       => 200.0,
                ],
                204,
            ],
            //#3
            [
                [
                    'update_type' => 'wrong type',
                ],
                422,
            ],
        ];
    }

    /**
     * @dataProvider updateLeadDataProvider
     *
     * @param array $params
     * @param int   $status
     *
     * @return void
     */
    public function testUpdate(array $params, int $status): void
    {
        $this->updateLeadTrackerTaskCreateActionMock
            ->shouldReceive('execute');

        $this->mockPolicy(Lead::class, 'update', true);

        $user = User::findOrFail(100321);

        $this
            ->actAs($user)
            ->putJson($this->getUrl(8888), $params)
            ->assertStatus($status);
    }

    protected function getUrl(int $leadId): string
    {
        return route('api.leads.update-lead', $leadId);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTestLeads();

        $this->updateLeadTrackerTaskCreateActionMock = $this->mock(UpdateLeadTrackerTaskCreateAction::class);
    }
}
