<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\NotificationService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

/** Admin review of sub-branch requests (the "Sub-branch requests" tab). Routes: /api/admin/branch-requests */
class AdminBranchController extends Controller
{
    public function __construct(private NotificationService $notifications) {}

    private function row(Company $b): array
    {
        $pd = $b->profile_data ?? [];
        $m = $b->parent;
        return [
            'id' => $b->id,
            'name' => $b->company_name,
            'logo' => $b->logo,
            'city' => $b->city,
            'state' => $b->state,
            'email' => $b->email ?: $b->user?->email,
            'phone' => $b->phone ?: $b->user?->phone,
            'website' => $b->website,
            'address' => $b->location,
            'description' => $b->description,
            'type' => $pd['type'] ?? $b->industry,
            'profile' => $pd,
            'main' => $m ? ['id' => $m->id, 'name' => $m->company_name, 'code' => $m->institution_code] : null,
            'main_status' => $b->branch_status,        // decision of the main institution
            'admin_status' => $b->branch_admin_status, // decision of the admin
            'approved' => (bool) $b->branchFullyApproved(),
            'note' => $b->branch_note,
            'submitted_at' => $b->branch_submitted_at,
        ];
    }

    private function like(string $v): string
    {
        return '%'.addcslashes($v, '%_\\').'%';
    }

    public function index(Request $r)
    {
        $q = Company::query()->whereNotNull('parent_id')
            ->whereIn('branch_status', ['pending', 'approved', 'rejected'])
            ->with(['parent:id,company_name,city,institution_code', 'user:id,name,email,phone'])
           ->when($r->filled('status'), fn ($w) => $r->status === 'pending'
    ? $w->where(fn ($x) => $x->where('branch_admin_status', 'pending')->orWhereNull('branch_admin_status'))
    : $w->where('branch_admin_status', $r->status))
            ->when($r->filled('state'), fn ($w) => $w->where('state', 'like', $this->like($r->state)))
            ->when($r->filled('city'), fn ($w) => $w->where('city', 'like', $this->like($r->city)))
            ->when($r->filled('search'), function ($w) use ($r) {
                $like = $this->like($r->search);
                $w->where(fn ($x) => $x->where('company_name', 'like', $like)
                    ->orWhere('institution_code', 'like', $like)
                    ->orWhereHas('parent', fn ($p) => $p->where('company_name', 'like', $like)->orWhere('institution_code', 'like', $like)));
            });

        $q->orderBy('branch_submitted_at', $r->query('sort') === 'oldest' ? 'asc' : 'desc')->orderBy('id', 'desc');

        $page = $q->paginate(min((int) $r->query('per_page', 20), 100));
        $page->getCollection()->transform(fn ($b) => $this->row($b));
        return ApiResponse::success($page);
    }

    public function show(int $id)
    {
        $b = Company::whereNotNull('parent_id')->with(['parent:id,company_name,city,institution_code', 'user:id,name,email,phone'])->findOrFail($id);
        return ApiResponse::success($this->row($b));
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
