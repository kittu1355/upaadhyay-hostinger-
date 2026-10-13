<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\User;
use App\Services\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/**
 * Main institution <-> sub-branch connection.
 * Flow: sub-branch enters the main's 5-digit code (connect) -> completes its profile -> submits (join)
 * -> the main institution reviews the full profile AND an admin reviews it -> both approved = live.
 */
class BranchController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    private const PUBLIC_COLS = ['id', 'company_name', 'city', 'state', 'industry', 'logo', 'institution_code', 'description', 'website', 'location', 'profile_data'];

    private function mainPayload(Company $c): array
    {
        $pd = $c->profile_data ?? [];
        return [
            'id' => $c->id, 'name' => $c->company_name, 'city' => $c->city, 'state' => $c->state, 'logo' => $c->logo,
            'type' => $pd['type'] ?? $c->industry, 'code' => $c->institution_code,
            'description' => $c->description ?: ($pd['about1'] ?? $pd['about2'] ?? null),
            'website' => $c->website, 'address' => $c->location ?: ($pd['addr'] ?? null),
            'year' => $pd['year'] ?? null, 'verified' => $c->verification_status === 'approved',
        ];
    }

    /** Profile is "complete" when the basics a main institution needs to review are present. */
    private function profileComplete(Company $c): bool
    {
        $pd = $c->profile_data ?? [];
        return filled($c->company_name) && filled($c->city) && (filled($c->phone) || filled($c->email) || filled($pd['rphone'] ?? null) || filled($pd['remail'] ?? null))
            && ! empty($pd);
    }

    /** Public: main institutions a sub-branch can join. With ?code= it returns the full company details of that one institution. */
    public function lookup(Request $r)
    {
        $like = $r->filled('q') ? '%'.addcslashes($r->q, '%_\\').'%' : null;
        $rows = Company::query()->whereNull('parent_id')->whereNull('branch_status')
            ->whereIn('verification_status', ['approved', 'pending'])
            ->when($r->filled('code'), fn ($q) => $q->where('institution_code', $r->code))
            ->when($like, fn ($q) => $q->where(fn ($w) => $w->where('company_name', 'like', $like)->orWhere('city', 'like', $like)))
            ->orderBy('company_name')->limit(100)->get(self::PUBLIC_COLS);
        return ApiResponse::success($rows->map(fn ($c) => $this->mainPayload($c)));
    }

    /** Sub-branch: its current connection (main institution details + both approval states). */
    public function mine(Request $r)
    {
        $me = $r->user()->company;
        abort_unless($me, 403);
        return ApiResponse::success([
            'connected' => (bool) $me->parent_id,
            'main' => $me->parent ? $this->mainPayload($me->parent) : null,
            'status' => $me->branch_status, 'admin_status' => $me->branch_admin_status,
            'note' => $me->branch_note, 'submitted_at' => $me->branch_submitted_at,
            'profile_complete' => $this->profileComplete($me),
            'approved' => (bool) $me->branchFullyApproved(),
        ]);
    }

    /** Step 1: sub-branch enters the code. Saves the link as a draft, nothing is sent yet. */
    public function connect(Request $r)
    {
        $d = $r->validate(['code' => ['required', 'digits:5']]);
        $me = $r->user()->company;
        abort_unless($me, 403, 'Only institutions can join.');
        abort_if($me->branches()->exists(), 422, 'A main institution with branches cannot become a sub-branch.');
        abort_if(in_array($me->branch_status, ['pending', 'approved'], true), 422, 'Your request is already with the main institution.');
        $main = Company::whereNull('parent_id')->whereNull('branch_status')->where('institution_code', $d['code'])->where('id', '!=', $me->id)->first();
        abort_unless($main, 404, 'No institution found for that code.');
        $me->update(['parent_id' => $main->id, 'branch_status' => 'draft', 'branch_admin_status' => null, 'branch_note' => null]);
        return ApiResponse::success($this->mainPayload($main), 'Connected to '.$main->company_name.'. Complete your profile, then send it for verification.');
    }

    /** Step 2: sub-branch sends the completed profile for verification (main institution + admin). */
    public function join(Request $r)
    {
        $r->validate(['code' => ['nullable', 'digits:5']]);
        $me = $r->user()->company;
        abort_unless($me, 403, 'Only institutions can join.');
        if ($r->filled('code') && (! $me->parent_id || $me->parent?->institution_code !== $r->code)) { $this->connect($r); $me->refresh(); }
        abort_unless($me->parent_id, 422, 'Enter your main institution code first.');
        abort_if(in_array($me->branch_status, ['pending', 'approved'], true), 422, 'Your request is already submitted.');
        abort_unless($this->profileComplete($me), 422, 'Complete your institution profile before sending it for verification.');

        $main = $me->parent;
        $me->update(['branch_status' => 'pending', 'branch_admin_status' => 'pending', 'branch_submitted_at' => now(), 'branch_note' => null]);
        $this->notifications->notify($main->user_id, 'branch_request', 'New sub-branch request', $me->company_name.' sent its profile for verification.', ['company_id' => $me->id]);
        User::where('role', User::ROLE_ADMIN)->pluck('id')->each(fn ($id) => $this->notifications->notify($id, 'branch_request', 'Sub-branch request', $me->company_name.' asked to join '.$main->company_name.'.', ['company_id' => $me->id]));
        return ApiResponse::success($me->load('parent:id,company_name,city,state,institution_code'), 'Sent to '.$main->company_name.' and the admin for verification.');
    }

    /** Sub-branch: withdraw the request / disconnect. */
    public function withdraw(Request $r)
    {
        $me = $r->user()->company;
        abort_unless($me, 403);
        $me->update(['parent_id' => null, 'branch_status' => null, 'branch_admin_status' => null, 'branch_submitted_at' => null, 'branch_note' => null]);
        return ApiResponse::success(null, 'Request withdrawn');
    }

    /** Main institution: its code, plus every sub-branch that sent a request (drafts are not shown). */
    public function index(Request $r)
    {
        $me = $r->user()->company;
        abort_unless($me, 403);
        $rows = $me->branches()->whereIn('branch_status', ['pending', 'approved', 'rejected'])
            ->with('user:id,name,email,phone')->withCount('jobs')->latest('branch_submitted_at')->get()
            ->map(fn ($b) => $this->branchRow($b));
        return ApiResponse::success([
            'institution_code' => $me->institution_code,
            'share_url' => rtrim(config('app.frontend_url', env('FRONTEND_URL', 'https://upaadhyay.com')), '/').'/pages/register.html?branch_code='.$me->institution_code,
            'branches' => $rows,
        ]);
    }

    private function branchRow(Company $b): array
    {
        return [
            'id' => $b->id, 'name' => $b->company_name, 'logo' => $b->logo, 'city' => $b->city, 'state' => $b->state,
            'type' => ($b->profile_data['type'] ?? $b->industry), 'jobs_count' => $b->jobs_count ?? null,
            'status' => $b->branch_status, 'admin_status' => $b->branch_admin_status, 'note' => $b->branch_note,
            'approved' => (bool) $b->branchFullyApproved(), 'submitted_at' => $b->branch_submitted_at,
            'email' => $b->email ?: $b->user?->email, 'phone' => $b->phone ?: $b->user?->phone,
        ];
    }

    /** Main institution: everything the sub-branch submitted, to verify before approving. */
    public function show(Request $r, int $id)
    {
        $me = $r->user()->company;
        abort_unless($me, 403);
        $b = $me->branches()->whereIn('branch_status', ['pending', 'approved', 'rejected'])->with('user:id,name,email,phone')->findOrFail($id);
        return ApiResponse::success($this->branchRow($b) + [
            'description' => $b->description, 'website' => $b->website, 'address' => $b->location,
            'company_size' => $b->company_size, 'profile' => $b->profile_data ?? [],
            'representative' => ['name' => $b->user?->name, 'email' => $b->user?->email, 'phone' => $b->user?->phone],
        ]);
    }

    public function decide(Request $r, int $id, string $action)
    {
        abort_unless(in_array($action, ['approve', 'reject'], true), 404);
        $me = $r->user()->company;
        abort_unless($me, 403);
        $b = $me->branches()->where('branch_status', 'pending')->findOrFail($id);
        $note = $action === 'reject' ? ($r->validate(['reason' => ['nullable', 'string', 'max:500']])['reason'] ?? null) : null;
        $b->update(['branch_status' => $action === 'approve' ? 'approved' : 'rejected', 'branch_note' => $note]);
        $adminPending = $action === 'approve' && $b->branch_admin_status !== 'approved';
        $this->notifications->notify($b->user_id, 'branch_'.$action, 'Sub-branch request '.($action === 'approve' ? 'approved by main institution' : 'rejected'),
            $action === 'approve'
                ? $me->company_name.' approved your request.'.($adminPending ? ' Waiting for the admin to approve too.' : ' You can now post jobs.')
                : $me->company_name.' did not approve your request.'.($note ? ' Reason: '.$note : ''), ['company_id' => $me->id]);
        return ApiResponse::success($this->branchRow($b->fresh()), 'Branch '.$action.'d');
    }

    /** Main institution: change the code (own 5 digits, or leave empty for a random one). Existing sub-branches stay linked. */
    public function updateCode(Request $r)
    {
        $d = $r->validate(['code' => ['nullable', 'digits:5']]);
        $me = $r->user()->company;
        abort_unless($me && ! $me->parent_id, 403, 'Only a main institution has a code.');
        if (! empty($d['code'])) {
            abort_if(Company::where('institution_code', $d['code'])->where('id', '!=', $me->id)->exists(), 422, 'That code is already used by another institution.');
            $code = $d['code'];
        } else {
            do { $code = (string) random_int(10000, 99999); } while (Company::where('institution_code', $code)->exists());
        }
        $me->update(['institution_code' => $code]);
        return ApiResponse::success(['institution_code' => $code], 'Code updated');
    }
}
