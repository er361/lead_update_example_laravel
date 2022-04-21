<?php

namespace Tests\Feature\Offers\Leads;

use App\Components\Tracker\Tracker;
use App\Components\UUID;
use App\Entities\Currency;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Offers\Entities\Campaign;
use Modules\Offers\Entities\Lead;
use Modules\Offers\Entities\LeadActionLog;
use Modules\Offers\Entities\LeadExternalData;
use Modules\Offers\Entities\OfferRate;
use Modules\Offers\Entities\PromoTool;
use Modules\Offers\Factories\PromoCodeFactory;
use Modules\Offers\Factories\PromoWebsiteFactory;
use Modules\Users\Entities\User;
use Tests\TestCase;
use Tests\Traits\BasicAuthVariables;
use Tests\Traits\JwtAuthHeaders;
use Tests\Traits\MocksPolicies;
use TiMacDonald\Log\LogFake;

class LeadsControllerTest extends TestCase
{
    use JwtAuthHeaders, BasicAuthVariables, MocksPolicies;

    protected Lead     $lead;
    protected Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lead = factory(Lead::class)->state('withExternalData')->create();

        /** @var OfferRate $offerRate */
        $offerRate = factory(OfferRate::class)
            ->create([
                'id'                        => 1111,
                'merchant_payment_amount'   => 100,
                'commission_payment_amount' => 10,
            ]);

        $this->campaign = factory(Campaign::class)
            ->create([
                'id'       => 2222,
                'offer_id' => $offerRate->offer->id,
            ]);
    }

    public function testViewReturns403(): void
    {
        $this->mockPolicy(Lead::class, 'view', false);

        $this
            ->actAs($this->lead->campaign->affiliate->owner)
            ->getJson(route('api.leads.show', $this->lead))
            ->assertForbidden();
    }

    public function testViewAsAffiliate(): void
    {
        $user = factory(User::class)->state(User::ROLE_AFFILIATE)->create();

        $this->mockPolicy(Lead::class, 'view', true);

        $result = $this
            ->actAs($user)
            ->getJson(route('api.leads.show', $this->lead))
            ->assertOk()
            ->json();

        $this->assertEquals($this->lead->id, $result['id']);
        $this->assertEquals($this->lead->tracker_id, $result['id_uuid']);
        $this->assertEquals($this->lead->status, $result['status']);
        $this->assertEquals($this->lead->reject_comment, $result['reject_comment']);
        $this->assertEquals($this->lead->timestamp->timestamp, $result['timestamp']);
        $this->assertEquals($this->lead->currency, $result['currency']);
        $this->assertEquals($this->lead->campaign_id, $result['campaign']['id']);
        $this->assertEquals($this->lead->offer_id, $result['offer']['id']);
        $this->assertEquals($this->lead->network_id, $result['network']['id']);

        $this->assertNotEmpty($result['external']);
        $expectedPayload = $this->lead->externalData->payload;
        $expectedUpdatedAt = Arr::pull($expectedPayload, 'updated_at');
        $actualPayload = $result['external'];
        $actualUpdatedAt = Arr::pull($actualPayload, 'updated_at');
        $this->assertEquals($expectedUpdatedAt, date('Y-m-d H:i:s', $actualUpdatedAt));
        $this->assertEquals($expectedPayload, $actualPayload);

        $this->assertEquals(formatBalanceOutput($this->lead->affiliate_profit), $result['affiliate_profit']);
        $this->assertNotContains('merchant_payment', $result);
        $this->assertNotContains('network_profit', $result);
        $this->assertNotContains('payload', $result);

        $this->lead->status = Lead::STATUS_REJECTED;
        $this->lead->save();

        $result = $this
            ->actAs($user)
            ->getJson(route('api.leads.show', $this->lead))
            ->assertOk()
            ->json();

        $this->assertEquals($this->lead->payload, $result['payload']);
    }

    public function testViewAsMerchant(): void
    {
        $user = factory(User::class)->state(User::ROLE_MERCHANT)->create();

        $this->mockPolicy(Lead::class, 'view', true);

        $result = $this
            ->actAs($user)
            ->getJson(route('api.leads.show', $this->lead))
            ->assertOk()
            ->json();

        $this->assertEquals($this->lead->id, $result['id']);
        $this->assertEquals($this->lead->tracker_id, $result['id_uuid']);
        $this->assertEquals($this->lead->status, $result['status']);
        $this->assertEquals($this->lead->reject_comment, $result['reject_comment']);
        $this->assertEquals($this->lead->timestamp->timestamp, $result['timestamp']);
        $this->assertEquals($this->lead->payload, $result['payload']);
        $this->assertEquals($this->lead->currency, $result['currency']);

        $this->assertNotEmpty($result['external']);
        $expectedPayload = $this->lead->externalData->payload;
        $expectedUpdatedAt = Arr::pull($expectedPayload, 'updated_at');
        $actualPayload = $result['external'];
        $actualUpdatedAt = Arr::pull($actualPayload, 'updated_at');
        $this->assertEquals($expectedUpdatedAt, date('Y-m-d H:i:s', $actualUpdatedAt));
        $this->assertEquals($expectedPayload, $actualPayload);

        $this->assertEquals(formatBalanceOutput($this->lead->merchant_payment), $result['merchant_payment']);
        $this->assertNotContains('affiliate_profit', $result);
        $this->assertNotContains('network_profit', $result);

        $this->assertEquals($this->lead->offer_id, $result['offer']['id']);
        $this->assertEquals($this->lead->network_id, $result['network']['id']);
    }

    public function testViewAsNetwork(): void
    {
        $this->mockPolicy(Lead::class, 'view', true);

        $results = [];

        $user = factory(User::class)->state(User::ROLE_NETWORK)->create();

        $results[] = $this
            ->actAs($user)
            ->getJson(route('api.leads.show', $this->lead))
            ->assertOk()
            ->json();

        $user = factory(User::class)->state(User::ROLE_MANAGER_ADMIN)->create();

        $results[] = $this
            ->actAs($user)
            ->getJson(route('api.leads.show', $this->lead))
            ->assertOk()
            ->json();

        foreach ($results as $result) {
            $this->assertEquals($this->lead->id, $result['id']);
            $this->assertEquals($this->lead->tracker_id, $result['id_uuid']);
            $this->assertEquals($this->lead->status, $result['status']);
            $this->assertEquals($this->lead->reject_comment, $result['reject_comment']);
            $this->assertEquals($this->lead->timestamp->timestamp, $result['timestamp']);
            $this->assertEquals($this->lead->payload, $result['payload']);
            $this->assertEquals($this->lead->currency, $result['currency']);

            $this->assertEquals($this->lead->campaign_id, $result['campaign']['id']);
            $this->assertEquals($this->lead->offer_id, $result['offer']['id']);
            $this->assertEquals($this->lead->network_id, $result['network']['id']);
            $this->assertEquals($this->lead->affiliate_id, $result['affiliate']['id']);

            $this->assertNotEmpty($result['external']);
            $expectedPayload = $this->lead->externalData->payload;
            $expectedUpdatedAt = Arr::pull($expectedPayload, 'updated_at');
            $actualPayload = $result['external'];
            $actualUpdatedAt = Arr::pull($actualPayload, 'updated_at');
            $this->assertEquals($expectedUpdatedAt, date('Y-m-d H:i:s', $actualUpdatedAt));
            $this->assertEquals($expectedPayload, $actualPayload);

            $this->assertEquals(formatBalanceOutput($this->lead->merchant_payment), $result['merchant_payment']);
            $this->assertEquals(formatBalanceOutput($this->lead->affiliate_profit), $result['affiliate_profit']);
            $this->assertEquals(formatBalanceOutput($this->lead->network_profit), $result['network_profit']);
        }
    }

    public function testHistory(): void
    {
        factory(LeadActionLog::class, 5)->create(['lead_id' => $this->lead->id]);

        $user = factory(User::class)->state(User::ROLE_MERCHANT)->create();

        $this
            ->actAs($user)
            ->getJson(route('api.leads.history', $this->lead))
            ->assertForbidden();

        $result = $this
            ->actAs($this->lead->affiliate->owner)
            ->getJson(route('api.leads.history', $this->lead))
            ->assertOk()
            ->json();

        $this->assertCount(5, $result);
    }

    public function postbackDataProvider(): array
    {
        return [
            [
                '$commonParams'       => [
                    'id'               => UUID::v4(),
                    'status'           => Lead::STATUS_HOLD,
                    'meta'             => [
                        'price' => 1000,
                    ],
                    'network_amount'   => 1000,
                    'affiliate_amount' => 9000,
                    'merchant_amount'  => 10000,
                    'currency'         => Currency::US_DOLLAR,
                    'warn_message'     => 'you have an error',
                    'click_id'         => UUID::v4(),
                    'session_id'       => UUID::v4(),
                    'client_id'        => UUID::v4(),
                    'request_id'       => '15',
                    'timestamp'        => now()->toFormattedDateString(),
                    'customer_id'      => '123456789|123456789|1234',
                    'rate_template'    => "{\"user_phone (phone)\":\"681719695316\"}",
                ],
                '$externalParams'     => [
                    'ip'           => '136.91.216.181',
                    'referrer'     => 'https://dummy.net',
                    'utm_campaign' => '7777',
                    'utm_content'  => '0000',
                    'utm_source'   => '8888',
                    'utm_medium'   => '0909',
                    'utm_term'     => '9999',
                    'subid1'       => '1111',
                    'subid2'       => '2222',
                    'subid3'       => '3333',
                    'subid4'       => '4444',
                    'subid5'       => '5555',
                    'subid6'       => '6666',
                ],
                '$existentLeadParams' => [],
                '$expected'           => [
                    'status'           => Lead::STATUS_HOLD,
                    'meta'             => "{\"price\":1000}",
                    'network_amount'   => 1000,
                    'affiliate_amount' => 9000,
                    'merchant_amount'  => 10000,
                    'warn_message'     => 'you have an error',
                    'customer_id'      => '123456789|123456789|1234',
                    'rate_template'    => '{"user_phone (phone)": "681719695316"}',
                ],
            ],
            [
                '$commonParams'       => [
                    'id'               => UUID::v4(),
                    'status'           => Lead::STATUS_REJECTED,
                    'meta'             => null,
                    'network_amount'   => 1000,
                    'affiliate_amount' => 9000,
                    'merchant_amount'  => 10000,
                    'currency'         => Currency::US_DOLLAR,
                    'warn_message'     => null,
                    'click_id'         => UUID::v4(),
                    'session_id'       => UUID::v4(),
                    'client_id'        => UUID::v4(),
                    'request_id'       => '15',
                    'timestamp'        => now()->toFormattedDateString(),
                    'customer_id'      => null,
                    'rate_template'    => "{}",
                ],
                '$externalParams'     => [],
                '$existentLeadParams' => [],
                '$expected'           => [
                    'status'           => Lead::STATUS_REJECTED,
                    'meta'             => null,
                    'network_amount'   => 1000,
                    'affiliate_amount' => 9000,
                    'merchant_amount'  => 10000,
                    'warn_message'     => null,
                    'customer_id'      => null,
                    'rate_template'    => '{}',
                ],
            ],
            [
                '$commonParams'       => [
                    'id'               => UUID::v4(),
                    'status'           => Lead::STATUS_APPROVED,
                    'meta'             => null,
                    'network_amount'   => 1000,
                    'affiliate_amount' => 9000,
                    'merchant_amount'  => 10000,
                    'currency'         => Currency::US_DOLLAR,
                    'warn_message'     => null,
                    'click_id'         => null,
                    'session_id'       => UUID::v4(),
                    'client_id'        => null,
                    'request_id'       => '15',
                    'timestamp'        => now()->toFormattedDateString(),
                    'rate_template'    => "{\"user_phone (phone)\":\"681719695316\"}",
                ],
                '$externalParams'     => [],
                '$existentLeadParams' => [],
                '$expected'           => [
                    'status'           => Lead::STATUS_APPROVED,
                    'meta'             => null,
                    'network_amount'   => 1000,
                    'affiliate_amount' => 9000,
                    'merchant_amount'  => 10000,
                    'warn_message'     => null,
                    'customer_id'      => null,
                    'rate_template'    => '{"user_phone (phone)": "681719695316"}',
                ],
            ],
            [
                '$commonParams'       => [
                    'id'               => UUID::v4(),
                    'status'           => Lead::STATUS_HOLD,
                    'meta'             => [
                        'test' => 'hold',
                    ],
                    'network_amount'   => 1000,
                    'affiliate_amount' => 9000,
                    'merchant_amount'  => 10000,
                    'currency'         => Currency::US_DOLLAR,
                    'warn_message'     => 'hold message',
                    'click_id'         => UUID::v4(),
                    'session_id'       => UUID::v4(),
                    'client_id'        => UUID::v4(),
                    'request_id'       => '15',
                    'timestamp'        => now()->toFormattedDateString(),
                    'customer_id'      => '1234|123456789|123456789',
                    'rate_template'    => '{"user_phone (phone)":"12345678"}',
                ],
                '$externalParams'     => [],
                '$existentLeadParams' => [
                    'status'           => Lead::STATUS_APPROVED,
                    'meta'             => [
                        'test' => 'approved',
                    ],
                    'network_amount'   => 100,
                    'affiliate_amount' => 900,
                    'merchant_amount'  => 1000,
                    'warn_message'     => 'approved message',
                    'customer_id'      => '123456789|123456789|1234',
                    'rate_template'    => '{"user_phone (phone)": "681719695316"}',
                ],
                '$expected'           => [
                    'status'           => Lead::STATUS_APPROVED,
                    'meta'             => "{\"test\":\"approved\"}",
                    'network_amount'   => 100,
                    'affiliate_amount' => 900,
                    'merchant_amount'  => 1000,
                    'warn_message'     => 'approved message',
                    'customer_id'      => '123456789|123456789|1234',
                    'rate_template'    => '{"user_phone (phone)": "681719695316"}',
                ],
            ],
            [
                '$commonParams'       => [
                    'id'               => UUID::v4(),
                    'status'           => Lead::STATUS_REJECTED,
                    'meta'             => [
                        'test' => 'rejected',
                    ],
                    'network_amount'   => 1000,
                    'affiliate_amount' => 9000,
                    'merchant_amount'  => 10000,
                    'currency'         => Currency::US_DOLLAR,
                    'warn_message'     => 'rejected',
                    'click_id'         => UUID::v4(),
                    'session_id'       => UUID::v4(),
                    'client_id'        => UUID::v4(),
                    'request_id'       => '15',
                    'timestamp'        => now()->toFormattedDateString(),
                    'customer_id'      => null,
                    'rate_template'    => "{'user_phone (phone)':'12345678'}",
                ],
                '$externalParams'     => [],
                '$existentLeadParams' => [
                    'status'           => Lead::STATUS_APPROVED,
                    'meta'             => [
                        'test' => 'approved',
                    ],
                    'network_amount'   => 100,
                    'affiliate_amount' => 900,
                    'merchant_amount'  => 1000,
                    'warn_message'     => 'approved',
                    'customer_id'      => '123456789|123456789|1234',
                    'rate_template'    => '{"user_phone (phone)": "681719695316"}',
                ],
                '$expected'           => [
                    'status'           => Lead::STATUS_REJECTED,
                    'meta'             => "{\"test\":\"approved\"}",
                    'network_amount'   => 100,
                    'affiliate_amount' => 900,
                    'merchant_amount'  => 1000,
                    'warn_message'     => 'approved',
                    'customer_id'      => '123456789|123456789|1234',
                    'rate_template'    => '{"user_phone (phone)": "681719695316"}',
                ],
            ],
            [
                '$commonParams'       => [
                    'id'               => UUID::v4(),
                    'status'           => Lead::STATUS_HOLD,
                    'meta'             => [
                        'test' => 'hold',
                    ],
                    'network_amount'   => 1000,
                    'affiliate_amount' => 9000,
                    'merchant_amount'  => 10000,
                    'currency'         => Currency::US_DOLLAR,
                    'warn_message'     => 'hold',
                    'click_id'         => UUID::v4(),
                    'session_id'       => UUID::v4(),
                    'client_id'        => UUID::v4(),
                    'request_id'       => '15',
                    'timestamp'        => now()->toFormattedDateString(),
                    'rate_template'    => "{\"user_phone (phone)\":\"12345678\"}",
                ],
                '$externalParams'     => [],
                '$existentLeadParams' => [
                    'status'           => Lead::STATUS_REJECTED,
                    'meta'             => [
                        'test' => 'rejected',
                    ],
                    'network_amount'   => 100,
                    'affiliate_amount' => 900,
                    'merchant_amount'  => 1000,
                    'warn_message'     => 'rejected',
                    'customer_id'      => '123456789|123456789|1234',
                    'rate_template'    => '{"user_phone (phone)": "681719695316"}',
                ],
                '$expected'           => [
                    'status'           => Lead::STATUS_REJECTED,
                    'meta'             => "{\"test\":\"rejected\"}",
                    'network_amount'   => 100,
                    'affiliate_amount' => 900,
                    'merchant_amount'  => 1000,
                    'warn_message'     => 'rejected',
                    'customer_id'      => '123456789|123456789|1234',
                    'rate_template'    => '{"user_phone (phone)": "681719695316"}',
                ],
            ],
            [
                '$commonParams'       => [
                    'id'               => UUID::v4(),
                    'status'           => Lead::STATUS_APPROVED,
                    'meta'             => [
                        'test' => 'approved',
                    ],
                    'price'            => 10000, // new payment
                    'network_amount'   => 1500,
                    'affiliate_amount' => 9500,
                    'merchant_amount'  => 11000,
                    'currency'         => Currency::US_DOLLAR,
                    'warn_message'     => 'approved',
                    'click_id'         => UUID::v4(),
                    'session_id'       => UUID::v4(),
                    'client_id'        => UUID::v4(),
                    'request_id'       => '15',
                    'timestamp'        => now()->toFormattedDateString(),
                    'customer_id'      => null,
                    'rate_template'    => "{\"user_phone (phone)\":\"12345678\"}",
                ],
                '$externalParams'     => [],
                '$existentLeadParams' => [
                    'status'           => Lead::STATUS_HOLD,
                    'meta'             => [
                        'test' => 'hold',
                    ],
                    'network_amount'   => 123,
                    'affiliate_amount' => 1111,
                    'merchant_amount'  => 1234,
                    'warn_message'     => 'hold',
                    'customer_id'      => null,
                    'rate_template'    => '{"user_phone (phone)": "681719695316"}',
                ],
                '$expected'           => [
                    'status'           => Lead::STATUS_APPROVED,
                    'meta'             => "{\"test\":\"approved\"}",
                    'network_amount'   => 1000,
                    'affiliate_amount' => 9000,
                    'merchant_amount'  => 10000,
                    'warn_message'     => 'approved',
                    'customer_id'      => null,
                    'rate_template'    => '{"user_phone (phone)": "681719695316"}',
                ],
            ],
            [
                '$commonParams'       => [
                    'id'               => UUID::v4(),
                    'status'           => Lead::STATUS_REJECTED,
                    'meta'             => null,
                    'network_amount'   => 1000,
                    'affiliate_amount' => 9000,
                    'merchant_amount'  => 10000,
                    'currency'         => Currency::US_DOLLAR,
                    'warn_message'     => null,
                    'click_id'         => UUID::v4(),
                    'session_id'       => UUID::v4(),
                    'client_id'        => UUID::v4(),
                    'request_id'       => '15',
                    'timestamp'        => now()->toFormattedDateString(),
                    'rate_template'    => "{\"user_phone (phone)\":\"12345678\"}",
                ],
                '$externalParams'     => [],
                '$existentLeadParams' => [
                    'status'           => Lead::STATUS_HOLD,
                    'meta'             => [
                        'test' => 'hold',
                    ],
                    'network_amount'   => 1000,
                    'affiliate_amount' => 9000,
                    'merchant_amount'  => 10000,
                    'warn_message'     => 'hold',
                    'customer_id'      => null,
                    'rate_template'    => '{"user_phone (phone)": "681719695316"}',
                ],
                '$expected'           => [
                    'status'           => Lead::STATUS_REJECTED,
                    'meta'             => null,
                    'network_amount'   => 1000,
                    'affiliate_amount' => 9000,
                    'merchant_amount'  => 10000,
                    'warn_message'     => null,
                    'customer_id'      => null,
                    'rate_template'    => '{"user_phone (phone)": "681719695316"}',
                ],
            ],
        ];
    }

    /**
     * @dataProvider postbackDataProvider
     *
     * @param array $commonParams
     * @param array $externalParams
     * @param array $existentLeadParams
     * @param array $expected
     */
    public function testPostback(
        array $commonParams,
        array $externalParams,
        array $existentLeadParams,
        array $expected,
    ): void {
        Queue::fake();
        Log::swap(new LogFake());

        /** @var Campaign $campaign */
        $campaign = factory(Campaign::class)->create();

        /** @var OfferRate $offerRate */
        $offerRate = factory(OfferRate::class)->create([
            'offer_id'                  => $campaign->offer_id,
            'merchant_payment_type'     => OfferRate::PAYMENT_TYPE_PERCENT,
            'merchant_payment_amount'   => 100,
            'commission_payment_amount' => 10,
        ]);

        /** @var PromoTool $promoTool */
        $promoTool = factory(PromoTool::class)->create([
            PromoTool::MORPH_TYPE_FIELD => PromoTool::TYPE_WEBSITE,
            PromoTool::MORPH_ID_FIELD   => PromoWebsiteFactory::new()->create(),
            'offer_id'                  => $campaign->offer_id,
        ]);

        $promocode = PromoCodeFactory::new()->withPromoTool()->create();

        $params = array_merge($commonParams, $externalParams, [
            'network_id'   => $campaign->offer->network_id,
            'offer_id'     => $campaign->offer_id,
            'ad_id'        => $campaign->id,
            'tariff_id'    => $offerRate->id,
            'promo_id'     => $promoTool->id,
            'affiliate'    => $campaign->affiliate->owner_id,
            'merchant'     => $campaign->offer->merchant->owner_id,
            'promocode_id' => $promocode->promoTool->id,
        ]);

        if ($existentLeadParams) {
            $this
                ->actAsTracker()
                ->postJson(route('api.leads.postback'), array_merge($params, $existentLeadParams))
                ->assertOk()
                ->json();
        }

        $response = $this
            ->actAsTracker()
            ->postJson(route('api.leads.postback'), $params)
            ->assertOk()
            ->json();

        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertTrue($response['success']);

        Log::channel(Tracker::LOG_CHANNEL)
            ->assertLogged('info', function ($message, $context) use ($params) {
                return Str::contains($message, 'api.leads.postback')
                       && Arr::get($context, 'REQUEST.id') === $params['id']
                       && Arr::get($context, 'RESPONSE.success') === true;
            });


        $this->assertDatabaseHas(Lead::TABLE, [
            'campaign_id' => $campaign->id,
            'tracker_id'  => $params['id'],
        ]);

        $lead = Lead::firstWhere('tracker_id', '=', $params['id']);

        if ($externalParams) {
            $this->assertDatabaseHas(LeadExternalData::TABLE, ['lead_id' => $lead->id]);

            $externalDataPayload = $lead->externalData->payload;
            $this->assertCount(13, $externalDataPayload);
            $this->assertArraysEqual($externalParams, $externalDataPayload);
        }

        $this->checkActionLogs($lead, $params, $existentLeadParams);

        $this->assertEquals($expected['status'], $lead->status);
        $this->assertEquals($expected['merchant_amount'], formatBalanceOutput($lead->merchant_payment));
        $this->assertEquals($expected['affiliate_amount'], formatBalanceOutput($lead->affiliate_profit));
        $this->assertEquals($expected['network_amount'], formatBalanceOutput($lead->network_profit));
        $this->assertEquals($expected['meta'], $lead->payload);
        $this->assertEquals($expected['warn_message'], $lead->notice);
        $this->assertEquals($expected['customer_id'], $lead->customer_id);
        $this->assertEquals($expected['rate_template'], $lead->template_data);

        if ($expected['status'] === Lead::STATUS_HOLD) {
            $this->assertNull($lead->terminated_at);
        } else {
            $this->assertNotNull($lead->terminated_at);
        }
    }

    protected function checkActionLogs(Lead $lead, array $params, array $existentLeadParams): void
    {
        if ($existentLeadParams && $existentLeadParams['status'] === Lead::STATUS_HOLD) {
            $this->checkChangeCostActionLogs($lead, $params, $existentLeadParams);
            $this->checkStatusActionLogs($lead, $params, $existentLeadParams);
        }
    }

    protected function checkChangeCostActionLogs(Lead $lead, array $params, array $existentLeadParams): void
    {
        if ($existentLeadParams['merchant_amount'] !== $params['merchant_amount']) {
            $log = $lead->actionsLog->where('event', LeadActionLog::EVENT_COST_CHANGED)->first();
            $this->assertActionLog(
                $log,
                $lead,
                $params,
                $existentLeadParams,
                'Auto changing cost by postback'
            );
        }
    }

    protected function checkStatusActionLogs(Lead $lead, array $params, array $existentLeadParams): void
    {
        switch ($params['status']) {
            case Lead::STATUS_APPROVED:
                $log = $lead->actionsLog->where('event', LeadActionLog::EVENT_APPROVE)->first();
                $this->assertActionLog($log, $lead, $params, $existentLeadParams);
                break;

            case Lead::STATUS_REJECTED:
                $log = $lead->actionsLog->where('event', LeadActionLog::EVENT_REJECT)->first();
                $this->assertActionLog(
                    $log,
                    $lead,
                    $params,
                    $existentLeadParams,
                    'Auto rejecting lead by postback'
                );
                break;
        }
    }

    protected function assertActionLog(
        LeadActionLog $log,
        Lead $lead,
        array $params,
        array $existentLeadParams,
        ?string $comment = null
    ): void {
        $this->assertEquals($lead->merchant->owner_id, $log->user_id);
        $this->assertEquals($lead->id, $log->lead_id);

        if ($comment) {
            $this->assertEquals($comment, $log->comment);
        }

        switch ($log->event) {
            case LeadActionLog::EVENT_COST_CHANGED:
                $this->assertEquals($existentLeadParams['merchant_amount'], $log->data['payment_before']);
                $this->assertEquals($params['price'] ?? $params['merchant_amount'], $log->data['payment_after']);
                break;

            case LeadActionLog::EVENT_APPROVE:
            case LeadActionLog::EVENT_REJECT:
                $this->assertEquals($existentLeadParams['status'], $log->data['status_before']);
                $this->assertEquals($params['status'], $log->data['status_after']);
                break;
        }
    }

    public function testPostbackAuthenticationError(): void
    {
        Log::swap(new LogFake());

        $response = $this
            ->postJson(route('api.leads.postback'), [])
            ->assertStatus(401)
            ->assertHeader('WWW-Authenticate', 'Basic')
            ->json();

        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertFalse($response['success']);

        $this->assertEmpty($this->campaign->leads()->count());

        Log::channel(Tracker::LOG_CHANNEL)
            ->assertLogged('error', function ($message, $context) {
                return Str::contains($message, 'api.leads.postback')
                       && Str::contains($message, 'Request authentication error')
                       && Arr::get($context, 'RESPONSE.success') === false;
            });
    }

    public function postbackAuthorizationErrorDataProvider(): array
    {
        return [
            [
                '',
                '',
            ],
            [
                '',
                'TGAedEeD5kJs9JyxEszs',
            ],
            [
                'fake',
                'TGAedEeD5kJs9JyxEszs',
            ],
            [
                'tracker@hoqu.com',
                '',
            ],
            [
                'tracker@hoqu.com',
                'fake',
            ],
            [
                'fake',
                'fake',
            ],
        ];
    }

    /**
     * @dataProvider postbackAuthorizationErrorDataProvider
     *
     * @param string|null $user
     * @param string|null $password
     */
    public function testPostbackAuthorizationError(?string $user, ?string $password): void
    {
        Log::swap(new LogFake());

        $response = $this
            ->actWithAuthBasic($user, $password)
            ->postJson(route('api.leads.postback'), [])
            ->assertForbidden()
            ->json();

        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertFalse($response['success']);

        $this->assertEmpty($this->campaign->leads()->count());

        Log::channel(Tracker::LOG_CHANNEL)
            ->assertLogged('error', function ($message, $context) {
                return Str::contains($message, 'api.leads.postback')
                       && Str::contains($message, 'Request authorization error')
                       && Arr::get($context, 'RESPONSE.success') === false;
            });
    }

    public function testPostbackMediaTypeError(): void
    {
        Log::swap(new LogFake());

        $response = $this
            ->actAsTracker()
            ->post(route('api.leads.postback'), [])
            ->assertStatus(415)
            ->json();

        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertFalse($response['success']);

        $this->assertEmpty($this->campaign->leads()->count());

        Log::channel(Tracker::LOG_CHANNEL)
            ->assertLogged('error', function ($message, $context) {
                return Str::contains($message, 'api.leads.postback')
                       && Str::contains($message, 'Request media type error')
                       && Arr::get($context, 'RESPONSE.success') === false;
            });
    }

    public function postbackValidationErrorDataProvider(): array
    {
        return [
            [
                [
                    'id'               => false,
                    'ad_id'            => false,
                    'tariff_id'        => false,
                    'timestamp'        => false,
                    'meta'             => false,
                    'network_amount'   => false,
                    'affiliate_amount' => false,
                    'merchant_amount'  => false,
                ],
                [
                    'id',
                    'ad_id',
                    'tariff_id',
                    'timestamp',
                    'network_amount',
                    'affiliate_amount',
                    'merchant_amount',
                ],
            ],
            [
                [],
                [
                    'id',
                    'ad_id',
                    'tariff_id',
                    'timestamp',
                ],
            ],
            [
                [
                    'id'        => 3000,
                    'ad_id'     => 2000,
                    'tariff_id' => 1000,
                    'timestamp' => now()->timestamp,
                    'meta'      => ['price' => 1000],
                ],
                [
                    'id',
                    'ad_id',
                    'tariff_id',
                ],
            ],
        ];
    }

    /**
     * @dataProvider postbackValidationErrorDataProvider
     *
     * @param array $params
     * @param array $expectedErrorFields
     */
    public function testPostbackValidationError(array $params, array $expectedErrorFields): void
    {
        Log::swap(new LogFake());

        factory(Lead::class)
            ->create([
                'campaign_id'   => 2222,
                'offer_rate_id' => 1111,
            ]);

        $this->assertEquals(1, $this->campaign->leads()->count());

        $response = $this
            ->actAsTracker()
            ->postJson(route('api.leads.postback'), $params)
            ->assertStatus(422)
            ->json();

        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertFalse($response['success']);

        $this->assertArrayHasKey('errors', $response);
        foreach ($expectedErrorFields as $expectedError) {
            $this->assertArrayHasKey($expectedError, $response['errors']);
        }

        $this->assertEquals(1, $this->campaign->leads()->count());

        Log::channel(Tracker::LOG_CHANNEL)
            ->assertLogged('error', function ($message, $context) {
                return Str::contains($message, 'api.leads.postback')
                       && Str::contains($message, 'Request validation error')
                       && Arr::get($context, 'RESPONSE.success') === false;
            });
    }
}
