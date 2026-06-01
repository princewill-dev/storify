@props(['status'])

@php
$map = [
    'active'     => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'inactive'   => 'bg-slate-100 text-slate-600 ring-slate-500/20',
    'pending'    => 'bg-amber-50 text-amber-700 ring-amber-600/20',
    'draft'      => 'bg-slate-100 text-slate-500 ring-slate-400/20',
    'approved'   => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20',
    'suspended'  => 'bg-red-50 text-red-700 ring-red-600/20',
    'completed'  => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'received'   => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'cancelled'  => 'bg-red-50 text-red-700 ring-red-600/20',
    'rejected'   => 'bg-red-50 text-red-700 ring-red-600/20',
    'processing' => 'bg-blue-50 text-blue-700 ring-blue-600/20',
    'accepted'   => 'bg-cyan-50 text-cyan-700 ring-cyan-600/20',
    'dispatched' => 'bg-purple-50 text-purple-700 ring-purple-600/20',
    'delivered'  => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'returned'   => 'bg-orange-50 text-orange-700 ring-orange-600/20',
    'invited'    => 'bg-amber-50 text-amber-700 ring-amber-600/20',
    'open'       => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'closed'     => 'bg-slate-100 text-slate-600 ring-slate-500/20',
    'confirmed'  => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'refunded'   => 'bg-purple-50 text-purple-700 ring-purple-600/20',
    'failed'     => 'bg-red-50 text-red-700 ring-red-600/20',
    'unpaid'     => 'bg-amber-50 text-amber-700 ring-amber-600/20',
    'paid'       => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    'deleted'    => 'bg-red-50 text-red-700 ring-red-600/20',
];
$class = $map[$status] ?? 'bg-slate-100 text-slate-600 ring-slate-500/20';
$label = ucfirst($status);
@endphp

<span {{ $attributes->merge(['class' => "inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {$class}"]) }}>
    {{ $label }}
</span>
