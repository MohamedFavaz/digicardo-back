<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\TemplateService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class TemplateController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly TemplateService $templateService
    ) {}

    /**
     * List all approved templates with metadata and default theme tokens.
     */
    public function index(): JsonResponse
    {
        $templates = $this->templateService->getAllTemplates();

        return $this->successResponse($templates);
    }
}
