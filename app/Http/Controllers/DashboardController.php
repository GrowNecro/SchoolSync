<?php

namespace App\Http\Controllers;

use App\Models\ClientComputer;
use App\Models\ClientFileVersion;
use App\Models\ClientSyncedFile;
use App\Models\CommandExecution;
use App\Models\ClassSchedule;
use App\Models\Project;
use App\Models\RemoteCommand;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DashboardController extends Controller
{
    private const ACTIVE_COMPUTER_SECONDS = 90;

    private const DAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    private const LAUNCHERS = ['edge', 'roblox', 'vscode', 'scratch', 'construct', 'python'];

    public function index(): View
    {
        $setting = Setting::query()->with('project')->firstOrCreate([], Setting::defaults());
        $projects = Project::query()->latest('updated_at')->get();
        $activeSchedules = ClassSchedule::query()->where('enabled', true)->get();

        return view('dashboard', [
            'setting' => $setting,
            'projects' => $projects,
            'days' => $this->dayLabels(),
            'scheduleCount' => $activeSchedules->count(),
            'scheduledLauncherCount' => $activeSchedules->pluck('launcher')->flatten()->filter()->unique()->count(),
            'scheduledBrowserCount' => $activeSchedules->pluck('browser')->flatten()->filter()->unique()->count(),
            'scheduledProjectCount' => $activeSchedules->pluck('project_id')->filter()->unique()->count(),
            'commandTargets' => $this->commandTargetOptions(),
            'recentCommands' => RemoteCommand::query()->with('executions')->latest()->limit(8)->get(),
            ...$this->computerStatusData(),
        ]);
    }

    public function computerStatus(): JsonResponse
    {
        return response()->json($this->computerStatusData())
            ->header('Cache-Control', 'no-store, max-age=0');
    }

    public function schedulesPage(): View
    {
        return view('schedules', [
            'setting' => Setting::query()->firstOrCreate([], Setting::defaults()),
            'schedules' => ClassSchedule::query()->with('project')->orderBy('schedule_day')->orderBy('start_time')->get(),
            'projects' => Project::query()->orderBy('filename')->get(),
            'days' => $this->dayLabels(),
            'launchers' => [
                'edge' => 'Microsoft Edge', 'roblox' => 'Roblox Studio', 'vscode' => 'Visual Studio Code',
                'scratch' => 'Scratch Desktop', 'construct' => 'Construct 3', 'python' => 'Python IDLE',
            ],
            'commandTargets' => $this->commandTargetOptions(),
            'computerNames' => ClientComputer::query()->distinct()->orderBy('computer_name')->pluck('computer_name'),
        ]);
    }

    public function updateShutdownExclusions(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'shutdown_excluded_computers' => ['nullable', 'array', 'max:200'],
            'shutdown_excluded_computers.*' => ['string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/'],
            'shutdown_excluded_manual' => ['nullable', 'string', 'max:10000'],
        ]);
        $excludedComputers = $this->uniqueComputerNames(array_merge(
            $validated['shutdown_excluded_computers'] ?? [],
            preg_split('/\R/u', (string) ($validated['shutdown_excluded_manual'] ?? '')) ?: []
        ));

        Setting::query()->firstOrCreate([], Setting::defaults())->update([
            'shutdown_excluded_computers' => $excludedComputers,
        ]);

        return redirect()->route('schedules')->with('success', 'Pengecualian shutdown berhasil disimpan.');
    }

    public function storeSchedule(Request $request): RedirectResponse
    {
        ClassSchedule::query()->create($this->validatedScheduleData($request));

        return redirect()->route('schedules')->with('success', 'Jadwal baru berhasil ditambahkan.');
    }

    public function updateSchedule(Request $request, ClassSchedule $schedule): RedirectResponse
    {
        $schedule->update($this->validatedScheduleData($request));

        return redirect()->route('schedules')->with('success', "Jadwal {$schedule->name} berhasil diperbarui.");
    }

    public function updateScheduleExamMode(Request $request, ClassSchedule $schedule): RedirectResponse
    {
        $validated = $request->validate([
            'exam_enabled' => ['required', 'boolean'],
        ]);
        $enabled = (bool) $validated['exam_enabled'];
        $schedule->update(['exam_enabled' => $enabled]);

        return redirect()->route('schedules')->with(
            'success',
            'Mode ujian '.$schedule->name.' '.($enabled ? 'diaktifkan' : 'dinonaktifkan').'. Klien menerapkan perubahan maksimal sekitar 10 detik.'
        );
    }

    public function deleteSchedule(ClassSchedule $schedule): RedirectResponse
    {
        $name = $schedule->name;
        $schedule->delete();

        return redirect()->route('schedules')->with('success', "Jadwal {$name} berhasil dihapus.");
    }

    public function filesPage(): View
    {
        return view('files', [
            'setting' => Setting::query()->with('project')->firstOrCreate([], Setting::defaults()),
            'projects' => Project::query()->latest('updated_at')->get(),
        ]);
    }

    public function clientFilesPage(): View
    {
        $computers = ClientComputer::query()
            ->whereHas('syncedFiles')
            ->with(['syncedFiles' => fn ($query) => $query->with('versions')->latest('synced_at')])
            ->orderBy('computer_name')
            ->get();

        return view('client-files', ['computers' => $computers]);
    }

    public function downloadClientFile(ClientSyncedFile $clientSyncedFile): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        abort_unless(Storage::disk('local')->exists($clientSyncedFile->storage_path), 404);

        return response()->download(
            Storage::disk('local')->path($clientSyncedFile->storage_path),
            basename($clientSyncedFile->relative_path),
            ['X-Content-Type-Options' => 'nosniff']
        );
    }

    public function downloadClientFileVersion(ClientFileVersion $clientFileVersion): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $clientFileVersion->loadMissing('syncedFile');
        abort_unless(Storage::disk('local')->exists($clientFileVersion->storage_path), 404);

        return response()->download(
            Storage::disk('local')->path($clientFileVersion->storage_path),
            basename($clientFileVersion->syncedFile->relative_path),
            ['X-Content-Type-Options' => 'nosniff']
        );
    }

    public function restoreClientFileVersion(ClientFileVersion $clientFileVersion): RedirectResponse
    {
        $clientFileVersion->loadMissing('syncedFile.computer');
        $syncedFile = $clientFileVersion->syncedFile;
        abort_unless($syncedFile && $syncedFile->computer, 404);
        abort_unless(Storage::disk('local')->exists($clientFileVersion->storage_path), 404);

        $syncedFile->update([
            'storage_path' => $clientFileVersion->storage_path,
            'size' => $clientFileVersion->size,
            'sha256' => $clientFileVersion->sha256,
            'synced_at' => now(),
        ]);
        $this->queueCommand('restore_file', [
            'version_id' => $clientFileVersion->id,
            'relative_path' => $syncedFile->relative_path,
            'sha256' => $clientFileVersion->sha256,
        ], 'computer:'.$syncedFile->computer->installation_id, 10080);

        return redirect()->route('client-files')->with('success', "Versi {$syncedFile->relative_path} dijadwalkan untuk dipulihkan ke {$syncedFile->computer->computer_name}.");
    }

    public function connectionPage(): View
    {
        return view('connection');
    }

    public function computersPage(): View
    {
        return view('computers', [
            'computers' => ClientComputer::query()->latest('last_seen_at')->get(),
        ]);
    }

    public function updateComputer(Request $request, ClientComputer $computer): RedirectResponse
    {
        $validated = $request->validate([
            'group_name' => ['nullable', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/'],
        ]);
        $approved = $request->boolean('approved');
        $updates = [
            'group_name' => trim((string) ($validated['group_name'] ?? '')) ?: null,
            'approved' => $approved,
            'approved_at' => $approved ? ($computer->approved_at ?? now()) : null,
        ];
        if ($request->boolean('reset_pairing')) {
            $updates['client_token_hash'] = null;
        }
        $computer->update($updates);

        return redirect()->route('computers')->with('success', "Komputer {$computer->computer_name} berhasil diperbarui.");
    }

    public function commandActivityPage(): View
    {
        return view('command-activity', [
            'commands' => RemoteCommand::query()->with(['executions.computer'])->latest()->limit(100)->get(),
        ]);
    }

    public function securityPage(): View
    {
        return view('security');
    }

    public function openNow(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2000', 'not_regex:/[\x00-\x1F\x7F\"]/'],
            'target' => ['required', 'string', 'max:200'],
        ]);
        $url = $this->normalizeHttpUrl($validated['url'], 'url');

        $this->queueCommand('open_edge', ['url' => $url], $validated['target']);

        return redirect()->route('dashboard')->with('success', 'Perintah buka Edge sudah dikirim ke komputer SchoolSync yang aktif.');
    }

    public function openAppNow(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'app' => ['required', Rule::in(self::LAUNCHERS)],
            'target' => ['required', 'string', 'max:200'],
        ]);

        $payload = ['app' => $validated['app']];
        if ($validated['app'] === 'roblox') {
            $setting = Setting::query()->with('project')->firstOrCreate([], Setting::defaults());
            $payload['project'] = $setting->project?->filename;
        }

        $this->queueCommand('open_app', $payload, $validated['target']);

        return redirect()->route('dashboard')->with('success', 'Perintah buka aplikasi sudah dikirim ke komputer SchoolSync yang aktif.');
    }

    public function shutdownNow(Request $request): RedirectResponse
    {
        $validated = $request->validate(['target' => ['required', 'string', 'max:200']]);
        $setting = Setting::query()->firstOrCreate([], Setting::defaults());
        $excluded = array_values($setting->shutdown_excluded_computers ?? []);
        $this->queueCommand('shutdown', ['excluded_computers' => $excluded], $validated['target']);

        $message = 'Perintah shutdown sudah dikirim.';
        if ($excluded !== []) {
            $message .= ' '.count($excluded).' komputer dalam daftar pengecualian tetap menyala.';
        }

        return redirect()->route('dashboard')->with('success', $message);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'schedule_day' => ['required', Rule::in(self::DAYS)],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'browser' => ['nullable', 'string', 'max:5000'],
            'launcher' => ['nullable', 'array'],
            'launcher.*' => [Rule::in(self::LAUNCHERS)],
            'shutdown_warning' => ['required', 'integer', 'min:1', 'max:120'],
            'shutdown_excluded_computers' => ['nullable', 'array', 'max:200'],
            'shutdown_excluded_computers.*' => ['string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/'],
            'shutdown_excluded_manual' => ['nullable', 'string', 'max:10000'],
        ]);

        $browser = array_values(array_filter(array_map(
            'trim',
            preg_split('/\R/u', (string) ($validated['browser'] ?? '')) ?: []
        )));
        $browser = array_values(array_unique(array_map(
            fn (string $url): string => $this->normalizeHttpUrl($url, 'browser'),
            $browser
        )));

        $excludedComputers = array_merge(
            $validated['shutdown_excluded_computers'] ?? [],
            preg_split('/\R/u', (string) ($validated['shutdown_excluded_manual'] ?? '')) ?: []
        );
        $excludedComputers = $this->uniqueComputerNames($excludedComputers);

        $setting = Setting::query()->firstOrCreate([], Setting::defaults());
        $setting->update([
            'schedule_day' => $validated['schedule_day'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'project_id' => $validated['project_id'] ?? null,
            'browser' => $browser,
            'launcher' => array_values($validated['launcher'] ?? []),
            'shutdown_enabled' => $request->boolean('shutdown_enabled'),
            'shutdown_warning' => $validated['shutdown_warning'],
            'shutdown_excluded_computers' => $excludedComputers,
        ]);

        ClassSchedule::query()->updateOrCreate(
            ['name' => 'Jadwal utama'],
            [
                'schedule_day' => $validated['schedule_day'],
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'project_id' => $validated['project_id'] ?? null,
                'browser' => $browser,
                'launcher' => array_values($validated['launcher'] ?? []),
                'shutdown_enabled' => $request->boolean('shutdown_enabled'),
                'shutdown_warning' => $validated['shutdown_warning'],
                'target_type' => 'all',
                'target_value' => null,
                'exam_enabled' => false,
                'blocked_processes' => [],
                'enabled' => true,
            ]
        );

        return redirect()->route('schedules')->with('success', 'Konfigurasi berhasil disimpan dan siap dibaca komputer lab.');
    }

    public function uploadProject(Request $request): RedirectResponse
    {
        $request->validate(['project_file' => ['required', 'file', 'max:102400']]);
        $file = $request->file('project_file');
        $originalName = str_replace('\\', '/', $file->getClientOriginalName());
        $filename = basename($originalName);
        if ($filename === '' || in_array($filename, ['.', '..'], true) || mb_strlen($filename) > 200 || preg_match('/[\x00-\x1F\x7F]/', $filename)) {
            throw ValidationException::withMessages(['project_file' => 'Nama file tidak valid atau terlalu panjang.']);
        }

        $size = $file->getSize();
        $path = $file->storeAs('projects', $filename, 'local');
        $sha256 = hash_file('sha256', Storage::disk('local')->path($path));
        $extract = strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'zip';
        Project::query()->updateOrCreate(
            ['filename' => $filename],
            ['path' => $path, 'size' => $size, 'sha256' => $sha256, 'extract' => $extract]
        );

        return redirect()->route('files')->with('success', "File {$filename} berhasil diunggah.");
    }

    public function deleteProject(Project $project): RedirectResponse
    {
        DB::transaction(function () use ($project): void {
            Setting::query()->where('project_id', $project->id)->update(['project_id' => null]);
            Storage::disk('local')->delete($project->path);
            $project->delete();
        });

        return redirect()->route('files')->with('success', 'File berhasil dihapus dari server.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed'],
        ]);
        if (! Hash::check($validated['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages(['current_password' => 'Kata sandi saat ini salah.']);
        }

        $request->user()->update(['password' => $validated['password']]);

        return redirect()->route('security')->with('success', 'Kata sandi admin berhasil diperbarui.');
    }

    private function dayLabels(): array
    {
        return [
            'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu',
            'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu', 'Sunday' => 'Minggu',
        ];
    }

    private function normalizeHttpUrl(string $url, string $field): string
    {
        $url = trim($url);
        $hasSchemePrefix = preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $url) === 1;
        $hasHttpScheme = preg_match('#^https?://#i', $url) === 1;
        $candidateHost = (string) parse_url('https://'.$url, PHP_URL_HOST);
        $looksLikeAddress = ! preg_match('/\s/u', $url) && (
            str_contains($candidateHost, '.') ||
            strtolower($candidateHost) === 'localhost' ||
            filter_var($candidateHost, FILTER_VALIDATE_IP)
        );

        if ($hasSchemePrefix && ! $hasHttpScheme && ! $looksLikeAddress) {
            throw ValidationException::withMessages([$field => "Alamat website tidak valid: {$url}"]);
        }

        if (! $hasHttpScheme && $looksLikeAddress) {
            $url = 'https://'.$url;
        }

        if (! $hasHttpScheme && ! $looksLikeAddress) {
            return 'https://www.google.com/search?q='.rawurlencode($url);
        }

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            throw ValidationException::withMessages([$field => "Alamat website tidak valid: {$url}"]);
        }

        return $url;
    }

    private function queueCommand(string $action, array $payload, string $target, int $expiresInMinutes = 10): RemoteCommand
    {
        RemoteCommand::query()->where('expires_at', '<', now()->subDay())->delete();
        [$targetType, $targetValue] = $this->parseCommandTarget($target);

        return DB::transaction(function () use ($action, $payload, $targetType, $targetValue, $expiresInMinutes): RemoteCommand {
            $command = RemoteCommand::query()->create([
                'action' => $action,
                'payload' => $payload,
                'target_type' => $targetType,
                'target_value' => $targetValue,
                'expires_at' => now()->addMinutes($expiresInMinutes),
            ]);

            $this->targetComputerQuery($targetType, $targetValue)->pluck('id')->each(
                fn (int $computerId) => CommandExecution::query()->create([
                    'remote_command_id' => $command->id,
                    'client_computer_id' => $computerId,
                ])
            );

            return $command;
        });
    }

    private function commandTargetOptions(): array
    {
        $options = [['value' => 'all', 'label' => 'Semua komputer disetujui']];
        ClientComputer::query()->where('approved', true)->whereNotNull('group_name')->distinct()->orderBy('group_name')->pluck('group_name')->each(
            function (string $group) use (&$options): void {
                $options[] = ['value' => 'group:'.$group, 'label' => 'Grup · '.$group];
            }
        );
        ClientComputer::query()->where('approved', true)->orderBy('computer_name')->get()->each(
            function (ClientComputer $computer) use (&$options): void {
                $options[] = ['value' => 'computer:'.$computer->installation_id, 'label' => 'Komputer · '.$computer->computer_name];
            }
        );

        return $options;
    }

    private function validatedScheduleData(Request $request): array
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/'],
            'schedule_day' => ['required', Rule::in(self::DAYS)],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'after:start_time'],
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'browser' => ['nullable', 'string', 'max:5000'],
            'launcher' => ['nullable', 'array'],
            'launcher.*' => [Rule::in(self::LAUNCHERS)],
            'shutdown_warning' => ['required', 'integer', 'min:1', 'max:120'],
            'target' => ['required', 'string', 'max:200'],
            'blocked_processes' => ['nullable', 'string', 'max:5000'],
        ]);

        $browser = array_values(array_unique(array_map(
            fn (string $url): string => $this->normalizeHttpUrl($url, 'browser'),
            array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) ($validated['browser'] ?? '')) ?: [])))
        )));
        $protectedProcesses = ['schoolsync', 'powershell', 'pwsh', 'winlogon', 'lsass', 'csrss', 'services', 'svchost', 'system', 'explorer'];
        $blocked = [];
        foreach (preg_split('/\R/u', (string) ($validated['blocked_processes'] ?? '')) ?: [] as $process) {
            $process = strtolower(pathinfo(trim($process), PATHINFO_FILENAME));
            if ($process === '') {
                continue;
            }
            if (! preg_match('/^[a-z0-9._-]{1,100}$/', $process) || in_array($process, $protectedProcesses, true)) {
                throw ValidationException::withMessages(['blocked_processes' => "Proses tidak aman atau tidak valid: {$process}"]);
            }
            $blocked[$process] = $process;
        }
        [$targetType, $targetValue] = $this->parseCommandTarget($validated['target']);

        return [
            'name' => trim($validated['name']),
            'schedule_day' => $validated['schedule_day'],
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'project_id' => $validated['project_id'] ?? null,
            'browser' => $browser,
            'launcher' => array_values($validated['launcher'] ?? []),
            'shutdown_enabled' => $request->boolean('shutdown_enabled'),
            'shutdown_warning' => $validated['shutdown_warning'],
            'target_type' => $targetType,
            'target_value' => $targetValue,
            'exam_enabled' => $request->boolean('exam_enabled'),
            'blocked_processes' => array_values($blocked),
            'enabled' => $request->boolean('enabled'),
        ];
    }

    private function parseCommandTarget(string $target): array
    {
        if ($target === 'all') {
            return ['all', null];
        }

        [$type, $value] = array_pad(explode(':', $target, 2), 2, '');
        if ($type === 'group' && ClientComputer::query()->where('approved', true)->where('group_name', $value)->exists()) {
            return ['group', [$value]];
        }
        if ($type === 'computer' && ClientComputer::query()->where('approved', true)->where('installation_id', $value)->exists()) {
            return ['computer', [$value]];
        }

        throw ValidationException::withMessages(['target' => 'Target komputer tidak valid atau belum disetujui.']);
    }

    private function targetComputerQuery(string $targetType, ?array $targetValue)
    {
        $query = ClientComputer::query()->where('approved', true);
        if ($targetType === 'group') {
            $query->whereIn('group_name', $targetValue ?? []);
        } elseif ($targetType === 'computer') {
            $query->whereIn('installation_id', $targetValue ?? []);
        }

        return $query;
    }

    private function uniqueComputerNames(array $names): array
    {
        $unique = [];

        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            if (mb_strlen($name) > 100 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
                throw ValidationException::withMessages(['shutdown_excluded_manual' => 'Setiap nama komputer maksimal 100 karakter dan tidak boleh mengandung karakter kontrol.']);
            }

            $key = mb_strtolower($name);
            $unique[$key] ??= $name;
        }

        if (count($unique) > 200) {
            throw ValidationException::withMessages(['shutdown_excluded_manual' => 'Maksimal 200 komputer dapat dikecualikan.']);
        }

        return array_values($unique);
    }

    private function computerStatusData(): array
    {
        $activeSince = now()->subSeconds(self::ACTIVE_COMPUTER_SECONDS);
        $computers = ClientComputer::query()
            ->latest('last_seen_at')
            ->limit(100)
            ->get()
            ->map(function (ClientComputer $computer) use ($activeSince): array {
                $active = $computer->last_seen_at->gte($activeSince);
                $interactive = $active && $computer->last_interactive_at?->gte($activeSince);

                return [
                    'name' => $computer->computer_name,
                    'version' => $computer->version ?: '-',
                    'last_seen' => $computer->last_seen_at->toIso8601String(),
                    'last_seen_label' => $computer->last_seen_at->locale('id')->diffForHumans(),
                    'active' => $active,
                    'interactive' => $interactive,
                ];
            });

        return [
            'active_count' => $computers->where('active', true)->count(),
            'total_count' => $computers->count(),
            'active_for_seconds' => self::ACTIVE_COMPUTER_SECONDS,
            'computers' => $computers->values(),
        ];
    }
}
