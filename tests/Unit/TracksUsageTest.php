<?php

declare(strict_types=1);

namespace Moffhub\Billing\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Moffhub\Billing\Enums\BillingCycle;
use Moffhub\Billing\Models\Plan;
use Moffhub\Billing\Models\UsageEvent;
use Moffhub\Billing\Services\UsageService;
use Moffhub\Billing\Tests\BaseTestCase;
use Moffhub\Billing\Tests\Fixtures\Models\Company;
use Moffhub\Billing\Traits\TracksUsage;

// ─── Test models (defined here for isolation) ──────────────────────────

/**
 * @property int|null $company_id
 * @property string|null $name
 * @property-read Company|null $company
 */
class SimpleTrackedModel extends Model
{
    use TracksUsage;

    protected $table = 'tracked_items';

    protected $guarded = [];

    protected static string $usageFeatureSlug = 'ocr_scanning';

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function usageBillable(): ?Model
    {
        return $this->company;
    }
}

/**
 * @property int|null $company_id
 * @property string|null $name
 * @property-read Company|null $company
 */
class PerOperationModel extends Model
{
    use TracksUsage;

    protected $table = 'tracked_items';

    protected $guarded = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    protected static array $usageMap = [
        'created' => ['feature' => 'document_uploads', 'quantity' => 1],
        'updated' => ['feature' => 'document_edits', 'quantity' => 1],
        'deleted' => ['feature' => 'document_deletes', 'quantity' => 2],
    ];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function usageBillable(): ?Model
    {
        return $this->company;
    }
}

/**
 * @property int|null $company_id
 * @property string|null $type
 * @property-read Company|null $company
 */
class DynamicTrackedModel extends Model
{
    use TracksUsage;

    protected $table = 'tracked_items';

    protected $guarded = [];

    /** @var array<int, string> */
    protected static array $usageTrackOn = ['created'];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function usageBillable(): ?Model
    {
        return $this->company;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveUsage(string $event): ?array
    {
        return match ($this->type) {
            'visitor' => ['feature' => 'visitor_entries', 'quantity' => 1],
            'vehicle' => ['feature' => 'vehicle_entries', 'quantity' => 1],
            default => null, // skip tracking
        };
    }
}

// ─── Tests ─────────────────────────────────────────────────────────────

class TracksUsageTest extends BaseTestCase
{
    protected Company $company;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::create([
            'ulid' => Str::ulid()->toBase32(),
            'name' => 'Standard',
            'slug' => 'standard',
            'base_price' => 750000,
            'billing_cycle' => BillingCycle::MONTHLY,
            'is_active' => true,
            'features' => ['ocr_scanning', 'document_uploads', 'document_edits', 'document_deletes', 'visitor_entries', 'vehicle_entries'],
            'limits' => ['ocr_scanning' => 100],
        ]);

        $this->company = Company::create(['name' => 'Test Co']);
        $this->company->subscribe('standard')->create();
    }

    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        // Create test table for tracked items
        Schema::create('tracked_items', function ($table): void {
            $table->id();
            $table->foreignId('company_id')->nullable();
            $table->string('type')->nullable();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }

    public function test_simple_tracking_on_create(): void
    {
        SimpleTrackedModel::create([
            'company_id' => $this->company->id,
            'name' => 'test scan',
        ]);

        $this->assertEquals(1, $this->company->usage('ocr_scanning'));
        $this->assertEquals(1, UsageEvent::where('feature_slug', 'ocr_scanning')->count());
    }

    public function test_per_operation_different_features(): void
    {
        $model = PerOperationModel::create([
            'company_id' => $this->company->id,
            'name' => 'test doc',
        ]);

        $this->assertEquals(1, UsageEvent::where('feature_slug', 'document_uploads')->count());

        $model->update(['name' => 'updated doc']);

        $this->assertEquals(1, UsageEvent::where('feature_slug', 'document_edits')->count());

        $model->delete();

        $events = UsageEvent::where('feature_slug', 'document_deletes')->first();
        $this->assertNotNull($events);
        $this->assertEquals(2, $events->quantity); // quantity = 2 as configured
    }

    public function test_dynamic_tracking_by_model_state(): void
    {
        DynamicTrackedModel::create([
            'company_id' => $this->company->id,
            'type' => 'visitor',
        ]);

        DynamicTrackedModel::create([
            'company_id' => $this->company->id,
            'type' => 'vehicle',
        ]);

        DynamicTrackedModel::create([
            'company_id' => $this->company->id,
            'type' => 'unknown', // resolveUsage returns null → no tracking
        ]);

        $this->assertEquals(1, UsageEvent::where('feature_slug', 'visitor_entries')->count());
        $this->assertEquals(1, UsageEvent::where('feature_slug', 'vehicle_entries')->count());
        $this->assertEquals(2, UsageEvent::count()); // "unknown" was skipped
    }

    public function test_deduplication_prevents_double_counting(): void
    {
        $model = SimpleTrackedModel::create([
            'company_id' => $this->company->id,
            'name' => 'test',
        ]);

        // Manually record the same event again using the service directly
        // (simulating a retry with the same transaction_id)
        $key = $model->getKey();
        $transactionId = 'SimpleTrackedModel:'.((is_int($key) || is_string($key)) ? $key : '').':created';
        app(UsageService::class)->record(
            billable: $this->company,
            featureSlug: 'ocr_scanning',
            quantity: 1,
            transactionId: $transactionId,
        );

        // Should still be 1 because transaction_id is the same
        $this->assertEquals(1, UsageEvent::where('feature_slug', 'ocr_scanning')->count());
    }
}
