<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeliveryRouteRequest;
use App\Models\DeliveryRoute;
use Illuminate\Support\Facades\Log;

class DeliveryRouteController extends Controller
{
    public function index()
    {
        $routes = DeliveryRoute::orderBy('country')->orderBy('state')->orderBy('area')->paginate(20);
        return view('admin.delivery_routes.index', compact('routes'));
    }

    public function store(DeliveryRouteRequest $request)
    {
        $data = $request->validated();
        $data['fee'] = (int)$data['fee'] * 100; // NGN -> kobo
        $route = DeliveryRoute::create($data);
        Log::info('delivery_route_created', ['user_id' => auth()->id(), 'route_id' => $route->id]);
        return redirect()->route('admin.delivery-routes.index')->with('success', 'Delivery route created');
    }

    public function edit(DeliveryRoute $deliveryRoute)
    {
        $routes = DeliveryRoute::orderBy('country')->orderBy('state')->orderBy('area')->paginate(20);
        return view('admin.delivery_routes.index', ['routes' => $routes, 'deliveryRoute' => $deliveryRoute]);
    }

    public function update(DeliveryRouteRequest $request, DeliveryRoute $deliveryRoute)
    {
        $data = $request->validated();
        $data['fee'] = (int)$data['fee'] * 100; // NGN -> kobo
        $deliveryRoute->update($data);
        Log::info('delivery_route_updated', ['user_id' => auth()->id(), 'route_id' => $deliveryRoute->id]);
        return redirect()->route('admin.delivery-routes.index')->with('success', 'Delivery route updated');
    }

    public function destroy(DeliveryRoute $deliveryRoute)
    {
        $deliveryRoute->delete();
        Log::info('delivery_route_deleted', ['user_id' => auth()->id(), 'route_id' => $deliveryRoute->id]);
        return redirect()->route('admin.delivery-routes.index')->with('success', 'Delivery route deleted');
    }

    public function toggle(DeliveryRoute $deliveryRoute)
    {
        $deliveryRoute->active = !$deliveryRoute->active;
        $deliveryRoute->save();
        Log::info('delivery_route_toggled', ['user_id' => auth()->id(), 'route_id' => $deliveryRoute->id, 'active' => $deliveryRoute->active]);
        return redirect()->route('admin.delivery-routes.index')->with('success', 'Delivery route status updated');
    }
}
