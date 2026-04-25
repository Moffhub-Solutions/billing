<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Moffhub\Billing\Enums\FeatureType;
use Moffhub\Billing\Models\Feature;
use Moffhub\Billing\Tests\BaseTestCase;

class FeatureTest extends BaseTestCase
{
    public function test_can_create_feature(): void
    {
        $feature = Feature::create([
            'slug' => 'ocr_scanning',
            'name' => 'OCR Scanning',
            'description' => 'Kenya ID OCR scanning',
            'category' => 'addons',
            'type' => FeatureType::METERED,
            'is_addon' => true,
            'addon_price' => 2000,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas(billing_table('features', 'billing_features'), [
            'slug' => 'ocr_scanning',
        ]);

        $this->assertEquals(FeatureType::METERED, $feature->type);
        $this->assertTrue($feature->is_addon);
        $this->assertTrue($feature->isTrackable());
    }

    public function test_boolean_feature_is_not_trackable(): void
    {
        $feature = Feature::create([
            'slug' => 'shifts',
            'name' => 'Shift Management',
            'type' => FeatureType::BOOLEAN,
            'is_active' => true,
        ]);

        $this->assertFalse($feature->isTrackable());
    }

    public function test_active_and_addon_scopes(): void
    {
        Feature::create(['slug' => 'active-bool', 'name' => 'A', 'type' => 'boolean', 'is_active' => true, 'is_addon' => false]);
        Feature::create(['slug' => 'active-addon', 'name' => 'B', 'type' => 'boolean', 'is_active' => true, 'is_addon' => true]);
        Feature::create(['slug' => 'inactive', 'name' => 'C', 'type' => 'boolean', 'is_active' => false, 'is_addon' => true]);

        $this->assertEquals(2, Feature::active()->count());
        $this->assertEquals(1, Feature::active()->addons()->count());
    }
}
