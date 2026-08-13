<?php

namespace App\Http\Controllers;

use App\Models\ClientComputer;
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

        return view('dashboard', [
            'setting' => $setting,
            'projects' => $projects,
            'days' => $this->dayLabels(),
            ...$this->computerStatusData(),
        ]);
    }

    public function computerStatus(): JsonResponse
    {
        return response()->json($this->computerStatusData())
            ->header('Cache-Control', 'no-store, max-age=0');
    }

    public function settingsPage(): View
    {
        $setting = Setting::query()->with('project')->firstOrCreate([], Setting::defaults());
        $projects = Project::query()->latest('updated_at')->get();

        return view('settings', [
            'setting' => $setting,
            'robloxProjects' => $projects->filter(
                fn (Project $project): bool => in_array(strtolower(pathinfo($project->filename, PATHINFO_EXTENSION)), ['rbxl', 'rbxlx'], true)
            ),
            'days' => $this->dayLabels(),
            'launchers' => [
                'edge' => 'Microsoft Edge', 'roblox' => 'Roblox Studio', 'vscode' => 'Visual Studio Code',
                'scratch' => 'Scratch Desktop', 'construct' => 'Construct 3', 'python' => 'Python IDLE',
            ],
        ]);
    }

    public function filesPage(): View
    {
        return view('files', [
            'setting' => Setting::query()->with('project')->firstOrCreate([], Setting::defaults()),
            'projects' => Project::query()->latest('updated_at')->get(),
        ]);
    }

    public function connectionPage(): View
    {
        return view('connection');
    }

    public function securityPage(): View
    {
        return view('security');
    }

    public function openNow(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2000', 'not_regex:/[\x00-\x1F\x7F\"]/'],
        ]);
        $url = $this->normalizeHttpUrl($validated['url'], 'url');

        RemoteCommand::query()->where('expires_at', '<', now()->subDay())->delete();
        RemoteCommand::query()->create([
            'action' => 'open_edge',
            'payload' => ['url' => $url],
            'expires_at' => now()->addMinutes(10),
        ]);

        return redirect()->route('dashboard')->with('success', 'Perintah buka Edge sudah dikirim ke komputer SchoolSync yang aktif.');
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
        ]);

        $browser = array_values(array_filter(array_map(
            'trim',
            preg_split('/\R/u', (string) ($validated['browser'] ?? '')) ?: []
        )));
        $browser = array_values(array_unique(array_map(
            fn (string $url): string => $this->normalizeHttpUrl($url, 'browser'),
            $browser
        )));

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
        ]);

        return redirect()->route('settings')->with('success', 'Konfigurasi berhasil disimpan dan siap dibaca komputer lab.');
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
