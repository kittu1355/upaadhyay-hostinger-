<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** Admin review of sub-branch requests (the "Sub-branch requests" tab). */
class AdminBranchController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    private function row(Company $b): array
    {
        return [
            'id' => $b->id,
            'name' => $b->company_name,
            'city' => $b->city,
            'state' => $b->state,
            'logo' => $b->logo,
            'main_id' => $b->parent_id,
            'main_name' => $b->parent?->company_name,
            'main_city' => $b->parent?->city,
            'main_code' => $b->parent?->institution_code,
            'admin_name' => $b->user?->name,
            'admin_email' => $b->user?->email,
            'admin_phone' => $b->user?->phone,
            'status' => $b->branch_status,              // main institution decision
            'admin_status' => $b->branch_admin_status,  // admin decision
            'note' => $b->branch_note,
            'submitted_at' => $b->branch_submitted_at,
        ];
    }

    public function index(Request $r)
    {
        $like = $r->filled('q') ? '%'.addcslashes($r->q, '%_\\').'%' : null;
        $q = Company::query()->whereNotNull('parent_id')
            ->whereIn('branch_status', ['pending', 'approved', 'rejected'])
            ->with(['parent:id,company_name,city,institution_code', 'user:id,name,email,phone'])
            ->when($r->filled('status'), fn ($w) => $w->where('branch_admin_status', $r->status))
            ->when($r->filled('state'), fn ($w) => $w->where('state', 'like', '%'.addcslashes($r->state, '%_\\').'%'))
            ->when($r->filled('city'), fn ($w) => $w->where('city', 'like', '%'.addcslashes($r->city, '%_\\').'%'))
            ->when($like, fn ($w) => $w->where(fn ($x) => $x->where('company_name', 'like', $like)
                ->orWhereHas('parent', fn ($p) => $p->where('company_name', 'like', $like))));

        $q->orderBy('branch_submitted_at', $r->query('sort') === 'oldest' ? 'asc' : 'desc');

        $page = $q->paginate(min((int) $r->query('per_page', 20), 100));
        $page->getCollection()->transform(fn ($b) => $this->row($b));
        return ApiResponse::success($page);
    }

    public function show(int $id)
    {
        $b = Company::whereNotNull('parent_id')->with(['parent:id,company_name,city,institution_code', 'user:id,name,email,phone'])->findOrFail($id);
        return ApiResponse::success($this->row($b) + [
            'description' => $b->description, 'website' => $b->website, 'address' => $b->location,
            'email' => $b->email, 'phone' => $b->phone, 'profile' => $b->profile_data ?? [],
        ]);
    }

    public function approve(int $id)
    {
        $b = Company::whereNotNull('parent_id')->findOrFail($id);
        $b->update(['branch_admin_status' => 'approved']);
        $this->notifications->notify($b->user_id, 'branch_approve', 'Sub-branch approved by admin',
            'The admin approved your sub-branch request.', ['company_id' => $b->id]);
        return ApiResponse::success($this->row($b->fresh(['parent', 'user'])), 'Branch approved');
    }

    public function reject(Request $r, int $id)
    {
        $d = $r->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $b = Company::whereNotNull('parent_id')->findOrFail($id);
        $b->update(['branch_admin_status' => 'rejected', 'branch_note' => $d['reason'] ?? null]);
        $this->notifications->notify($b->user_id, 'branch_reject', 'Sub-branch request rejected by admin',
            'The admin did not approve your request.'.(! empty($d['reason']) ? ' Reason: '.$d['reason'] : ''), ['company_id' => $b->id]);
        return ApiResponse::success($this->row($b->fresh(['parent', 'user'])), 'Branch rejected');
    }
}
