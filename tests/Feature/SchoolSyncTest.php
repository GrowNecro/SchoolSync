<?php

namespace Tests\Feature;

use App\Models\ClientComputer;
use App\Models\ClientSyncedFile;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class SchoolSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_default_admin_without_overwriting_changed_password(): void
    {
        $this->seed();

        $user = User::query()->where('username', 'admin')->firstOrFail();
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertDatabaseCount('settings', 1);

        $user->update(['password' => 'changed']);
        $this->seed();

        $this->assertTrue(Hash::check('changed', $user->fresh()->password));
    }

    public function test_seeded_admin_can_login(): void
    {
        $this->seed();
        $this->get('/')->assertRedirect(route('login'));

        $this->post('/login', ['username' => 'admin', 'password' => 'password'])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();
    }

    public function test_admin_can_save_configuration_and_api_returns_it(): void
    {
        $user = User::factory()->create(['username' => 'adminlab']);
        Setting::query()->create(Setting::defaults());

        $this->actingAs($user)->put('/settings', [
            'schedule_day' => 'Thursday',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'browser' => "classroom.google.com\nhttps://example.com",
            'launcher' => ['edge', 'roblox'],
            'shutdown_enabled' => '1',
            'shutdown_warning' => 15,
            'shutdown_excluded_computers' => ['LAB-GURU'],
            'shutdown_excluded_manual' => "SERVER-KELAS\nlab-guru",
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->getJson('/client/config')->assertOk()->assertExactJson([
            'schedule' => ['day' => 'Thursday', 'start' => '08:00', 'end' => '10:00'],
            'project' => '',
            'browser' => ['https://classroom.google.com', 'https://example.com'],
            'launcher' => ['edge', 'roblox'],
            'shutdown' => ['enabled' => true, 'warning' => 15, 'excluded_computers' => ['LAB-GURU', 'SERVER-KELAS']],
        ]);
    }

    public function test_project_upload_metadata_download_and_delete_work(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        Setting::query()->create(Setting::defaults());
        $file = UploadedFile::fake()->create('Pertemuan-01.rbxl', 25, 'application/octet-stream');

        $this->actingAs($user)->post('/projects', ['project_file' => $file])
            ->assertSessionHasNoErrors()->assertRedirect();

        $project = Project::query()->firstOrFail();
        Storage::disk('local')->assertExists($project->path);
        $this->get('/download?file=Pertemuan-01.rbxl')->assertOk()->assertDownload('Pertemuan-01.rbxl');
        $this->get('/api/project.php?file=Pertemuan-01.rbxl')->assertNotFound();

        $this->actingAs($user)->delete(route('projects.destroy', $project))->assertRedirect();
        $this->assertDatabaseCount('projects', 0);
        Storage::disk('local')->assertMissing($project->path);
    }

    public function test_any_file_can_be_uploaded_and_zip_is_listed_for_safe_extraction(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        Setting::query()->create(Setting::defaults());

        $this->actingAs($user)->post('/projects', [
            'project_file' => UploadedFile::fake()->image('poster-kelas.png'),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->actingAs($user)->post('/projects', [
            'project_file' => UploadedFile::fake()->create('materi-kelas.zip', 25, 'application/zip'),
        ])->assertSessionHasNoErrors()->assertRedirect();

        $zip = Project::query()->where('filename', 'materi-kelas.zip')->firstOrFail();
        $this->assertTrue($zip->extract);
        $this->assertNotEmpty($zip->sha256);
        $this->assertDatabaseHas('projects', ['filename' => 'poster-kelas.png', 'extract' => false]);

        $this->getJson('/client/files')->assertOk()
            ->assertJsonFragment([
                'name' => 'materi-kelas.zip',
                'size' => 25 * 1024,
                'sha256' => $zip->sha256,
                'extract' => true,
            ])
            ->assertJsonFragment(['name' => 'poster-kelas.png', 'extract' => false]);
    }

    public function test_password_can_be_changed(): void
    {
        $user = User::factory()->create(['password' => 'old']);

        $this->actingAs($user)->put('/password', [
            'current_password' => 'old',
            'password' => 'x',
            'password_confirmation' => 'x',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertTrue(Hash::check('x', $user->fresh()->password));
    }

    public function test_client_files_and_generated_installer_are_available(): void
    {
        $this->get('/download?client=version.txt')->assertOk()->assertSee(trim(file_get_contents(base_path('tools/version.txt'))));
        $this->get('/download?client=forbidden.php')->assertNotFound();
        $this->get('/api/client.php?file=version.txt')->assertNotFound();
        $this->get('/installer')->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="Install-SchoolSync.bat"')
            ->assertSee('set "PANEL_URL=http://localhost"', false)
            ->assertSee('/download?client=', false)
            ->assertSee('SchoolSync Heartbeat', false)
            ->assertSee('-HeartbeatOnly', false)
            ->assertDontSee('__SCHOOLSYNC_PANEL_URL__', false);
        $this->get('/api/installer.php')->assertNotFound();
    }

    public function test_pages_are_separate_and_open_now_queues_an_edge_command(): void
    {
        $user = User::factory()->create();
        Setting::query()->create(Setting::defaults());

        foreach (['/', '/settings', '/files', '/connection', '/security'] as $page) {
            $this->actingAs($user)->get($page)->assertOk();
        }

        $this->actingAs($user)->post('/actions/open-url', [
            'url' => 'materi scratch kelas 8',
        ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));

        $command = \App\Models\RemoteCommand::query()->firstOrFail();
        $this->getJson('/client/commands?after=0')->assertOk()->assertJsonFragment([
            'id' => $command->id,
            'action' => 'open_edge',
            'payload' => ['url' => 'https://www.google.com/search?q=materi%20scratch%20kelas%208'],
        ]);
        $this->getJson('/client/commands?after='.$command->id)->assertOk()->assertExactJson(['commands' => []]);

        $this->actingAs($user)->post('/actions/open-url', [
            'url' => 'javascript:alert(1)',
        ])->assertSessionHasErrors('url');
        $this->assertDatabaseCount('remote_commands', 1);
    }

    public function test_admin_can_queue_open_app_and_shutdown_with_exclusions(): void
    {
        $user = User::factory()->create();
        $setting = Setting::query()->create([
            ...Setting::defaults(),
            'shutdown_excluded_computers' => ['LAB-GURU', 'SERVER-KELAS'],
        ]);

        $this->actingAs($user)->post(route('actions.open-app'), ['app' => 'vscode'])
            ->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('remote_commands', ['action' => 'open_app']);
        $this->getJson('/client/commands?after=0')->assertOk()->assertJsonFragment([
            'action' => 'open_app',
            'payload' => ['app' => 'vscode'],
        ]);

        $project = Project::query()->create([
            'filename' => 'Pertemuan-01.rbxl',
            'path' => 'projects/Pertemuan-01.rbxl',
            'size' => 100,
            'sha256' => hash('sha256', 'project'),
            'extract' => false,
        ]);
        $setting->update(['project_id' => $project->id]);
        $this->actingAs($user)->post(route('actions.open-app'), ['app' => 'roblox'])
            ->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));
        $this->getJson('/client/commands?after=0')->assertOk()->assertJsonFragment([
            'action' => 'open_app',
            'payload' => ['app' => 'roblox', 'project' => 'Pertemuan-01.rbxl'],
        ]);

        $this->actingAs($user)->post(route('actions.shutdown'))
            ->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));
        $this->getJson('/client/commands?after=0')->assertOk()->assertJsonFragment([
            'action' => 'shutdown',
            'payload' => ['excluded_computers' => ['LAB-GURU', 'SERVER-KELAS']],
        ]);

        $this->actingAs($user)->post(route('actions.open-app'), ['app' => 'cmd'])
            ->assertSessionHasErrors('app');
    }

    public function test_client_files_are_grouped_privately_and_admin_can_download_them(): void
    {
        Storage::fake('local');
        $installationId = (string) Str::uuid();
        $contents = 'updated roblox project';

        $this->postJson('/client/heartbeat', [
            'installation_id' => $installationId,
            'computer_name' => 'LAB-PC-01',
            'version' => '1.7.1',
            'interactive' => true,
        ])->assertOk();

        $this->withHeader('X-SchoolSync-Client', '1')->post('/client/files/upload', [
            'installation_id' => $installationId,
            'computer_name' => 'LAB-PC-01',
            'relative_path' => 'kelas-8/proyek.rbxl',
            'sha256' => hash('sha256', $contents),
            'file' => UploadedFile::fake()->createWithContent('proyek.rbxl', $contents),
        ])->assertOk()->assertJson(['ok' => true, 'path' => 'kelas-8/proyek.rbxl']);

        $syncedFile = ClientSyncedFile::query()->with('computer')->firstOrFail();
        $this->assertSame('LAB-PC-01', $syncedFile->computer->computer_name);
        $this->assertStringContainsString('LAB-PC-01-'.$installationId, $syncedFile->storage_path);
        Storage::disk('local')->assertExists($syncedFile->storage_path);

        $this->get(route('client-files'))->assertRedirect(route('login'));
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('client-files'))->assertOk()
            ->assertSee('LAB-PC-01')->assertSee('kelas-8/proyek.rbxl');
        $this->actingAs($user)->get(route('client-files.download', $syncedFile))
            ->assertOk()->assertDownload('proyek.rbxl');

        $this->withHeader('X-SchoolSync-Client', '1')->post('/client/files/upload', [
            'installation_id' => $installationId,
            'computer_name' => 'LAB-PC-01',
            'relative_path' => '../outside.txt',
            'sha256' => hash('sha256', 'x'),
            'file' => UploadedFile::fake()->createWithContent('outside.txt', 'x'),
        ])->assertSessionHasErrors('relative_path');
    }

    public function test_heartbeat_tracks_unique_active_computers_and_dashboard_status(): void
    {
        $installationId = (string) Str::uuid();

        $this->postJson('/client/heartbeat', [
            'installation_id' => $installationId,
            'computer_name' => 'LAB-PC-01',
            'version' => '1.6.0',
            'interactive' => true,
        ])->assertOk()->assertJson(['ok' => true, 'active_for_seconds' => 90]);

        $this->postJson('/client/heartbeat', [
            'installation_id' => $installationId,
            'computer_name' => 'LAB-PC-01-RENAMED',
            'version' => '1.6.0',
            'interactive' => false,
        ])->assertOk();

        $this->postJson('/client/heartbeat', [
            'installation_id' => (string) Str::uuid(),
            'computer_name' => 'LAB-PC-POWERED-ON',
            'version' => '1.6.0',
            'interactive' => false,
        ])->assertOk();

        ClientComputer::query()->create([
            'installation_id' => (string) Str::uuid(),
            'computer_name' => 'LAB-PC-OFFLINE',
            'version' => '1.3.0',
            'last_seen_at' => now()->subMinutes(5),
        ]);

        $this->assertDatabaseCount('client_computers', 3);
        $this->assertDatabaseHas('client_computers', [
            'installation_id' => $installationId,
            'computer_name' => 'LAB-PC-01-RENAMED',
        ]);

        $user = User::factory()->create();
        Setting::query()->create(Setting::defaults());
        $this->actingAs($user)->getJson('/status/computers')->assertOk()
            ->assertJsonPath('active_count', 2)
            ->assertJsonPath('total_count', 3)
            ->assertJsonFragment(['name' => 'LAB-PC-01-RENAMED', 'active' => true, 'interactive' => true])
            ->assertJsonFragment(['name' => 'LAB-PC-POWERED-ON', 'active' => true, 'interactive' => false])
            ->assertJsonFragment(['name' => 'LAB-PC-OFFLINE', 'active' => false, 'interactive' => false]);

        $this->actingAs($user)->get('/')->assertOk()
            ->assertSee('data-active-count>2</span> komputer', false)
            ->assertSee('LAB-PC-01-RENAMED')
            ->assertSee('Siap Edge')
            ->assertSee('LAB-PC-POWERED-ON')
            ->assertSee('Menyala');
    }
}
