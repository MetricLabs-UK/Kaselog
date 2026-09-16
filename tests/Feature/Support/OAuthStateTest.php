<?php

namespace Tests\Feature\Support;

use App\Support\Accounting\OAuthState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\SetsUpTenant;
use Tests\TestCase;

/**
 * Section 20 — a provider's OAuth callback hits one shared route regardless
 * of which firm started the flow; this is how it still finds its way back
 * to the right tenant, encrypted so it can't be tampered with to target a
 * different one.
 */
class OAuthStateTest extends TestCase
{
    use RefreshDatabase, SetsUpTenant;

    public function test_a_generated_state_verifies_back_to_the_same_tenant_and_user(): void
    {
        $this->setUpTenant();
        $director = $this->actingAsRole('director');

        $state = OAuthState::generate($this->tenant, $director);
        $payload = OAuthState::verify($state, $director);

        $this->assertSame($this->tenant->id, $payload['tenant_id']);
        $this->assertSame($director->id, $payload['user_id']);
    }

    public function test_verifying_with_a_different_user_fails(): void
    {
        $this->setUpTenant();
        $director = $this->actingAsRole('director');
        $otherUser = $this->actingAsRole('solicitor');

        $state = OAuthState::generate($this->tenant, $director);

        $this->expectException(RuntimeException::class);
        OAuthState::verify($state, $otherUser);
    }

    public function test_an_expired_state_fails(): void
    {
        $this->setUpTenant();
        $director = $this->actingAsRole('director');

        $this->travelTo(now()->subMinutes(20));
        $state = OAuthState::generate($this->tenant, $director);
        $this->travelBack();

        $this->expectException(RuntimeException::class);
        OAuthState::verify($state, $director);
    }

    public function test_a_tampered_state_fails(): void
    {
        $this->setUpTenant();
        $director = $this->actingAsRole('director');

        $state = OAuthState::generate($this->tenant, $director);

        $this->expectException(RuntimeException::class);
        OAuthState::verify($state.'tampered', $director);
    }
}
