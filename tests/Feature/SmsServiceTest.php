<?php

namespace Tests\Feature;

use App\Enums\ClientSource;
use App\Enums\InstalmentStatus;
use App\Models\Activity;
use App\Models\Client;
use App\Models\Instalment;
use App\Models\Lead;
use App\Models\Matter;
use App\Models\PaymentPlan;
use App\Services\Sms\TwilioSmsClient;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * TwilioSmsClient is always mocked here — never the real Twilio SDK client
 * — so none of this fires a real SMS. AppServiceProvider additionally binds
 * a throwing fake for TwilioSmsClient whenever running unit tests, as a
 * second line of defence: any test that forgets to mock it here would fail
 * loudly with a clear message instead of silently reaching Twilio's real API
 * with blank test-env credentials.
 */
class SmsServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    private function instalment(): Instalment
    {
        $this->setUpTenant();

        $client = Client::create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone' => '+447700900123',
            'source' => ClientSource::Phone,
        ]);

        $matter = Matter::create([
            'client_id' => $client->id,
            'practice_area' => 'Family',
            'status' => 'active',
        ]);

        $paymentPlan = PaymentPlan::create([
            'matter_id' => $matter->id,
            'total_amount' => 500,
            'deposit_amount' => 0,
        ]);

        return Instalment::create([
            'payment_plan_id' => $paymentPlan->id,
            'amount' => 100,
            'due_date' => now()->subDays(20),
            'status' => InstalmentStatus::Overdue,
        ]);
    }

    private function lead(): Lead
    {
        $this->setUpTenant();

        return Lead::create([
            'first_name' => 'Sam',
            'last_name' => 'Client',
            'email' => 'sam@example.com',
            'telephone' => '+447700900456',
            'source' => 'web',
            'practice_area' => 'Conveyancing',
            'message' => 'Interested in a quote.',
            'status' => 'new',
        ]);
    }

    public function test_an_unmocked_send_fails_loudly_instead_of_risking_a_real_network_call(): void
    {
        $this->setUpTenant();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unmocked during a test');

        app(SmsService::class)->send('+447700900123', 'hello there');
    }

    public function test_a_successful_send_calls_twilio_with_the_configured_from_number_and_logs_it(): void
    {
        config(['services.twilio.from_number' => '+15551234567']);

        $this->mock(TwilioSmsClient::class)
            ->shouldReceive('send')
            ->once()
            ->with('+447700900123', '+15551234567', 'hello there')
            ->andReturn('SM1234567890');

        $this->setUpTenant();

        app(SmsService::class)->send('+447700900123', 'hello there');

        $activity = Activity::query()->inLog('sms')->forEvent('sent')->sole();

        $this->assertSame('+447700900123', $activity->getExtraProperty('to'));
        $this->assertSame('hello there', $activity->getExtraProperty('message'));
        $this->assertSame('SM1234567890', $activity->getExtraProperty('sid'));
        $this->assertSame($this->tenant->id, $activity->tenant_id);
    }

    public function test_a_failed_send_is_logged_and_rethrown(): void
    {
        $this->mock(TwilioSmsClient::class)
            ->shouldReceive('send')
            ->once()
            ->andThrow(new RuntimeException('The number +447700900123 is not a valid phone number.'));

        $this->setUpTenant();

        $this->expectException(RuntimeException::class);

        try {
            app(SmsService::class)->send('+447700900123', 'hello there');
        } finally {
            $activity = Activity::query()->inLog('sms')->forEvent('failed')->sole();

            $this->assertSame('+447700900123', $activity->getExtraProperty('to'));
            $this->assertStringContainsString('not a valid phone number', $activity->getExtraProperty('error'));
            $this->assertNull($activity->getExtraProperty('sid'));
        }
    }

    public function test_chase_step_1_sends_to_the_clients_phone_and_logs_against_the_instalment(): void
    {
        $instalment = $this->instalment();

        $this->mock(TwilioSmsClient::class)
            ->shouldReceive('send')
            ->once()
            ->with('+447700900123', \Mockery::any(), \Mockery::on(fn (string $body) => str_contains($body, 'Jane') && str_contains($body, $instalment->paymentPlan->matter->reference)))
            ->andReturn('SM1');

        app(SmsService::class)->chaseStep1($instalment);

        $activity = Activity::query()->inLog('sms')->forEvent('sent')->forSubject($instalment)->sole();
        $this->assertSame($this->tenant->id, $activity->tenant_id);
    }

    public function test_chase_step_2_sends_an_overdue_warning(): void
    {
        $instalment = $this->instalment();

        $this->mock(TwilioSmsClient::class)
            ->shouldReceive('send')
            ->once()
            ->with('+447700900123', \Mockery::any(), \Mockery::on(fn (string $body) => str_contains($body, 'urgently')))
            ->andReturn('SM2');

        app(SmsService::class)->chaseStep2($instalment);

        Activity::query()->inLog('sms')->forEvent('sent')->forSubject($instalment)->sole();
        $this->assertTrue(true);
    }

    public function test_nurture_step_1_prefers_mobile_over_telephone_and_logs_against_the_lead(): void
    {
        $lead = $this->lead();
        $lead->update(['mobile' => '+447700900789']);

        $this->mock(TwilioSmsClient::class)
            ->shouldReceive('send')
            ->once()
            ->with('+447700900789', \Mockery::any(), \Mockery::on(fn (string $body) => str_contains($body, 'Sam') && str_contains($body, 'Conveyancing')))
            ->andReturn('SM3');

        app(SmsService::class)->nurtureStep1($lead);

        Activity::query()->inLog('sms')->forEvent('sent')->forSubject($lead)->sole();
        $this->assertTrue(true);
    }

    public function test_portal_invite_includes_the_signed_url_and_logs_against_the_matter(): void
    {
        $this->setUpTenant();

        $client = Client::create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@example.com',
            'phone' => '+447700900123',
            'source' => ClientSource::Phone,
        ]);

        $matter = Matter::create([
            'client_id' => $client->id,
            'practice_area' => 'Family',
            'status' => 'active',
        ]);

        $this->mock(TwilioSmsClient::class)
            ->shouldReceive('send')
            ->once()
            ->with('+447700900123', \Mockery::any(), \Mockery::on(fn (string $body) => str_contains($body, 'https://example.com/set-password')))
            ->andReturn('SM4');

        app(SmsService::class)->portalInvite($client, $matter, 'https://example.com/set-password');

        $activity = Activity::query()->inLog('sms')->forEvent('sent')->forSubject($matter)->sole();
        $this->assertSame($this->tenant->id, $activity->tenant_id);
    }
}
