<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\FeatureRequest;
use App\Models\Feature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class FeatureController extends Controller
{
    public function index(): View
    {
        $features = Feature::ordered()->paginate(20);

        return view('admin.features.index', compact('features'));
    }

    public function store(FeatureRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['icon_path'] = $this->storeIcon($request->file('icon'));

        Feature::create($data);

        return back()->with('success', 'Feature created successfully.');
    }

    public function update(FeatureRequest $request, Feature $feature): RedirectResponse
    {
        $data = $request->validated();

        if ($request->hasFile('icon')) {
            $this->deleteIcon($feature);
            $data['icon_path'] = $this->storeIcon($request->file('icon'));
        }

        $feature->update($data);

        return back()->with('success', 'Feature updated successfully.');
    }

    public function destroy(Feature $feature): RedirectResponse
    {
        $this->deleteIcon($feature);
        $feature->delete();

        return back()->with('success', 'Feature removed.');
    }

    public function reorder(Request $request): JsonResponse
    {
        $items = $request->validate([
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'integer', 'exists:features,id'],
            'items.*.order' => ['required', 'integer'],
        ]);

        foreach ($items['items'] as $item) {
            Feature::where('id', $item['id'])->update(['order' => $item['order']]);
        }

        return response()->json(['success' => true]);
    }

    private function storeIcon($file): string
    {
        return $file->store('features/icons', 'public');
    }

    private function deleteIcon(Feature $feature): void
    {
        if ($feature->icon_path) {
            Storage::disk('public')->delete($feature->icon_path);
        }
    }
}
