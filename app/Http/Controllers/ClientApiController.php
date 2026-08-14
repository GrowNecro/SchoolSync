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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class ClientApiController extends Controller
{
    public function heartbeat(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'installation_id' => ['required', 'uuid'],
            'computer_name' => ['required', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/'],
            'version' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9._-]+$/'],
            'interactive' => ['nullable', 'boolean'],
            'pairing_capable' => ['nullable', 'boolean'],
            'inventory' => ['nullable', 'array'],
            'inventory.os' => ['nullable', 'string', 'max:200'],
            'inventory.ram_gb' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'inventory.disk_free_gb' => ['nullable', 'numeric', 'min:0'],
            'inventory.roblox_studio' => ['nullable', 'boolean'],
            'inventory.roblox_version' => ['nullable', 'string', 'max:100'],
        ]);

        $computer = ClientComputer::query()->createOrFirst(
            ['installation_id' => $validated['installation_id']],
            [
                'computer_name' => $validated['computer_name'],
                'approved' => true,
                'approved_at' => now(),
                'last_seen_at' => now(),
            ]
        );
        $isNewComputer = $computer->wasRecentlyCreated;

        [$computer, $issuedToken] = DB::transaction(function () use ($computer, $request, $validated): array {
            $computer = ClientComputer::query()->lockForUpdate()->findOrFail($computer->id);
            if ($computer->client_token_hash) {
                $providedToken = $request->bearerToken();
                abort_unless($providedToken && hash_equals($computer->client_token_hash, hash('sha256', $providedToken)), 401);
            }

            $updates = [
                'computer_name' => $validated['computer_name'],
                'version' => $validated['version'] ?? null,
                'ip_address' => $request->ip(),
                'last_seen_at' => now(),
                'inventory' => $this->mergeInventory($computer, $validated['inventory'] ?? [], $request->boolean('interactive')),
            ];
            if ($request->boolean('interactive')) {
                $updates['last_interactive_at'] = now();
            }

            $issuedToken = null;
            if ($request->boolean('pairing_capable') && ! $computer->client_token_hash) {
                $issuedToken = Str::random(64);
                $updates['client_token_hash'] = hash('sha256', $issuedToken);
            }
            $computer->update($updates);

            return [$computer, $issuedToken];
        });

        ClientComputer::query()->where('last_seen_at', '<', now()->subDays(90))->delete();

        return response()->json([
            'ok' => true,
            'computer_id' => $computer->id,
            'active_for_seconds' => 180,
            'approved' => $computer->approved,
            'pairing_status' => $computer->approved ? 'approved' : 'pending',
            'client_token' => $issuedToken,
            'new_computer' => $isNewComputer,
        ])->header('Cache-Control', 'no-store, max-age=0');
    }

    public function download(Request $request): Response
    {
        if ($request->filled('client')) {
            return $this->clientFile((string) $request->query('client'));
        }

        if ($request->filled('file')) {
            $this->authenticateClient($request);
            return $this->projectFile((string) $request->query('file'));
        }

        abort(404);
    }

    public function files(Request $request): JsonResponse
    {
        $this->authenticateClient($request);
        $files = Project::query()->orderBy('filename')->get()->map(function (Project $project): array {
            if (! $project->sha256 && Storage::disk('local')->exists($project->path)) {
                $project->update([
                    'sha256' => hash_file('sha256', Storage::disk('local')->path($project->path)),
                    'extract' => strtolower(pathinfo($project->filename, PATHINFO_EXTENSION)) === 'zip',
                ]);
            }

            return [
                'name' => $project->filename,
                'size' => $project->size,
                'sha256' => $project->sha256,
                'extract' => $project->extract,
            ];
        });

        return response()->json(['files' => $files])
            ->header('Cache-Control', 'no-store, max-age=0')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function uploadFile(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'installation_id' => ['required', 'uuid'],
            'computer_name' => ['required', 'string', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/'],
            'relative_path' => ['required', 'string', 'max:500', 'not_regex:/[\x00-\x1F\x7F]/'],
            'sha256' => ['required', 'string', 'size:64', 'regex:/^[a-fA-F0-9]{64}$/'],
            'file' => ['required', 'file', 'max:102400'],
        ]);

        $computer = $this->authenticateClient($request);
        abort_unless($computer->computer_name === $validated['computer_name'], 403);

        $relativePath = $this->normalizeRelativePath($validated['relative_path']);
        $uploadedFile = $request->file('file');
        $actualHash = hash_file('sha256', $uploadedFile->getRealPath());
        if (! hash_equals(strtolower($validated['sha256']), strtolower($actualHash))) {
            throw ValidationException::withMessages(['file' => 'Checksum file tidak sesuai.']);
        }

        $computer->update(['ip_address' => $request->ip(), 'last_seen_at' => now()]);
        $computerFolder = $this->safeFolderName($computer->computer_name).'-'.$computer->installation_id;
        $syncedFile = ClientSyncedFile::query()->firstOrNew([
            'client_computer_id' => $computer->id,
            'relative_path' => $relativePath,
        ]);
        $existingVersion = $syncedFile->exists
            ? $syncedFile->versions()->where('sha256', strtolower($actualHash))->first()
            : null;
        $storagePath = $existingVersion?->storage_path
            ?? 'client-sync/'.$computerFolder.'/versions/'.now()->format('Ymd-His').'-'.Str::random(8).'/'.$relativePath;

        if (! $existingVersion) {
            $stream = fopen($uploadedFile->getRealPath(), 'rb');
            try {
                Storage::disk('local')->put($storagePath, $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }

        $syncedFile->fill([
            'storage_path' => $storagePath,
            'size' => $uploadedFile->getSize(),
            'sha256' => strtolower($actualHash),
            'synced_at' => now(),
        ])->save();
        $syncedFile->versions()->firstOrCreate(
            ['sha256' => strtolower($actualHash)],
            ['storage_path' => $storagePath, 'size' => $uploadedFile->getSize()]
        );

        return response()->json(['ok' => true, 'path' => $relativePath])
            ->header('Cache-Control', 'no-store, max-age=0');
    }

    public function commands(Request $request): JsonResponse
    {
        $computer = $this->authenticateClient($request);
        $after = max(0, $request->integer('after'));
        $candidates = RemoteCommand::query()
            ->where('id', '>', $after)
            ->where('expires_at', '>', now())
            ->orderBy('id')
            ->limit(300)
            ->get(['id', 'action', 'payload', 'target_type', 'target_value', 'created_at']);
        $cursor = max($after, (int) ($candidates->max('id') ?? $after));
        $commands = $candidates
            ->filter(fn (RemoteCommand $command): bool => $this->commandTargetsComputer($command, $computer))
            ->take(100)
            ->values();

        foreach ($commands as $command) {
            CommandExecution::query()->firstOrCreate([
                'remote_command_id' => $command->id,
                'client_computer_id' => $computer->id,
            ]);
        }

        return response()->json(['commands' => $commands, 'cursor' => $cursor])
            ->header('Cache-Control', 'no-store, max-age=0')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function acknowledgeCommand(Request $request): JsonResponse
    {
        $computer = $this->authenticateClient($request);
        $validated = $request->validate([
            'command_id' => ['required', 'integer', 'exists:remote_commands,id'],
            'status' => ['required', 'in:success,failed,skipped'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);
        $command = RemoteCommand::query()->findOrFail($validated['command_id']);
        abort_unless($this->commandTargetsComputer($command, $computer), 403);

        $execution = CommandExecution::query()->firstOrNew([
            'remote_command_id' => $command->id,
            'client_computer_id' => $computer->id,
        ]);
        if (! $execution->exists || $execution->status !== 'success' || $validated['status'] === 'success') {
            $execution->fill([
                'status' => $validated['status'],
                'message' => $validated['message'] ?? null,
                'executed_at' => now(),
            ])->save();
        }

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store, max-age=0');
    }

    public function downloadClientFileVersion(Request $request, ClientFileVersion $clientFileVersion): BinaryFileResponse
    {
        $computer = $this->authenticateClient($request);
        $clientFileVersion->loadMissing('syncedFile');
        abort_unless($clientFileVersion->syncedFile?->client_computer_id === $computer->id, 403);
        abort_unless(Storage::disk('local')->exists($clientFileVersion->storage_path), 404);

        return response()->download(
            Storage::disk('local')->path($clientFileVersion->storage_path),
            basename($clientFileVersion->syncedFile->relative_path),
            ['X-Content-Type-Options' => 'nosniff']
        );
    }

    public function config(Request $request): JsonResponse
    {
        $computer = $request->filled('installation_id') ? $this->authenticateClient($request) : null;
        $setting = Setting::query()->with('project')->first();
        $values = $setting?->toArray() ?? Setting::defaults();

        $response = [
            'schedule' => [
                'day' => $values['schedule_day'],
                'start' => substr((string) $values['start_time'], 0, 5),
                'end' => substr((string) $values['end_time'], 0, 5),
            ],
            'project' => $setting?->project?->filename ?? '',
            'browser' => $values['browser'] ?? [],
            'launcher' => $values['launcher'] ?? [],
            'shutdown' => [
                'enabled' => (bool) ($values['shutdown_enabled'] ?? false),
                'warning' => (int) ($values['shutdown_warning'] ?? 10),
                'excluded_computers' => array_values($values['shutdown_excluded_computers'] ?? []),
            ],
        ];

        if (! $computer) {
            return response()->json($response)
                ->header('Cache-Control', 'no-store, max-age=0')
                ->header('X-SchoolSync-Legacy', 'upgrade-required');
        }

        $schedules = ClassSchedule::query()->with('project')->where('enabled', true)->orderBy('start_time')->get()
            ->filter(fn (ClassSchedule $schedule): bool => $this->scheduleTargetsComputer($schedule, $computer))
            ->map(fn (ClassSchedule $schedule): array => [
                'id' => $schedule->id,
                'name' => $schedule->name,
                'schedule' => [
                    'day' => $schedule->schedule_day,
                    'start' => substr((string) $schedule->start_time, 0, 5),
                    'end' => substr((string) $schedule->end_time, 0, 5),
                ],
                'project' => $schedule->project?->filename ?? '',
                'browser' => array_values($schedule->browser ?? []),
                'launcher' => array_values($schedule->launcher ?? []),
                'shutdown' => [
                    'enabled' => $schedule->shutdown_enabled,
                    'warning' => $schedule->shutdown_warning,
                    'excluded_computers' => array_values($values['shutdown_excluded_computers'] ?? []),
                ],
                'exam' => [
                    'enabled' => $schedule->exam_enabled,
                    'blocked_processes' => array_values(array_unique([
                        ...($schedule->blocked_processes ?? []),
                        ...($schedule->exam_enabled ? ['roblox'] : []),
                    ])),
                ],
            ])->values();
        $response['schedules'] = $schedules;

        return response()->json($response)->header('Cache-Control', 'no-store, max-age=0');
    }

    private function projectFile(string $requestedFilename): BinaryFileResponse
    {
        $normalizedName = str_replace('\\', '/', $requestedFilename);
        $filename = basename($normalizedName);
        abort_unless($normalizedName === $filename && $filename !== '', 404);
        $project = Project::query()->where('filename', $filename)->firstOrFail();
        abort_unless(Storage::disk('local')->exists($project->path), 404);

        return response()->download(Storage::disk('local')->path($project->path), $project->filename, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    private function clientFile(string $requestedFilename): Response
    {
        $normalizedName = str_replace('\\', '/', $requestedFilename);
        $filename = basename($normalizedName);
        abort_unless($normalizedName === $filename && $filename !== '', 404);
        abort_unless(in_array($filename, ['SchoolSync.bat', 'SchoolSync.ps1', 'version.txt'], true), 404);
        $path = base_path('tools/'.$filename);
        abort_unless(is_file($path), 503);

        return response(file_get_contents($path), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function installer(): Response
    {
        $template = base_path('tools/Install.bat');
        abort_unless(is_file($template), 503);
        $content = str_replace('__SCHOOLSYNC_PANEL_URL__', url('/'), file_get_contents($template));

        return response($content, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="Install-SchoolSync.bat"',
            'Cache-Control' => 'no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        $segments = explode('/', $path);
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
            throw ValidationException::withMessages(['relative_path' => 'Path file tidak valid.']);
        }

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw ValidationException::withMessages(['relative_path' => 'Path file tidak valid.']);
            }
        }

        return implode('/', $segments);
    }

    private function safeFolderName(string $name): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($name)) ?: 'computer';

        return trim($safe, '.-') ?: 'computer';
    }

    private function authenticateClient(Request $request): ClientComputer
    {
        $installationId = (string) ($request->input('installation_id') ?: $request->query('installation_id'));
        abort_unless(Str::isUuid($installationId), 401);
        $computer = ClientComputer::query()->where('installation_id', $installationId)->firstOrFail();
        abort_unless($computer->approved, 403);
        $token = $request->bearerToken();
        abort_unless($token && $computer->client_token_hash && hash_equals($computer->client_token_hash, hash('sha256', $token)), 401);

        return $computer;
    }

    private function commandTargetsComputer(RemoteCommand $command, ClientComputer $computer): bool
    {
        $targets = array_map('strval', $command->target_value ?? []);

        return match ($command->target_type) {
            'computer' => in_array($computer->installation_id, $targets, true),
            'group' => $computer->group_name !== null && in_array($computer->group_name, $targets, true),
            default => true,
        };
    }

    private function scheduleTargetsComputer(ClassSchedule $schedule, ClientComputer $computer): bool
    {
        $targets = array_map('strval', $schedule->target_value ?? []);

        return match ($schedule->target_type) {
            'computer' => in_array($computer->installation_id, $targets, true),
            'group' => $computer->group_name !== null && in_array($computer->group_name, $targets, true),
            default => true,
        };
    }

    private function mergeInventory(?ClientComputer $computer, array $inventory, bool $interactive): array
    {
        $current = $computer?->inventory ?? [];
        $merged = array_merge($current, $inventory);
        if (! $interactive && ! empty($current['roblox_studio']) && empty($inventory['roblox_studio'])) {
            $merged['roblox_studio'] = true;
            $merged['roblox_version'] = $current['roblox_version'] ?? null;
        }

        return $merged;
    }
}
