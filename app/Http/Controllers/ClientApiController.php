<?php

namespace App\Http\Controllers;

use App\Models\ClientComputer;
use App\Models\Project;
use App\Models\RemoteCommand;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
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
        ]);

        $updates = [
            'computer_name' => $validated['computer_name'],
            'version' => $validated['version'] ?? null,
            'ip_address' => $request->ip(),
            'last_seen_at' => now(),
        ];
        if ($request->boolean('interactive')) {
            $updates['last_interactive_at'] = now();
        }

        $computer = ClientComputer::query()->updateOrCreate(
            ['installation_id' => $validated['installation_id']],
            $updates
        );

        ClientComputer::query()->where('last_seen_at', '<', now()->subDays(90))->delete();

        return response()->json([
            'ok' => true,
            'computer_id' => $computer->id,
            'active_for_seconds' => 90,
        ])->header('Cache-Control', 'no-store, max-age=0');
    }

    public function download(Request $request): Response
    {
        if ($request->filled('client')) {
            return $this->clientFile((string) $request->query('client'));
        }

        if ($request->filled('file')) {
            return $this->projectFile((string) $request->query('file'));
        }

        abort(404);
    }

    public function files(): JsonResponse
    {
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

    public function commands(Request $request): JsonResponse
    {
        $after = max(0, $request->integer('after'));
        $commands = RemoteCommand::query()
            ->where('id', '>', $after)
            ->where('expires_at', '>', now())
            ->orderBy('id')
            ->limit(100)
            ->get(['id', 'action', 'payload', 'created_at']);

        return response()->json(['commands' => $commands])
            ->header('Cache-Control', 'no-store, max-age=0')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function config(): JsonResponse
    {
        $setting = Setting::query()->with('project')->first();
        $values = $setting?->toArray() ?? Setting::defaults();

        return response()->json([
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
            ],
        ])->header('Cache-Control', 'no-store, max-age=0');
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
}
