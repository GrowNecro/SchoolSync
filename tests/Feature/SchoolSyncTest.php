<?php

namespace Tests\Feature;

use App\Models\ClientComputer;
use App\Models\ClientFileVersion;
use App\Models\ClientSyncedFile;
use App\Models\ClassSchedule;
use App\Models\CommandExecution;
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
        [$computer, $headers] = $this->authenticatedClient();
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

        $this->withHeaders($headers)->getJson('/client/config?installation_id='.$computer->installation_id)->assertOk()->assertJson([
            'schedule' => ['day' => 'Thursday', 'start' => '08:00', 'end' => '10:00'],
            'project' => '',
            'browser' => ['https://classroom.google.com', 'https://example.com'],
            'launcher' => ['edge', 'roblox'],
            'shutdown' => ['enabled' => true, 'warning' => 15, 'excluded_computers' => ['LAB-GURU', 'SERVER-KELAS']],
        ]);
    }

    public function test_legacy_client_can_read_basic_config_until_it_self_updates(): void
    {
        Setting::query()->create(Setting::defaults());

        $this->getJson('/client/config')
            ->assertOk()
            ->assertHeader('X-SchoolSync-Legacy', 'upgrade-required')
            ->assertJsonStructure(['schedule', 'project', 'browser', 'launcher', 'shutdown'])
            ->assertJsonMissingPath('schedules');
    }

    public function test_project_upload_metadata_download_and_delete_work(): void
    {
        [$computer, $headers] = $this->authenticatedClient();
        Storage::fake('local');
        $user = User::factory()->create();
        Setting::query()->create(Setting::defaults());
        $file = UploadedFile::fake()->create('Pertemuan-01.rbxl', 25, 'application/octet-stream');

        $this->actingAs($user)->post('/projects', ['project_file' => $file])
            ->assertSessionHasNoErrors()->assertRedirect();

        $project = Project::query()->firstOrFail();
        Storage::disk('local')->assertExists($project->path);
        $this->assertDatabaseHas('remote_commands', ['action' => 'refresh_files', 'target_type' => 'all']);
        $automaticCommand = \App\Models\RemoteCommand::query()->firstOrFail();
        $this->assertTrue($automaticCommand->expires_at->gte(now()->addDays(6)));
        $this->withHeaders($headers)->getJson('/client/commands?after=0&installation_id='.$computer->installation_id)
            ->assertOk()->assertJsonFragment(['action' => 'refresh_files']);
        $this->actingAs($user)->get(route('files'))->assertOk()->assertSee('Sinkronkan sekarang');
        $this->actingAs($user)->post(route('actions.sync-files'), [
            'target' => 'computer:'.$computer->installation_id,
        ])->assertSessionHasNoErrors()->assertRedirect(route('files'));
        $manualCommand = \App\Models\RemoteCommand::query()->latest('id')->firstOrFail();
        $this->assertSame('refresh_files', $manualCommand->action);
        $this->assertSame('computer', $manualCommand->target_type);
        $this->assertSame([$computer->installation_id], $manualCommand->target_value);
        $this->assertTrue($manualCommand->payload['manual']);
        $this->assertSame(1, $manualCommand->payload['file_count']);
        $this->assertTrue($manualCommand->expires_at->gte(now()->addDays(6)));
        $this->withHeaders($headers)->getJson('/client/commands?after='.$automaticCommand->id.'&installation_id='.$computer->installation_id)
            ->assertOk()->assertJsonFragment(['id' => $manualCommand->id, 'action' => 'refresh_files']);
        $this->withHeaders($headers)->get('/download?file=Pertemuan-01.rbxl&installation_id='.$computer->installation_id)->assertOk()->assertDownload('Pertemuan-01.rbxl');
        $this->get('/api/project.php?file=Pertemuan-01.rbxl')->assertNotFound();

        $this->actingAs($user)->delete(route('projects.destroy', $project))->assertRedirect();
        $this->assertDatabaseCount('projects', 0);
        Storage::disk('local')->assertMissing($project->path);
    }

    public function test_any_file_can_be_uploaded_and_zip_is_listed_for_safe_extraction(): void
    {
        [$computer, $headers] = $this->authenticatedClient();
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

        $this->withHeaders($headers)->getJson('/client/files?installation_id='.$computer->installation_id)->assertOk()
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
        $clientScript = file_get_contents(base_path('tools/SchoolSync.ps1'));
        $this->assertStringContainsString("return @('RobloxPlayerBeta', 'RobloxPlayerLauncher', 'Windows10Universal')", $clientScript);
        $this->assertStringNotContainsString("'RobloxCrashHandler'", $clientScript);
        $this->assertStringContainsString('$script:ExamModeAvailable = $false', $clientScript);
        $this->assertStringContainsString('$response.update_required', $clientScript);
        $this->assertStringContainsString("RunningClientVersion = '2.0.6'", $clientScript);
        $this->assertStringContainsString('Invoke-CachedExamPolicies -DryRun:$DryRun', $clientScript);
        $this->assertStringContainsString("command.action -eq 'refresh_exam_policy'", $clientScript);
        $this->assertStringContainsString("command.action -eq 'refresh_files'", $clientScript);
        $this->assertStringContainsString('FileSystemWatcher', $clientScript);
        $this->assertStringContainsString('Invoke-PendingClientFileSync -ServerUrl', $clientScript);
        $this->assertStringNotContainsString('$fileSyncCountdown', $clientScript);
        $this->assertStringContainsString('Server rate limit reached; pausing requests', $clientScript);
        $this->assertStringContainsString('Set-ClientSyncStateHash -RelativePath', $clientScript);
        $this->assertSame('2.0.6', trim(file_get_contents(base_path('tools/version.txt'))));
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

        foreach (['/', '/schedules', '/files', '/client-files', '/computers', '/activity', '/connection', '/security'] as $page) {
            $this->actingAs($user)->get($page)->assertOk();
        }
        $this->actingAs($user)->get('/settings')->assertRedirect('/schedules');

        $this->actingAs($user)->post('/actions/open-url', [
            'url' => 'materi scratch kelas 8',
            'target' => 'all',
        ])->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));

        $command = \App\Models\RemoteCommand::query()->firstOrFail();
        [$computer, $headers] = $this->authenticatedClient('LAB-COMMAND');
        $this->withHeaders($headers)->getJson('/client/commands?after=0&installation_id='.$computer->installation_id)->assertOk()->assertJsonFragment([
            'id' => $command->id,
            'action' => 'open_edge',
            'payload' => ['url' => 'https://www.google.com/search?q=materi%20scratch%20kelas%208'],
        ]);
        $this->withHeaders($headers)->getJson('/client/commands?after='.$command->id.'&installation_id='.$computer->installation_id)->assertOk()->assertExactJson(['commands' => [], 'cursor' => $command->id]);

        $this->actingAs($user)->post('/actions/open-url', [
            'url' => 'javascript:alert(1)',
            'target' => 'all',
        ])->assertSessionHasErrors('url');
        $this->assertDatabaseCount('remote_commands', 1);
    }

    public function test_admin_can_queue_open_app_and_shutdown_with_exclusions(): void
    {
        [$computer, $headers] = $this->authenticatedClient('LAB-A-01', 'LAB-A');
        $user = User::factory()->create();
        $setting = Setting::query()->create([
            ...Setting::defaults(),
            'shutdown_excluded_computers' => ['LAB-GURU', 'SERVER-KELAS'],
        ]);

        $this->actingAs($user)->post(route('actions.open-app'), ['app' => 'vscode', 'target' => 'group:LAB-A'])
            ->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('remote_commands', ['action' => 'open_app']);
        $this->withHeaders($headers)->getJson('/client/commands?after=0&installation_id='.$computer->installation_id)->assertOk()->assertJsonFragment([
            'action' => 'open_app',
            'payload' => ['app' => 'vscode'],
        ]);
        $firstCommand = \App\Models\RemoteCommand::query()->where('action', 'open_app')->firstOrFail();
        [$otherComputer, $otherHeaders] = $this->authenticatedClient('LAB-B-01', 'LAB-B');
        $this->withHeaders($otherHeaders)->getJson('/client/commands?after=0&installation_id='.$otherComputer->installation_id)
            ->assertOk()->assertExactJson(['commands' => [], 'cursor' => $firstCommand->id]);
        $this->withHeaders($headers)->postJson('/client/commands/acknowledge', [
            'installation_id' => $computer->installation_id,
            'command_id' => $firstCommand->id,
            'status' => 'success',
            'message' => 'Visual Studio Code dibuka.',
        ])->assertOk();
        $this->assertDatabaseHas('command_executions', [
            'remote_command_id' => $firstCommand->id,
            'client_computer_id' => $computer->id,
            'status' => 'success',
        ]);
        $this->withHeaders($headers)->postJson('/client/commands/acknowledge', [
            'installation_id' => $computer->installation_id,
            'command_id' => $firstCommand->id,
            'status' => 'skipped',
            'message' => 'Proses lain melewati perintah.',
        ])->assertOk();
        $this->assertSame('success', CommandExecution::query()->where('remote_command_id', $firstCommand->id)->where('client_computer_id', $computer->id)->value('status'));

        $project = Project::query()->create([
            'filename' => 'Pertemuan-01.rbxl',
            'path' => 'projects/Pertemuan-01.rbxl',
            'size' => 100,
            'sha256' => hash('sha256', 'project'),
            'extract' => false,
        ]);
        $setting->update(['project_id' => $project->id]);
        $this->actingAs($user)->post(route('actions.open-app'), ['app' => 'roblox', 'target' => 'computer:'.$computer->installation_id])
            ->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));
        $this->withHeaders($headers)->getJson('/client/commands?after=0&installation_id='.$computer->installation_id)->assertOk()->assertJsonFragment([
            'action' => 'open_app',
            'payload' => ['app' => 'roblox', 'project' => 'Pertemuan-01.rbxl'],
        ]);

        $this->actingAs($user)->post(route('actions.shutdown'), ['target' => 'all'])
            ->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));
        $this->withHeaders($headers)->getJson('/client/commands?after=0&installation_id='.$computer->installation_id)->assertOk()->assertJsonFragment([
            'action' => 'shutdown',
            'payload' => ['excluded_computers' => ['LAB-GURU', 'SERVER-KELAS']],
        ]);

        $this->actingAs($user)->post(route('actions.open-app'), ['app' => 'cmd', 'target' => 'all'])
            ->assertSessionHasErrors('app');
    }

    public function test_client_files_are_grouped_privately_and_admin_can_download_them(): void
    {
        Storage::fake('local');
        [$clientComputer, $headers] = $this->authenticatedClient('LAB-PC-01');
        $installationId = $clientComputer->installation_id;
        $contents = 'updated roblox project';

        $this->withHeaders($headers)->post('/client/files/upload', [
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
        $this->assertDatabaseCount('client_file_versions', 1);

        $updatedContents = 'second project version';
        $this->withHeaders($headers)->post('/client/files/upload', [
            'installation_id' => $installationId,
            'computer_name' => 'LAB-PC-01',
            'relative_path' => 'kelas-8/proyek.rbxl',
            'sha256' => hash('sha256', $updatedContents),
            'file' => UploadedFile::fake()->createWithContent('proyek.rbxl', $updatedContents),
        ])->assertOk();
        $this->assertDatabaseCount('client_file_versions', 2);

        $oldVersion = ClientFileVersion::query()->oldest()->firstOrFail();

        $this->get(route('client-files'))->assertRedirect(route('login'));
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('client-files'))->assertOk()
            ->assertSee('LAB-PC-01')->assertSee('kelas-8/proyek.rbxl');
        $this->actingAs($user)->get(route('client-files.download', $syncedFile))
            ->assertOk()->assertDownload('proyek.rbxl');
        $this->actingAs($user)->post(route('client-file-versions.restore', $oldVersion))->assertRedirect(route('client-files'));
        $this->assertDatabaseHas('remote_commands', ['action' => 'restore_file', 'target_type' => 'computer']);

        $this->withHeaders($headers)->post('/client/files/upload', [
            'installation_id' => $installationId,
            'computer_name' => 'LAB-PC-01',
            'relative_path' => '../outside.txt',
            'sha256' => hash('sha256', 'x'),
            'file' => UploadedFile::fake()->createWithContent('outside.txt', 'x'),
        ])->assertUnprocessable()->assertJsonValidationErrors('relative_path');
    }

    public function test_heartbeat_tracks_unique_active_computers_and_dashboard_status(): void
    {
        $installationId = (string) Str::uuid();

        $this->postJson('/client/heartbeat', [
            'installation_id' => $installationId,
            'computer_name' => 'LAB-PC-01',
            'version' => '1.6.0',
            'interactive' => true,
        ])->assertOk()->assertJson([
            'ok' => true,
            'active_for_seconds' => 180,
            'latest_version' => '2.0.6',
            'update_required' => true,
        ]);

        $this->postJson('/client/heartbeat', [
            'installation_id' => $installationId,
            'computer_name' => 'LAB-PC-01-RENAMED',
            'version' => '2.0.6',
            'interactive' => false,
        ])->assertOk()->assertJson(['latest_version' => '2.0.6', 'update_required' => false]);

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

    public function test_new_client_pairing_is_approved_by_default_and_reports_inventory(): void
    {
        Setting::query()->create(Setting::defaults());
        $installationId = (string) Str::uuid();
        $response = $this->postJson('/client/heartbeat', [
            'installation_id' => $installationId,
            'computer_name' => 'LAB-PAIRING',
            'version' => '2.0.0',
            'interactive' => true,
            'pairing_capable' => true,
            'inventory' => [
                'os' => 'Windows 11 Pro',
                'ram_gb' => 16,
                'disk_free_gb' => 120.5,
                'roblox_studio' => true,
            ],
        ])->assertOk()->assertJson(['approved' => true, 'pairing_status' => 'approved']);
        $token = $response->json('client_token');
        $this->assertNotEmpty($token);

        $computer = ClientComputer::query()->where('installation_id', $installationId)->firstOrFail();
        $this->assertSame('Windows 11 Pro', $computer->inventory['os']);
        $headers = ['Authorization' => 'Bearer '.$token];
        $this->withHeaders($headers)->postJson('/client/heartbeat', [
            'installation_id' => $installationId,
            'computer_name' => 'LAB-PAIRING',
            'version' => '2.0.1',
            'interactive' => false,
            'pairing_capable' => true,
        ])->assertOk()->assertJsonPath('client_token', null);
        $this->withHeader('Authorization', '')->postJson('/client/heartbeat', [
            'installation_id' => $installationId,
            'computer_name' => 'LAB-PAIRING',
            'pairing_capable' => true,
        ])->assertUnauthorized();
        $this->withHeaders($headers)->getJson('/client/config?installation_id='.$installationId)
            ->assertOk()
            ->assertJsonPath('schedules', []);

        $admin = User::factory()->create();
        $this->actingAs($admin)->put(route('computers.update', $computer), [
            'group_name' => 'LAB-B',
        ])->assertRedirect(route('computers'));
        $this->assertFalse($computer->fresh()->approved);
        $this->withHeaders($headers)->getJson('/client/config?installation_id='.$installationId)->assertForbidden();

        $this->actingAs($admin)->put(route('computers.update', $computer), [
            'approved' => '1',
            'group_name' => 'LAB-B',
        ])->assertRedirect(route('computers'));
        $this->withHeaders($headers)->getJson('/client/config?installation_id='.$installationId)
            ->assertOk()
            ->assertJsonPath('schedules', []);
    }

    public function test_admin_can_create_multiple_targeted_schedules_with_exam_mode(): void
    {
        config()->set('schoolsync.exam_mode_enabled', true);
        [$computer, $headers] = $this->authenticatedClient('LAB-JADWAL', 'LAB-C');
        Setting::query()->create(Setting::defaults());
        $admin = User::factory()->create();

        $this->actingAs($admin)->post(route('schedules.store'), [
            'name' => 'Ujian Kelas 9',
            'schedule_day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'browser' => "ujian.example.com\nmateri ujian",
            'launcher' => ['edge'],
            'shutdown_warning' => 10,
            'target' => 'group:LAB-C',
            'exam_enabled' => '1',
            'blocked_processes' => "discord.exe\nsteam",
            'enabled' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect(route('schedules'));

        $schedule = ClassSchedule::query()->firstOrFail();
        $this->assertSame(['discord', 'steam'], $schedule->blocked_processes);
        $this->assertDatabaseHas('remote_commands', [
            'action' => 'refresh_exam_policy',
            'target_type' => 'group',
        ]);
        $this->withHeaders($headers)->getJson('/client/config?installation_id='.$computer->installation_id)
            ->assertOk()
            ->assertJsonFragment([
                'name' => 'Ujian Kelas 9',
                'exam' => ['enabled' => true, 'blocked_processes' => ['discord', 'steam', 'roblox']],
            ]);

        $this->actingAs($admin)->patch(route('schedules.exam-mode.update', $schedule), [
            'exam_enabled' => '0',
        ])->assertSessionHasNoErrors()->assertRedirect(route('schedules'));
        $this->assertFalse($schedule->fresh()->exam_enabled);
        $this->assertSame(2, \App\Models\RemoteCommand::query()->where('action', 'refresh_exam_policy')->count());
        $this->withHeaders($headers)->getJson('/client/config?installation_id='.$computer->installation_id)
            ->assertOk()
            ->assertJsonPath('schedules.0.exam.enabled', false)
            ->assertJsonPath('schedules.0.exam.blocked_processes', ['discord', 'steam']);

        $this->actingAs($admin)->patch(route('schedules.exam-mode.update', $schedule), [
            'exam_enabled' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect(route('schedules'));
        $this->assertTrue($schedule->fresh()->exam_enabled);
    }

    public function test_exam_mode_is_temporarily_disabled_for_admin_and_clients(): void
    {
        [$computer, $headers] = $this->authenticatedClient('LAB-NO-EXAM', 'LAB-D');
        Setting::query()->create(Setting::defaults());
        $schedule = ClassSchedule::query()->create([
            'name' => 'Jadwal lama',
            'schedule_day' => 'Monday',
            'start_time' => '08:00',
            'end_time' => '10:00',
            'browser' => [],
            'launcher' => [],
            'shutdown_enabled' => false,
            'shutdown_warning' => 10,
            'target_type' => 'group',
            'target_value' => ['LAB-D'],
            'exam_enabled' => true,
            'blocked_processes' => ['discord'],
            'enabled' => true,
        ]);

        $this->withHeaders($headers)->getJson('/client/config?installation_id='.$computer->installation_id)
            ->assertOk()
            ->assertJsonPath('schedules.0.exam.enabled', false)
            ->assertJsonPath('schedules.0.exam.blocked_processes', ['discord']);

        $admin = User::factory()->create();
        $this->actingAs($admin)->get(route('schedules'))
            ->assertOk()
            ->assertSee('Mode ujian nonaktif sementara')
            ->assertDontSee('Aktifkan sekarang');
        $this->actingAs($admin)->patch(route('schedules.exam-mode.update', $schedule), [
            'exam_enabled' => '1',
        ])->assertSessionHasNoErrors()->assertRedirect(route('schedules'));
        $this->assertFalse($schedule->fresh()->exam_enabled);
    }

    public function test_old_settings_page_redirects_to_schedules_and_shutdown_exclusions_live_there(): void
    {
        ClientComputer::query()->create([
            'installation_id' => (string) Str::uuid(),
            'computer_name' => 'LAB-GURU',
            'approved' => true,
            'last_seen_at' => now(),
        ]);
        Setting::query()->create(Setting::defaults());
        $admin = User::factory()->create();

        $this->actingAs($admin)->get('/settings')->assertRedirect('/schedules');
        $this->actingAs($admin)->get(route('schedules'))
            ->assertOk()
            ->assertSee('Pengecualian shutdown')
            ->assertSee('Mode ujian')
            ->assertDontSee('Pengaturan kelas');

        $this->actingAs($admin)->put(route('schedules.exclusions.update'), [
            'shutdown_excluded_computers' => ['LAB-GURU'],
            'shutdown_excluded_manual' => "SERVER-KELAS\nlab-guru",
        ])->assertSessionHasNoErrors()->assertRedirect(route('schedules'));

        $this->assertSame(
            ['LAB-GURU', 'SERVER-KELAS'],
            Setting::query()->firstOrFail()->shutdown_excluded_computers
        );
    }

    private function authenticatedClient(string $name = 'LAB-PC-TEST', ?string $group = null): array
    {
        $token = Str::random(64);
        $computer = ClientComputer::query()->create([
            'installation_id' => (string) Str::uuid(),
            'computer_name' => $name,
            'group_name' => $group,
            'client_token_hash' => hash('sha256', $token),
            'approved' => true,
            'approved_at' => now(),
            'version' => '1.8.0',
            'last_seen_at' => now(),
        ]);

        return [$computer, ['Authorization' => 'Bearer '.$token]];
    }
}
