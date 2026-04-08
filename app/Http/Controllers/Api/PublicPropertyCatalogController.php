<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PublicPropertyCatalogService;
use App\Traits\ResponseTrait;
use Exception;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class PublicPropertyCatalogController extends Controller
{
    use ResponseTrait;

    public function __construct(
        private readonly PublicPropertyCatalogService $catalogService
    ) {
    }

    public function home()
    {
        try {
            return $this->success(
                $this->catalogService->getHomeCatalog(),
                'Public home catalog fetched successfully'
            );
        } catch (Exception $e) {
            return $this->error([], $e->getMessage());
        }
    }

    public function index(Request $request)
    {
        try {
            return $this->success(
                $this->catalogService->searchProperties($request->all()),
                'Public properties fetched successfully'
            );
        } catch (Exception $e) {
            return $this->error([], $e->getMessage());
        }
    }

    public function show(int $propertyId)
    {
        try {
            return $this->success(
                $this->catalogService->getPropertyDetail($propertyId),
                'Public property fetched successfully'
            );
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'data' => null,
                'message' => 'Property not found',
            ], 404);
        } catch (Exception $e) {
            return $this->error([], $e->getMessage());
        }
    }

    public function showBySlug(string $slug)
    {
        try {
            return $this->success(
                $this->catalogService->getPropertyDetailBySlug($slug),
                'Public property fetched successfully'
            );
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'status' => false,
                'data' => null,
                'message' => 'Property not found',
            ], 404);
        } catch (Exception $e) {
            return $this->error([], $e->getMessage());
        }
    }

    public function countries(Request $request)
    {
        try {
            return $this->success(
                [
                    'countries' => $this->catalogService->getCountries((string) $request->get('q', '')),
                ],
                'Public countries fetched successfully'
            );
        } catch (Exception $e) {
            return $this->error([], $e->getMessage());
        }
    }
}
