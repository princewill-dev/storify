@extends('admin.layout')
@section('subtitle', 'Support Messages')

@section('content')
<div class="bg-white rounded-xl shadow-sm border border-slate-200">
    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
        <h2 class="text-lg font-bold text-slate-900">Support Messages</h2>
        <div class="flex gap-2">
            <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700">{{ $messages->where('status', 'pending')->count() }} Pending</span>
            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">{{ $messages->where('status', 'replied')->count() }} Replied</span>
        </div>
    </div>
    
        <table class="w-full text-sm">
            <thead class="border-b border-slate-100">
                <tr>
                    <th class="text-left py-3 px-4 font-medium text-slate-600 w-16">ID</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Customer</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Store</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600">Message</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600 w-24">Status</th>
                    <th class="text-left py-3 px-4 font-medium text-slate-600 w-28">Date</th>
                    <th class="text-right py-3 px-4 font-medium text-slate-600 w-36">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                @forelse($messages as $message)
                <tr class="{{ $message->status === 'pending' ? 'bg-amber-50/50' : '' }}">
                    <td class="py-3 px-4 text-slate-500">#{{ $message->id }}</td>
                    <td class="py-3 px-4">
                        <strong class="text-slate-700">{{ $message->name }}</strong>
                        <div class="text-xs text-slate-400">{{ $message->email }}</div>
                        @if($message->phone)
                        <div class="text-xs text-slate-400">{{ $message->phone }}</div>
                        @endif
                    </td>
                    <td class="py-3 px-4 text-slate-700">{{ $message->store?->name ?? '—' }}</td>
                    <td class="py-3 px-4 text-slate-600">{{ Str::limit($message->message, 80) }}</td>
                    <td class="py-3 px-4">
                        @if($message->status === 'pending')
                            <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700">Pending</span>
                        @elseif($message->status === 'replied')
                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">Replied</span>
                        @else
                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">Closed</span>
                        @endif
                    </td>
                    <td class="py-3 px-4 text-slate-600">{{ $message->created_at->format('M d, Y') }}</td>
                    <td class="py-3 px-4 text-right">
                        <div class="flex items-center justify-end gap-1">
                            <button type="button" onclick="openModal('viewModal{{ $message->id }}')" class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50">
                                <i class="fi fi-rr-eye text-sm"></i>
                            </button>
                            @if($message->status !== 'replied')
                            <button type="button" onclick="openModal('replyModal{{ $message->id }}')" class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-emerald-200 text-emerald-500 hover:bg-emerald-50">
                                <i class="fi fi-rr-undo text-sm"></i>
                            </button>
                            @endif
                            <button type="button" onclick="openModal('deleteModal{{ $message->id }}')" class="inline-flex items-center justify-center w-8 h-8 rounded-lg border border-red-200 text-red-500 hover:bg-red-50">
                                <i class="fi fi-rr-trash text-sm"></i>
                            </button>
                        </div>
                    </td>
                </tr>

                <!-- View Modal -->
                <div id="viewModal{{ $message->id }}" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="viewModalLabel{{ $message->id }}" role="dialog" aria-modal="true">
                    <div class="fixed inset-0 bg-slate-900/50 transition-opacity" onclick="closeModal('viewModal{{ $message->id }}')"></div>
                    <div class="flex min-h-full items-center justify-center p-4">
                        <div class="relative w-full max-w-2xl bg-white rounded-xl shadow-xl">
                            <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                                <h3 class="text-base font-semibold text-slate-900" id="viewModalLabel{{ $message->id }}">Support Message Details</h3>
                                <button type="button" onclick="closeModal('viewModal{{ $message->id }}')" class="text-slate-400 hover:text-slate-600">&times;</button>
                            </div>
                            <div class="p-6">
                                <div class="grid grid-cols-2 gap-4 mb-4">
                                    <div>
                                        <p class="text-sm font-medium text-slate-700 mb-0.5">Customer Name</p>
                                        <p class="text-slate-600">{{ $message->name }}</p>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-slate-700 mb-0.5">Email</p>
                                        <p class="text-slate-600">{{ $message->email }}</p>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-slate-700 mb-0.5">Phone</p>
                                        <p class="text-slate-600">{{ $message->phone ?? 'N/A' }}</p>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-slate-700 mb-0.5">Store</p>
                                        <p class="text-slate-600">{{ $message->store?->name ?? '—' }}</p>
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-slate-700 mb-0.5">Status</p>
                                        @if($message->status === 'pending')
                                            <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-medium text-amber-700">Pending</span>
                                        @elseif($message->status === 'replied')
                                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-medium text-emerald-700">Replied</span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">Closed</span>
                                        @endif
                                    </div>
                                    <div>
                                        <p class="text-sm font-medium text-slate-700 mb-0.5">Date Submitted</p>
                                        <p class="text-slate-600">{{ $message->created_at->format('M d, Y \a\t h:i A') }}</p>
                                    </div>
                                </div>
                                <div class="mb-4">
                                    <p class="text-sm font-medium text-slate-700 mb-1.5">Message</p>
                                    <div class="bg-slate-50 rounded-lg p-4 text-sm text-slate-600">{{ $message->message }}</div>
                                </div>
                                @if($message->reply)
                                <div class="mb-4">
                                    <p class="text-sm font-medium text-slate-700 mb-1.5">Reply ({{ ucfirst($message->replied_by_type) }})</p>
                                    <div class="bg-emerald-50 rounded-lg p-4 text-sm text-emerald-800">{{ $message->reply }}</div>
                                    <p class="text-xs text-slate-400 mt-1">Replied on: {{ $message->replied_at->format('M d, Y \a\t h:i A') }}</p>
                                </div>
                                @endif
                            </div>
                            <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100 bg-slate-50/50 rounded-b-xl">
                                <button type="button" onclick="closeModal('viewModal{{ $message->id }}')" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">Close</button>
                                @if($message->status !== 'replied')
                                <button type="button" onclick="closeModal('viewModal{{ $message->id }}'); openModal('replyModal{{ $message->id }}')" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg bg-slate-900 text-white hover:bg-slate-800">Reply to Customer</button>
                                @endif
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Reply Modal -->
                <div id="replyModal{{ $message->id }}" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="replyModalLabel{{ $message->id }}" role="dialog" aria-modal="true">
                    <div class="fixed inset-0 bg-slate-900/50 transition-opacity" onclick="closeModal('replyModal{{ $message->id }}')"></div>
                    <div class="flex min-h-full items-center justify-center p-4">
                        <div class="relative w-full max-w-lg bg-white rounded-xl shadow-xl">
                            <form action="{{ route('admin.support-messages.reply', $message) }}" method="POST">
                                @csrf
                                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-emerald-50 rounded-t-xl">
                                    <h3 class="text-base font-semibold text-emerald-900" id="replyModalLabel{{ $message->id }}">Reply to {{ $message->name }}</h3>
                                    <button type="button" onclick="closeModal('replyModal{{ $message->id }}')" class="text-emerald-400 hover:text-emerald-600">&times;</button>
                                </div>
                                <div class="p-6">
                                    <div class="mb-4">
                                        <p class="text-sm font-medium text-slate-700 mb-1.5">Customer Message</p>
                                        <div class="bg-slate-50 rounded-lg p-4 text-sm text-slate-600">{{ $message->message }}</div>
                                    </div>
                                    <div class="mb-4">
                                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Your Reply <span class="text-red-500">*</span></label>
                                        <textarea name="reply" rows="6" class="w-full rounded-lg border-slate-300 px-3.5 py-2.5 text-sm shadow-sm focus:border-slate-500 focus:ring-1 focus:ring-slate-500 @error('reply') border-red-300 focus:border-red-500 focus:ring-red-500 @enderror" required></textarea>
                                        @error('reply')<p class="text-sm text-red-500 mt-1">{{ $message }}</p>@enderror
                                    </div>
                                    <p class="text-xs text-slate-400">
                                        <i class="fi fi-rr-info text-xs mr-1"></i> An email notification will be sent to {{ $message->email }} with your reply.
                                    </p>
                                </div>
                                <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100 bg-slate-50/50 rounded-b-xl">
                                    <button type="button" onclick="closeModal('replyModal{{ $message->id }}')" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">Cancel</button>
                                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg bg-emerald-600 text-white hover:bg-emerald-700">Send Reply</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Delete Modal -->
                <div id="deleteModal{{ $message->id }}" class="hidden fixed inset-0 z-50 overflow-y-auto" aria-labelledby="deleteModalLabel{{ $message->id }}" role="dialog" aria-modal="true">
                    <div class="fixed inset-0 bg-slate-900/50 transition-opacity" onclick="closeModal('deleteModal{{ $message->id }}')"></div>
                    <div class="flex min-h-full items-center justify-center p-4">
                        <div class="relative w-full max-w-md bg-white rounded-xl shadow-xl">
                            <form action="{{ route('admin.support-messages.destroy', $message) }}" method="POST">
                                @csrf
                                @method('DELETE')
                                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100 bg-red-50 rounded-t-xl">
                                    <h3 class="text-base font-semibold text-red-900" id="deleteModalLabel{{ $message->id }}">Delete Support Message</h3>
                                    <button type="button" onclick="closeModal('deleteModal{{ $message->id }}')" class="text-red-400 hover:text-red-600">&times;</button>
                                </div>
                                <div class="p-6">
                                    <p class="text-sm text-slate-600 mb-4">Are you sure you want to delete this support message?</p>
                                    <div class="bg-amber-50 rounded-lg p-4 text-sm">
                                        <p class="font-medium text-slate-700">From:</p>
                                        <p class="text-slate-600 mb-2">{{ $message->name }}</p>
                                        <p class="font-medium text-slate-700">Store:</p>
                                        <p class="text-slate-600 mb-2">{{ $message->store?->name }}</p>
                                        <p class="font-medium text-slate-700">Message:</p>
                                        <p class="text-slate-600">{{ Str::limit($message->message, 100) }}</p>
                                    </div>
                                    <p class="text-sm text-red-600 mt-4"><i class="fi fi-rr-triangle-warning mr-1"></i>This action cannot be undone.</p>
                                </div>
                                <div class="flex justify-end gap-2 px-6 py-4 border-t border-slate-100 bg-slate-50/50 rounded-b-xl">
                                    <button type="button" onclick="closeModal('deleteModal{{ $message->id }}')" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50">Cancel</button>
                                    <button type="submit" class="inline-flex items-center gap-1.5 px-4 py-2.5 text-sm font-semibold rounded-lg bg-red-600 text-white hover:bg-red-700">Delete</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                @empty
                <tr>
                    <td colspan="7" class="py-12 text-center text-slate-400">
                        <i class="fi fi-rr-inbox text-3xl text-slate-300 block mb-2"></i>
                        No support messages found.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>

</div>
@endsection
