<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PageStyling;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PageStylingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $stylings = PageStyling::orderBy('page_label')->get();
        return view('admin.styling.index', compact('stylings'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        return view('admin.styling.create');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'page_name' => 'required|string|unique:page_stylings,page_name|max:255',
            'page_label' => 'required|string|max:255',
            'background_color' => 'nullable|string|max:7',
            'custom_css' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        PageStyling::create([
            'page_name' => $request->page_name,
            'page_label' => $request->page_label,
            'background_color' => $request->background_color,
            'custom_css' => $request->custom_css,
            'is_active' => $request->has('is_active'),
        ]);

        return redirect()->route('admin.styling.index')
            ->with('success', 'Page styling created successfully!');
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(PageStyling $styling)
    {
        return view('admin.styling.edit', compact('styling'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, PageStyling $styling)
    {
        $validator = Validator::make($request->all(), [
            'page_name' => 'required|string|max:255|unique:page_stylings,page_name,' . $styling->id,
            'page_label' => 'required|string|max:255',
            'background_color' => 'nullable|string|max:7',
            'custom_css' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        $styling->update([
            'page_name' => $request->page_name,
            'page_label' => $request->page_label,
            'background_color' => $request->background_color,
            'custom_css' => $request->custom_css,
            'is_active' => $request->has('is_active'),
        ]);

        return redirect()->route('admin.styling.index')
            ->with('success', 'Page styling updated successfully!');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PageStyling $styling)
    {
        $styling->delete();

        return redirect()->route('admin.styling.index')
            ->with('success', 'Page styling deleted successfully!');
    }
}
