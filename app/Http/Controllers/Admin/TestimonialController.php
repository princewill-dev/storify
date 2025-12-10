<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Testimonial;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TestimonialController extends Controller
{
    /**
     * Display a listing of testimonials
     */
    public function index()
    {
        $testimonials = Testimonial::orderBy('position')->orderBy('created_at', 'desc')->get();
        return view('admin.testimonials.index', compact('testimonials'));
    }

    /**
     * Store a newly created testimonial
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'occupation' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:1000'],
            'photo' => ['required', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            'status' => ['required', 'in:active,inactive'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            $data = $request->only(['name', 'occupation', 'message', 'status', 'position']);
            
            // Convert photo to base64
            if ($request->hasFile('photo')) {
                $photo = $request->file('photo');
                $photoData = base64_encode(file_get_contents($photo->getRealPath()));
                $mimeType = $photo->getMimeType();
                $data['photo'] = 'data:' . $mimeType . ';base64,' . $photoData;
            }

            Testimonial::create($data);

            Log::info('admin.testimonial.created', ['admin_id' => auth()->id()]);

            return redirect()->route('admin.testimonials.index')
                ->with('success', 'Testimonial created successfully.');
                
        } catch (\Exception $e) {
            Log::error('admin.testimonial.create_failed', [
                'error' => $e->getMessage(),
                'admin_id' => auth()->id(),
            ]);
            
            return redirect()->back()
                ->with('error', 'Failed to create testimonial. Please try again.')
                ->withInput();
        }
    }

    /**
     * Update the specified testimonial
     */
    public function update(Request $request, Testimonial $testimonial)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'occupation' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:1000'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif', 'max:2048'],
            'status' => ['required', 'in:active,inactive'],
            'position' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return redirect()->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            $data = $request->only(['name', 'occupation', 'message', 'status', 'position']);
            
            // Convert photo to base64 if new photo is uploaded
            if ($request->hasFile('photo')) {
                $photo = $request->file('photo');
                $photoData = base64_encode(file_get_contents($photo->getRealPath()));
                $mimeType = $photo->getMimeType();
                $data['photo'] = 'data:' . $mimeType . ';base64,' . $photoData;
            }

            $testimonial->update($data);

            Log::info('admin.testimonial.updated', [
                'testimonial_id' => $testimonial->id,
                'admin_id' => auth()->id(),
            ]);

            return redirect()->route('admin.testimonials.index')
                ->with('success', 'Testimonial updated successfully.');
                
        } catch (\Exception $e) {
            Log::error('admin.testimonial.update_failed', [
                'testimonial_id' => $testimonial->id,
                'error' => $e->getMessage(),
                'admin_id' => auth()->id(),
            ]);
            
            return redirect()->back()
                ->with('error', 'Failed to update testimonial. Please try again.')
                ->withInput();
        }
    }

    /**
     * Remove the specified testimonial
     */
    public function destroy(Testimonial $testimonial)
    {
        try {
            $testimonial->delete();

            Log::info('admin.testimonial.deleted', [
                'testimonial_id' => $testimonial->id,
                'admin_id' => auth()->id(),
            ]);

            return redirect()->route('admin.testimonials.index')
                ->with('success', 'Testimonial deleted successfully.');
                
        } catch (\Exception $e) {
            Log::error('admin.testimonial.delete_failed', [
                'testimonial_id' => $testimonial->id,
                'error' => $e->getMessage(),
                'admin_id' => auth()->id(),
            ]);
            
            return redirect()->back()
                ->with('error', 'Failed to delete testimonial. Please try again.');
        }
    }
}
