<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Services\AdminService;
use App\Services\ApplicationService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminApplicationController extends Controller
{
    public function index(Request $r, ApplicationService $apps)
    {
        return ApiResponse::success($apps->listForCompany($r->user(), $r->query()));
    }

    public function destroy(int $id, AdminService $admin)
    {
        $admin->deleteApplication(Application::findOrFail($id));
        return ApiResponse::success(null, 'Application deleted');
    }
}
