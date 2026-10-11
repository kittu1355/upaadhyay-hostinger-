<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompanyProfileRequest;
use App\Services\CompanyService;
use App\Services\FileUploadService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class CompanyProfileController extends Controller
{
    public function __construct(private CompanyService $svc) {}

    public function show(Request $r)
    {
        return ApiResponse::success($this->svc->getProfile($r->user()));
    }

    public function update(CompanyProfileRequest $r)
    {
        return ApiResponse::success($this->svc->updateProfile($r->user(), $r->validated()), 'Company profile updated');
    }

    /** Remove the logo: the stored object(s), the files row(s) and the company reference. */
    public function removeLogo(Request $r, FileUploadService $uploads)
    {
        $company = $this->svc->getProfile($r->user());
        foreach ($r->user()->files()->where('category', 'company_logo')->get() as $f) {
            if (! $uploads->delete($f)) return ApiResponse::error('Could not remove the logo from storage. Please try again.', 502);
        }
        $company->update(['logo' => null]);   // also clears a reference that had no file row
        return ApiResponse::success($company->fresh(), 'Logo removed');
    }
}
