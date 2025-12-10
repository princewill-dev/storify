<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CompanyServiceController extends Controller
{
    public function index()
    {
        Log::info('company_services_viewed', ['user_id' => auth()->id()]);
        $services = CompanyService::ordered()->paginate(20)->withQueryString();
        return view('admin.company_services.index', compact('services'));
    }

    public function store(Request $request)
    {
        Log::info('company_service_store_attempt', [
            'user_id' => auth()->id(),
            'request_data' => $request->except(['background_image']),
            'has_file' => $request->hasFile('background_image')
        ]);

        try {
            // Remove background_image from request if no file was uploaded
            if (!$request->hasFile('background_image')) {
                $request->request->remove('background_image');
            }

            $data = $request->validate([
                'order' => 'nullable|integer|min:0',
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'page_link' => 'nullable|string|max:255|unique:company_services,page_link',
                'background_image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:10240',
                'status' => 'required|in:active,inactive',
            ]);

            Log::info('company_service_validation_passed', ['validated_data' => $data]);

            // Normalize page_link to be path without leading slash
            if (array_key_exists('page_link', $data)) {
                $pl = trim((string)$data['page_link']);
                $pl = ltrim($pl, '/');
                $data['page_link'] = $pl !== '' ? $pl : null;
            }

            if ($request->hasFile('background_image')) {
                $path = $request->file('background_image')->store('company_services', 'public');
                $data['background_image_path'] = $path;
                Log::info('company_service_image_uploaded', ['path' => $path]);
            }

            $service = CompanyService::create($data);
            Log::info('company_service_created', [
                'user_id' => auth()->id(),
                'service_id' => $service->id,
                'service_data' => $service->toArray()
            ]);

            Cache::forget('nav_company_services');
            return redirect()->route('admin.company-services.index')->with('success', 'Service created successfully');

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('company_service_validation_failed', [
                'user_id' => auth()->id(),
                'errors' => $e->errors(),
                'input' => $request->except(['background_image'])
            ]);
            return back()->withErrors($e->errors())->withInput();

        } catch (\Exception $e) {
            Log::error('company_service_store_failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return back()->with('error', 'Failed to create service: ' . $e->getMessage())->withInput();
        }
    }

    public function update(Request $request, CompanyService $companyService)
    {
        Log::info('company_service_update_attempt', [
            'user_id' => auth()->id(),
            'service_id' => $companyService->id,
            'request_data' => $request->except(['background_image'])
        ]);

        try {
            // Remove background_image from request if no file was uploaded
            if (!$request->hasFile('background_image')) {
                $request->request->remove('background_image');
            }

            $data = $request->validate([
                'order' => 'nullable|integer|min:0',
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'page_link' => 'nullable|string|max:255|unique:company_services,page_link,' . $companyService->id,
                'background_image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:10240',
                'status' => 'required|in:active,inactive',
            ]);

            if (array_key_exists('page_link', $data)) {
                $pl = trim((string)$data['page_link']);
                $pl = ltrim($pl, '/');
                $data['page_link'] = $pl !== '' ? $pl : null;
            }

            if ($request->hasFile('background_image')) {
                if (!empty($companyService->background_image_path)) {
                    Storage::disk('public')->delete($companyService->background_image_path);
                }
                $path = $request->file('background_image')->store('company_services', 'public');
                $data['background_image_path'] = $path;
                Log::info('company_service_image_updated', ['path' => $path]);
            }

            $companyService->update($data);
            Log::info('company_service_updated', [
                'user_id' => auth()->id(),
                'service_id' => $companyService->id,
                'updated_data' => $data
            ]);

            Cache::forget('nav_company_services');
            return redirect()->route('admin.company-services.index')->with('success', 'Service updated successfully');

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::warning('company_service_update_validation_failed', [
                'user_id' => auth()->id(),
                'service_id' => $companyService->id,
                'errors' => $e->errors()
            ]);
            return back()->withErrors($e->errors())->withInput();

        } catch (\Exception $e) {
            Log::error('company_service_update_failed', [
                'user_id' => auth()->id(),
                'service_id' => $companyService->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return back()->with('error', 'Failed to update service: ' . $e->getMessage())->withInput();
        }
    }

    public function destroy(CompanyService $companyService)
    {
        if (!empty($companyService->background_image_path)) {
            Storage::disk('public')->delete($companyService->background_image_path);
        }
        $companyService->delete();
        Log::info('company_service_deleted', ['user_id' => auth()->id(), 'service_id' => $companyService->id]);
        Cache::forget('nav_company_services');
        return redirect()->route('admin.company-services.index')->with('success', 'Service deleted');
    }

    public function toggle(CompanyService $companyService)
    {
        $companyService->status = $companyService->status === 'active' ? 'inactive' : 'active';
        $companyService->save();
        Log::info('company_service_toggled', ['user_id' => auth()->id(), 'service_id' => $companyService->id, 'status' => $companyService->status]);
        Cache::forget('nav_company_services');
        return redirect()->route('admin.company-services.index')->with('success', 'Service status updated');
    }

    public function reorder(Request $request)
    {
        try {
            $items = $request->validate([
                'items' => 'required|array',
                'items.*.id' => 'required|exists:company_services,id',
                'items.*.order' => 'required|integer|min:0',
            ]);

            DB::beginTransaction();

            foreach ($items['items'] as $item) {
                CompanyService::where('id', $item['id'])->update(['order' => $item['order']]);
            }

            DB::commit();

            Log::info('company_services_reordered', [
                'user_id' => auth()->id(),
                'items' => $items['items']
            ]);

            Cache::forget('nav_company_services');

            return response()->json(['success' => true, 'message' => 'Order updated successfully']);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('company_services_reorder_failed', [
                'user_id' => auth()->id(),
                'error' => $e->getMessage()
            ]);

            return response()->json(['success' => false, 'message' => 'Failed to update order'], 500);
        }
    }
}
