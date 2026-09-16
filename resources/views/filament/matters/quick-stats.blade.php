@php
    $record = $getRecord();

    $totalSeconds = (int) $record->timeEntries()->sum('duration_seconds');
    $totalBilled = (float) $record->timeEntries()->sum('billed_amount');

    $paymentPlan = $record->paymentPlan;
    $outstanding = $paymentPlan ? (float) $paymentPlan->amount_outstanding : null;
    $isOverdueBalance = $paymentPlan && $outstanding > 0 && $paymentPlan->hasOverdueInstalments();
@endphp

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
    <div style="background: rgba(255,255,255,0.05); border: 1px solid #D8DBE0; border-radius: 8px; padding: 12px;">
        <div style="font-size: 0.75rem; color: #F4F5F7; opacity: 0.7; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Time logged</div>
        <div style="font-size: 1.25rem; font-weight: 600; color: #F4F5F7;">{{ \App\Models\TimeEntry::formatDuration($totalSeconds) }}</div>
    </div>

    <div style="background: rgba(255,255,255,0.05); border: 1px solid #D8DBE0; border-radius: 8px; padding: 12px;">
        <div style="font-size: 0.75rem; color: #F4F5F7; opacity: 0.7; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Total billed</div>
        <div style="font-size: 1.25rem; font-weight: 600; color: #F4F5F7;">£{{ number_format($totalBilled, 2) }}</div>
    </div>

    <div style="background: rgba(255,255,255,0.05); border: 1px solid #D8DBE0; border-radius: 8px; padding: 12px;">
        <div style="font-size: 0.75rem; color: #F4F5F7; opacity: 0.7; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Outstanding balance</div>
        <div style="font-size: 1.25rem; font-weight: 600; color: {{ $isOverdueBalance ? '#ef4444' : '#F4F5F7' }};">
            {{ $paymentPlan ? ('£' . number_format($outstanding, 2)) : 'No payment plan' }}
        </div>
    </div>
</div>
