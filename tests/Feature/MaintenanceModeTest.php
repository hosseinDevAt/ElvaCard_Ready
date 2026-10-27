<?php

namespace Tests\Feature;

use App\Livewire\Admin\SiteSettingManager;
use App\Livewire\Auth\Login;
use App\Models\Page;
use App\Models\SiteSetting;
use App\Models\User;
use App\Services\MaintenanceModeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class MaintenanceModeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $customer;

    private MaintenanceModeManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = app(MaintenanceModeManager::class);
        $this->cleanUpFlagFile();

        $this->admin = User::factory()->create([
            'phone' => '09121111111',
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->customer = User::factory()->create([
            'phone' => '09122222222',
            'role' => 'customer',
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        $this->cleanUpFlagFile();
        parent::tearDown();
    }

    private function cleanUpFlagFile(): void
    {
        $path = storage_path('framework/maintenance_mode.json');
        if (file_exists($path)) {
            @unlink($path);
        }
        try {
            SiteSetting::query()->where('key', 'maintenance_mode')->delete();
        } catch (\Throwable) {
        }
        $this->manager->resetMemoizedStatus();
        SiteSetting::clearRuntimeCache();
    }

    public function test_maintenance_mode_is_disabled_by_default(): void
    {
        $this->assertFalse($this->manager->isActive());
    }

    public function test_public_pages_work_normally_when_maintenance_mode_is_disabled(): void
    {
        $page = Page::create([
            'title' => 'صفحه تست',
            'slug' => 'test_page',
            'page_type' => 'general',
            'content' => 'محتوای عادی',
            'is_active' => true,
        ]);

        $response = $this->get(route('pages.show', $page->slug));
        $response->assertOk();
        $response->assertSee('صفحه تست');
    }

    public function test_public_requests_return_503_when_maintenance_is_enabled(): void
    {
        $this->manager->enable('test_actor');

        Page::create([
            'title' => 'صفحه تست',
            'slug' => 'test_page',
            'page_type' => 'general',
            'content' => 'محتوای عادی',
            'is_active' => true,
        ]);

        $response = $this->get(route('pages.show', 'test_page'));
        $response->assertStatus(503);
        $response->assertSee('داریم چیزی زیباتر آماده می‌کنیم');
        $response->assertSee('در حال بهبود تجربهٔ شما');
        $response->assertSee('از شکیبایی شما سپاسگزاریم.');
    }

    public function test_public_homepage_and_catalog_return_503_when_maintenance_is_enabled(): void
    {
        $this->manager->enable('test_actor');

        $homeResponse = $this->get('/');
        $homeResponse->assertStatus(503);
        $homeResponse->assertSee('داریم چیزی زیباتر آماده می‌کنیم');

        $catalogResponse = $this->get(route('catalog.products.index'));
        $catalogResponse->assertStatus(503);
    }

    public function test_unknown_public_routes_return_503_during_maintenance_without_bypassing(): void
    {
        $this->manager->enable('test_actor');

        $response = $this->get('/non-existent-random-route-999');
        $response->assertStatus(503);
        $response->assertSee('داریم چیزی زیباتر آماده می‌کنیم');
    }

    public function test_maintenance_page_renders_without_database_queries(): void
    {
        $this->manager->enable('test_actor');

        // Disconnect or listen to database queries
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->get('/');
        $response->assertStatus(503);

        // Verify that the view rendering does not depend on DB queries
        $queries = DB::getQueryLog();
        // Zero DB queries during rendering because status was read from file and view is pure Blade
        $this->assertCount(0, $queries);
    }

    public function test_static_assets_and_health_remain_accessible_during_maintenance(): void
    {
        $this->manager->enable('test_actor');

        $healthResponse = $this->get('/up');
        $healthResponse->assertOk();

        $faviconResponse = $this->get('/favicon.svg');
        $this->assertNotEquals(503, $faviconResponse->getStatusCode());

        $robotsResponse = $this->get('/robots.txt');
        $this->assertNotEquals(503, $robotsResponse->getStatusCode());
    }

    public function test_login_page_remains_reachable_during_maintenance(): void
    {
        $this->manager->enable('test_actor');

        $response = $this->get(route('login'));
        $response->assertOk();
        $response->assertSee('ورود');
    }

    public function test_logged_out_admin_can_authenticate_via_login_flow_during_maintenance(): void
    {
        $this->manager->enable('test_actor');

        // 1. Visit login page
        $getLogin = $this->get(route('login'));
        $getLogin->assertOk();

        // 2. Perform Livewire login submission
        Livewire::test(Login::class)
            ->set('phone', '09121111111')
            ->set('password', 'password')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('admin.dashboard'));

        // 3. User is authenticated as admin
        $this->assertAuthenticatedAs($this->admin);

        // 4. Admin can access dashboard
        $dashResponse = $this->actingAs($this->admin)->get(route('admin.dashboard'));
        $dashResponse->assertOk();
    }

    public function test_authorized_admin_can_access_settings_and_disable_maintenance(): void
    {
        $this->manager->enable('test_actor');
        $this->assertTrue($this->manager->isActive());

        // Admin opens site-settings
        $response = $this->actingAs($this->admin)->get(route('admin.site-settings'));
        $response->assertOk();
        $response->assertSee('حالت تعمیر و نگهداری');

        // Admin disables maintenance via Livewire
        Livewire::actingAs($this->admin)
            ->test(SiteSettingManager::class)
            ->assertSet('isMaintenanceActive', true)
            ->call('disableMaintenance')
            ->assertSet('isMaintenanceActive', false);

        $this->assertFalse($this->manager->isActive());

        // Public access is immediately restored
        $publicResponse = $this->get('/');
        $publicResponse->assertOk();
    }

    public function test_authorized_admin_can_enable_maintenance_via_livewire(): void
    {
        $this->assertFalse($this->manager->isActive());

        Livewire::actingAs($this->admin)
            ->test(SiteSettingManager::class)
            ->assertSet('isMaintenanceActive', false)
            ->call('enableMaintenance')
            ->assertSet('isMaintenanceActive', true);

        $this->assertTrue($this->manager->isActive());

        auth()->logout();
        $this->manager->resetMemoizedStatus();

        $publicResponse = $this->get('/');
        $publicResponse->assertStatus(503);
    }

    public function test_ordinary_customer_cannot_toggle_maintenance_mode(): void
    {
        $this->actingAs($this->customer);

        // Access to admin route is rejected by admin middleware
        $response = $this->get(route('admin.site-settings'));
        $response->assertStatus(403);

        // Livewire component aborts with 403 upon mount via AuthorizesAdminActions
        Livewire::actingAs($this->customer)
            ->test(SiteSettingManager::class)
            ->assertStatus(403);

        $this->assertFalse($this->manager->isActive());
    }

    public function test_unauthenticated_user_cannot_invoke_toggle_action(): void
    {
        // Unauthenticated access to admin settings redirects to login
        $response = $this->get(route('admin.site-settings'));
        $response->assertRedirect(route('login'));

        // Livewire aborts with 403 via AuthorizesAdminActions
        Livewire::test(SiteSettingManager::class)
            ->assertStatus(403);

        $this->assertFalse($this->manager->isActive());
    }

    public function test_state_changes_persist_across_independent_requests(): void
    {
        $this->assertFalse($this->manager->isActive());

        $this->manager->enable('admin_user');

        // Simulate fresh request lifecycle with clean memory
        $this->manager->resetMemoizedStatus();
        $freshManager = new MaintenanceModeManager;
        $this->assertTrue($freshManager->isActive());

        // Verify stored in database
        $dbRow = SiteSetting::query()->where('key', 'maintenance_mode')->first();
        $this->assertNotNull($dbRow);
        $this->assertSame('1', (string) $dbRow->value);

        // Disable and verify persistence
        $freshManager->disable();
        $this->manager->resetMemoizedStatus();
        $this->assertFalse((new MaintenanceModeManager)->isActive());

        $dbRow->refresh();
        $this->assertSame('0', (string) $dbRow->value);
    }

    public function test_authenticated_admin_can_preview_public_pages_during_maintenance(): void
    {
        $this->manager->enable('test_actor');

        Page::create([
            'title' => 'صفحه راهنما',
            'slug' => 'guide_page',
            'page_type' => 'general',
            'content' => 'محتوای ادمین',
            'is_active' => true,
        ]);

        // Public visitor gets 503
        $this->get(route('pages.show', 'guide_page'))->assertStatus(503);

        // Authenticated admin gets 200 and sees the page
        $adminResponse = $this->actingAs($this->admin)->get(route('pages.show', 'guide_page'));
        $adminResponse->assertOk();
        $adminResponse->assertSee('صفحه راهنما');
    }

    public function test_no_redirect_loops_occur_during_maintenance(): void
    {
        $this->manager->enable('test_actor');

        // Public page gives direct 503, never 301 or 302
        $response = $this->get('/');
        $this->assertSame(503, $response->getStatusCode());
        $this->assertFalse($response->isRedirection());
    }
}
