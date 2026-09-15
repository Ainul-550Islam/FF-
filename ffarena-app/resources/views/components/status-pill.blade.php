@props(['status', 'label' => null])

@php
    $labels = [
        'draft' => 'Draft',
        'open' => 'Open',
        'closed' => 'Closed',
        'live' => 'Live',
        'finished' => 'Finished',
        'cancelled' => 'Cancelled',
        'pending' => 'Pending',
        'confirmed' => 'Confirmed',
        'verified' => 'Verified',
        'checked' => 'Checked In',
        'checked_in' => 'Checked In',
        'waitlisted' => 'Waitlisted',
        'withdrawn' => 'Withdrawn',
        'rejected' => 'Rejected',
        'no_show' => 'No Show',
        'disputed' => 'Disputed',
        'under_review' => 'Under Review',
        'resolved' => 'Resolved',
        'failed' => 'Failed',
        'refunded' => 'Refunded',
        'paid' => 'Paid',
        'processing' => 'Processing',
        'ready' => 'Ready',
        'bye' => 'Bye',
        'expired' => 'Expired',
        'suspended' => 'Suspended',
        'active' => 'Active',
        'completed' => 'Completed',
        'success' => 'Success',
        'warning' => 'Warning',
        'overdue' => 'Overdue',
        'in_progress' => 'In Progress',
    ];

    $text = $label ?? ($labels[$status] ?? strtoupper(str_replace('_', ' ', (string) $status)));
@endphp

<span {{ $attributes->merge(['class' => 'pill '.$status]) }}>{{ $text }}</span>
