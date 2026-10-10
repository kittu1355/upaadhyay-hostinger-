<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** Main institution <-> sub-branch connection (code based, approved by the main institution). */
class BranchController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    /** Public: main institutions a sub-branch can join (name/city/code only). */
    public function lookup(Request $r)
    {
        $like = $r->filled('q') ? '%'.addcslashes($r->q, '%_\\').'%' : null;
        $rows = Company::query()->whereNull('parent_id')->whereNull('branch_status')
            ->whereIn('verification_status', ['approved', 'pending'])
            ->when($r->filled('code'), fn ($q) => $q->where('institution_code', $r->code))
            ->when($like, fn ($q) => $q->where(fn ($w) => $w->where('company_name', 'like', $like)->orWhere('city', 'like', $like)))
            ->orderBy('company_name')->limit(100)
            ->get(['id', 'company_name', 'city', 'state', 'industry', 'logo', 'institution_code', 'profile_data']);
        return ApiResponse::success($rows->map(fn ($c) => [
            'id' => $c->id, 'name' => $c->company_name, 'city' => $c->city, 'state' => $c->state, 'logo' => $c->logo,
            'type' => ($c->profile_data['type'] ?? $c->industry), 'code' => $c->institution_code,
        ]));
    }

    /** Sub-branch asks to join a main institution. */
    public function join(Request $r)
    {
        $d = $r->validate(['code' => ['required', 'digits:5']]);
        $me = $r->user()->company;
        abort_unless($me, 403, 'Only institutions can join.');
        abort_if($me->branches()->exists(), 422, 'A main institution with branches cannot become a sub-branch.');
        $main = Company::whereNull('parent_id')->whereNull('branch_status')->where('institution_code', $d['code'])->where('id', '!=', $me->id)->first();
        abort_unless($main, 404, 'No institution found for that code.');
        $me->update(['parent_id' => $main->id, 'branch_status' => 'pending']);
        $this->notifications->notify($main->user_id, 'branch_request', 'New sub-branch request', $me->company_name.' wants to join as a sub-branch.', ['company_id' => $me->id]);
        return ApiResponse::success($me->load('parent:id,company_name,city,state,institution_code'), 'Request sent to '.$main->company_name);
    }

    /** Main institution: its code + every sub-branch with status. */
    public function index(Request $r)
    {
        $me = $r->user()->company;
        abort_unless($me, 403);
        $rows = $me->branches()->with('user:id,name,email,phone')->withCount('jobs')->latest('id')->get();
        return ApiResponse::success(['institution_code' => $me->institution_code, 'branches' => $rows]);
    }

    public function decide(Request $r, int $id, string $action)
    {
        abort_unless(in_array($action, ['approve', 'reject'], true), 404);
        $me = $r->user()->company;
        abort_unless($me, 403);
        $b = $me->branches()->findOrFail($id);
        $b->update(['branch_status' => $action === 'approve' ? 'approved' : 'rejected']);
        $this->notifications->notify($b->user_id, 'branch_'.$action, 'Sub-branch request '.($action === 'approve' ? 'approved' : 'rejected'),
            $me->company_name.($action === 'approve' ? ' approved your request. You can now post jobs.' : ' did not approve your request.'), ['company_id' => $me->id]);
        return ApiResponse::success($b, 'Branch '.$action.'d');
    }
}
