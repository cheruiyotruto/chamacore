<?php

namespace Tests\Feature;

use App\Models\MarketingLead;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_marketing_pages_are_available(): void
    {
        $pages = [
            route('home') => 'clarity and confidence',
            route('marketing.features') => 'Everything your group needs',
            route('marketing.pricing') => 'A plan for the group you are today',
            route('marketing.about') => 'group money deserves better',
            route('marketing.contact') => 'Access request',
        ];

        foreach ($pages as $url => $copy) {
            $this->get($url)
                ->assertOk()
                ->assertSee($copy);
        }
    }

    public function test_pricing_page_shows_active_plans(): void
    {
        Plan::create([
            'name' => 'Test Club',
            'slug' => 'test-club',
            'price' => 125000,
            'interval' => 'monthly',
            'trial_days' => 14,
            'max_groups' => 2,
            'max_members' => 75,
            'modules' => ['core', 'savings'],
            'is_active' => true,
        ]);

        $this->get(route('marketing.pricing'))
            ->assertOk()
            ->assertSee('Test Club')
            ->assertSee('KES 1,250.00')
            ->assertSee('Up to 75');
    }

    public function test_contact_form_captures_an_access_request(): void
    {
        $this->post(route('marketing.contact.store'), [
            'name' => 'Amina Mwangi',
            'organisation' => 'Umoja Women Group',
            'email' => 'amina@example.test',
            'phone' => '0712000000',
            'group_type' => 'Chama / investment club',
            'expected_members' => 80,
            'plan' => 'growth',
            'message' => 'We need savings and merry-go-round.',
        ])->assertRedirect();

        $this->assertDatabaseHas('marketing_leads', [
            'name' => 'Amina Mwangi',
            'organisation' => 'Umoja Women Group',
            'email' => 'amina@example.test',
            'plan' => 'growth',
            'status' => MarketingLead::STATUS_NEW,
        ]);
    }

    public function test_contact_form_validates_required_fields(): void
    {
        $this->post(route('marketing.contact.store'), [])
            ->assertSessionHasErrors(['name', 'organisation', 'email']);

        $this->assertDatabaseCount('marketing_leads', 0);
    }

    public function test_dashboard_uses_new_path_for_authenticated_users(): void
    {
        $this->get('/')->assertOk()->assertViewIs('marketing.home');

        $this->get('/dashboard')->assertRedirect(route('login'));

        $this->assertSame(url('/dashboard'), route('dashboard'));
    }

    public function test_super_admin_can_review_and_update_access_requests(): void
    {
        $lead = MarketingLead::create([
            'name' => 'Brian Otieno',
            'organisation' => 'Future SACCO',
            'email' => 'brian@example.test',
            'status' => MarketingLead::STATUS_NEW,
        ]);

        $admin = User::factory()->create(['is_super_admin' => true]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('platform.leads.index'))
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('platform.leads.index'))
            ->assertOk()
            ->assertSee('Future SACCO');

        $this->actingAs($admin)
            ->put(route('platform.leads.update', $lead), [
                'status' => MarketingLead::STATUS_CONTACTED,
                'notes' => 'Called and sent plan details.',
            ])
            ->assertRedirect();

        $this->assertSame(MarketingLead::STATUS_CONTACTED, $lead->fresh()->status);
        $this->assertSame('Called and sent plan details.', $lead->fresh()->notes);
    }
}
