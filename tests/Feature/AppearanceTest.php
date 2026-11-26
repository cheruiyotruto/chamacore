<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\GroupSetting;
use App\Support\Appearance;
use App\Support\GroupContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_defaults_are_used_when_a_group_has_no_theme_settings(): void
    {
        $group = Group::factory()->create();

        $theme = Appearance::for($group);

        $this->assertSame('slate', $theme['border_key']);
        $this->assertSame('#cbd5e1', $theme['border_color']);
        $this->assertSame('slate', $theme['background_key']);
        $this->assertSame('#f8fafc', $theme['background_color']);
        $this->assertSame('green', $theme['accent_key']);
        $this->assertSame('#059669', $theme['accent_color']);
    }

    public function test_group_theme_settings_override_the_defaults(): void
    {
        $group = Group::factory()->create();
        app(GroupContext::class)->setGroup($group);

        foreach ([
            'theme_border_color' => 'blue',
            'theme_accent_color' => 'indigo',
            'theme_background_color' => 'sand',
        ] as $key => $value) {
            GroupSetting::withoutGlobalScopes()->create([
                'group_id' => $group->id,
                'key' => $key,
                'value' => $value,
            ]);
        }

        $theme = Appearance::for($group);

        $this->assertSame('blue', $theme['border_key']);
        $this->assertSame('#2563eb', $theme['border_color']);
        $this->assertSame('sand', $theme['background_key']);
        $this->assertSame('#fdf8f3', $theme['background_color']);
        $this->assertSame('indigo', $theme['accent_key']);
        $this->assertSame('#4f46e5', $theme['accent_color']);
    }

    public function test_unknown_stored_values_fall_back_to_safe_defaults(): void
    {
        $group = Group::factory()->create();
        app(GroupContext::class)->setGroup($group);

        foreach ([
            'theme_border_color' => 'not-a-colour',
            'theme_accent_color' => 'not-a-colour',
            'theme_background_color' => 'not-a-colour',
        ] as $key => $value) {
            GroupSetting::withoutGlobalScopes()->create([
                'group_id' => $group->id,
                'key' => $key,
                'value' => $value,
            ]);
        }

        $theme = Appearance::for($group);

        $this->assertSame('slate', $theme['border_key']);
        $this->assertSame('green', $theme['accent_key']);
        $this->assertSame('slate', $theme['background_key']);
    }
}
