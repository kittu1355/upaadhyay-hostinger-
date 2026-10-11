<?php

namespace App\Services;

use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\Job;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use App\Support\ApiResponse;

class ApplicationService
{
    /** Once an application reaches one of these, it can no longer change. */
    private const TERMINAL = ['selected', 'rejected', 'withdrawn'];

    public function __construct(private NotificationService $notifications) {}

    // ------------------------------------------------------------------ candidate
    public function apply(User $candidate, int $jobId, array $d): Application
    {
        $job = Job::published()
            ->whereHas('company', fn ($c) => $c->where('verification_status', 'approved'))
            ->with('company:id,user_id,company_name')
            ->findOrFail($jobId);

        if (! empty($d['resume_id'])) {
            abort_unless($candidate->resumes()->whereKey($d['resume_id'])->exists(), 422, 'Choose one of your own resumes.');
        } else {
            // No resume chosen (the website sends none): attach the primary one so the employer receives it with the application.
            $d['resume_id'] = $candidate->resumes()->where('is_primary', true)->value('id') ?? $candidate->resumes()->latest('id')->value('id');
        }

        $existing = Application::where('job_id', $job->id)->where('candidate_id', $candidate->id)->first();
        if ($existing && $existing->status !== 'withdrawn') {
            throw new HttpResponseException(ApiResponse::error('You have already applied to this job.', 422));
        }

        $app = DB::transaction(function () use ($job, $candidate, $d, $existing) {
            $payload = [
                'resume_id' => $d['resume_id'] ?? null,
                'cover_letter' => $d['cover_letter'] ?? null,
                'status' => 'applied',
                'applied_at' => now(),
            ];
            if ($existing) {                       // re-applying after a withdrawal re-opens the same row
                $existing->update($payload);
                $app = $existing;
            } else {
                $app = Application::create($payload + ['job_id' => $job->id, 'candidate_id' => $candidate->id]);
            }
            $this->log($app, $existing ? 'withdrawn' : null, 'applied', $candidate->id);
            return $app;
        });

        $this->notifications->notify($candidate, 'application_submitted', 'Application submitted',
            "You applied for {$job->title} at {$job->company->company_name}.", ['application_id' => $app->id, 'job_id' => $job->id]);
        $this->notifications->notify($job->company->user_id, 'new_application', 'New application received',
            "{$candidate->name} applied for {$job->title}.", ['application_id' => $app->id, 'job_id' => $job->id]);

        return $app->load($this->relations());
    }

    public function withdraw(User $user, int $id): Application
    {
        $app = Application::findOrFail($id);
        Gate::forUser($user)->authorize('withdraw', $app);
        $this->guardOpen($app);

        $this->transition($app, 'withdrawn', $user->id, null);
        return $app->fresh($this->relations());
    }

    public function listForCandidate(User $user, array $f = [])
    {
        return $user->applications()
            ->with(['job:id,company_id,title,city,state,employment_type,status', 'job.company:id,company_name,logo', 'resume.file'])
            ->when(! empty($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->latest('id')
            ->paginate($this->perPage($f));
    }

    // ------------------------------------------------------------------ company / admin
    /** Company: applicants for its own jobs. Admin: every application. */
    public function listForCompany(User $user, array $f = [])
    {
        $q = Application::query()->with($this->relations(false));

        if (! $user->isAdmin()) {
            $company = $user->company;
            if (! $company) return Application::whereRaw('1 = 0')->paginate($this->perPage($f));
            $q->whereHas('job', fn ($j) => $j->where('company_id', $company->id));
        }
        if (! empty($f['status'])) $q->where('status', $f['status']);
        if (! empty($f['job_id']) && is_numeric($f['job_id'])) $q->where('job_id', (int) $f['job_id']);
        if (! empty($f['search'])) {
            $like = '%'.addcslashes($f['search'], '%_\\').'%';
            $q->whereHas('candidate', fn ($c) => $c->where('name', 'like', $like)->orWhere('email', 'like', $like));
        }
        return $q->latest('id')->paginate($this->perPage($f));
    }

    public function view(User $user, int $id): Application
    {
        $app = Application::findOrFail($id);
        Gate::forUser($user)->authorize('view', $app);

        // The first time the employer opens an application it moves to "under review".
        if ($app->status === 'applied' && $user->isCompany()) {
            $this->transition($app, 'under_review', $user->id, null);
        }
        return $app->fresh($this->relations());
    }

    public function updateStatus(User $user, int $id, string $status, ?string $note): Application
    {
        $app = Application::findOrFail($id);
        Gate::forUser($user)->authorize('updateStatus', $app);
        $this->guardOpen($app);

        $this->transition($app, $status, $user->id, $note);
        return $app->fresh($this->relations());
    }

    /** Company invites a candidate (optionally to a specific job of theirs). */
    public function invite(User $company, int $candidateId, ?int $jobId, ?string $message): void
    {
        $candidate = User::where('role', User::ROLE_CANDIDATE)->where('status', 'active')->findOrFail($candidateId);
        $co = $company->company;
        abort_unless($co, 403, 'Only companies can send invitations.');

        $job = null;
        if ($jobId) $job = $co->jobs()->findOrFail($jobId);

        $this->notifications->notify($candidate, 'invitation', 'You have been invited to apply',
            $message ?: "{$co->company_name} invited you to apply".($job ? " for {$job->title}." : '.'),
            ['company_id' => $co->id, 'job_id' => $job?->id]);
    }

    // ------------------------------------------------------------------ internals
    private function transition(Application $app, string $to, int $byUserId, ?string $note): void
    {
        $from = $app->status;
        DB::transaction(function () use ($app, $from, $to, $byUserId, $note) {
            $app->update(['status' => $to]);
            $this->log($app, $from, $to, $byUserId, $note);
        });

        // Candidates hear about every employer-side change (not about their own withdrawal).
        if ($to !== 'withdrawn') {
            $app->loadMissing('job:id,title');
            $label = str_replace('_', ' ', $to);
            $this->notifications->notify($app->candidate_id, 'application_'.$to, 'Application '.$label,
                "Your application for {$app->job->title} is now: {$label}.",
                ['application_id' => $app->id, 'job_id' => $app->job_id, 'status' => $to]);
        }
    }

    private function log(Application $app, ?string $from, string $to, ?int $by, ?string $note = null): void
    {
        ApplicationStatusHistory::create([
            'application_id' => $app->id, 'from_status' => $from, 'to_status' => $to, 'changed_by' => $by, 'note' => $note,
        ]);
    }

    private function guardOpen(Application $app): void
    {
        if (in_array($app->status, self::TERMINAL, true)) {
            throw new HttpResponseException(ApiResponse::error("This application is already {$app->status} and can't be changed.", 422));
        }
    }

    private function relations(bool $detailed = true): array
    {
        $rel = [
            'job:id,company_id,title,city,state,employment_type',
            'job.company:id,company_name,logo',
            'candidate:id,name,email,phone',
            'candidate.candidateProfile',
            'resume.file',
        ];
        if ($detailed) $rel[] = 'statusHistory';
        return $rel;
    }

    private function perPage(array $f): int
    {
        return min(max((int) ($f['per_page'] ?? 15), 1), 50);
    }
}
