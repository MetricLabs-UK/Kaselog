@php
    $record = $getRecord();
    $paymentPlan = $record->paymentPlan;
    $outstanding = (float) $paymentPlan->amount_outstanding;
    $isOverdueBalance = $outstanding > 0 && $paymentPlan->hasOverdueInstalments();
@endphp

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px;">
    <div style="background: rgba(255,255,255,0.05); border: 1px solid #D8DBE0; border-radius: 8px; padding: 12px;">
        <div style="font-size: 0.75rem; color: #F4F5F7; opacity: 0.7; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Total amount</div>
        <div style="font-size: 1.125rem; font-weight: 600; color: #F4F5F7;">£{{ number_format((float) $paymentPlan->total_amount, 2) }}</div>
    </div>

    <div style="background: rgba(255,255,255,0.05); border: 1px solid #D8DBE0; border-radius: 8px; padding: 12px;">
        <div style="font-size: 0.75rem; color: #F4F5F7; opacity: 0.7; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Deposit</div>
        <div style="font-size: 1.125rem; font-weight: 600; color: #F4F5F7;">£{{ number_format((float) $paymentPlan->deposit_amount, 2) }}</div>
        <div style="font-size: 0.75rem; color: #F4F5F7; opacity: 0.7; margin-top: 4px;">
            {{ $paymentPlan->deposit_paid_at ? 'Paid ' . $paymentPlan->deposit_paid_at->format('d M Y') : 'Not paid' }}
        </div>
    </div>

    <div style="background: rgba(255,255,255,0.05); border: 1px solid #D8DBE0; border-radius: 8px; padding: 12px;">
        <div style="font-size: 0.75rem; color: #F4F5F7; opacity: 0.7; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Outstanding balance</div>
        <div style="font-size: 1.125rem; font-weight: 600; color: {{ $isOverdueBalance ? '#ef4444' : '#F4F5F7' }};">
            £{{ number_format($outstanding, 2) }}
        </div>
        @if ($isOverdueBalance)
            <div style="font-size: 0.75rem; color: #ef4444; margin-top: 4px;">Overdue instalments</div>
        @endif
    </div>
</div>
