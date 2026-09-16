<x-filament-panels::page>
    @php
        $paymentPlan = $matter->paymentPlan;
        $messages = $this->getVisibleMessages();
    @endphp

    <div style="background: #ffffff; border: 1px solid #D8DBE0; border-radius: 8px; padding: 24px; max-width: 480px;">
        <div style="font-size: 0.75rem; color: #4A5568; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Matter reference</div>
        <div style="font-size: 1.5rem; font-weight: 600; color: #1C2430; margin-bottom: 16px;">{{ $matter->reference }}</div>

        <div style="font-size: 0.75rem; color: #4A5568; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Status</div>
        <div style="font-size: 1rem; font-weight: 600; color: #0B4F9E; margin-bottom: 16px;">{{ ucfirst($matter->status->value) }}</div>

        @if ($matter->court_date)
            <div style="font-size: 0.75rem; color: #4A5568; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Court date</div>
            <div style="font-size: 1rem; color: #1C2430;">{{ $matter->court_date->format('d M Y') }}</div>
        @endif
    </div>

    @if ($paymentPlan)
        @php
            $outstanding = (float) $paymentPlan->amount_outstanding;
            $isOverdueBalance = $outstanding > 0 && $paymentPlan->hasOverdueInstalments();
        @endphp

        <div style="background: #ffffff; border: 1px solid #D8DBE0; border-radius: 8px; padding: 24px; margin-top: 24px; max-width: 720px;">
            <div style="font-size: 1.125rem; font-weight: 600; color: #1C2430; margin-bottom: 16px;">Payment plan</div>

            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; margin-bottom: 20px;">
                <div style="background: #F4F5F7; border-radius: 8px; padding: 12px;">
                    <div style="font-size: 0.75rem; color: #4A5568; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Total amount</div>
                    <div style="font-size: 1.125rem; font-weight: 600; color: #1C2430;">£{{ number_format((float) $paymentPlan->total_amount, 2) }}</div>
                </div>

                <div style="background: #F4F5F7; border-radius: 8px; padding: 12px;">
                    <div style="font-size: 0.75rem; color: #4A5568; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Deposit</div>
                    <div style="font-size: 1.125rem; font-weight: 600; color: #1C2430;">£{{ number_format((float) $paymentPlan->deposit_amount, 2) }}</div>
                    <div style="font-size: 0.75rem; color: #4A5568; margin-top: 4px;">
                        {{ $paymentPlan->deposit_paid_at ? 'Paid ' . $paymentPlan->deposit_paid_at->format('d M Y') : 'Not paid' }}
                    </div>
                </div>

                <div style="background: #F4F5F7; border-radius: 8px; padding: 12px;">
                    <div style="font-size: 0.75rem; color: #4A5568; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 4px;">Outstanding balance</div>
                    <div style="font-size: 1.125rem; font-weight: 600; color: {{ $isOverdueBalance ? '#B91C1C' : '#1C2430' }};">
                        £{{ number_format($outstanding, 2) }}
                    </div>
                    @if ($isOverdueBalance)
                        <div style="font-size: 0.75rem; color: #B91C1C; margin-top: 4px;">Overdue — please contact us</div>
                    @endif
                </div>
            </div>

            <table style="width: 100%; border-collapse: collapse; font-size: 0.875rem;">
                <thead>
                    <tr style="text-align: left; border-bottom: 1px solid #D8DBE0;">
                        <th style="padding: 8px 4px; color: #4A5568;">Amount</th>
                        <th style="padding: 8px 4px; color: #4A5568;">Due date</th>
                        <th style="padding: 8px 4px; color: #4A5568;">Status</th>
                        <th style="padding: 8px 4px; color: #4A5568;">Paid</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($paymentPlan->instalments as $instalment)
                        <tr style="border-bottom: 1px solid #F4F5F7;">
                            <td style="padding: 8px 4px; color: #1C2430;">£{{ number_format((float) $instalment->amount, 2) }}</td>
                            <td style="padding: 8px 4px; color: #1C2430;">{{ $instalment->due_date?->format('d M Y') ?? '—' }}</td>
                            <td style="padding: 8px 4px; color: #1C2430;">{{ ucfirst($instalment->display_status->value) }}</td>
                            <td style="padding: 8px 4px; color: #1C2430;">{{ $instalment->paid_at?->format('d M Y') ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div style="margin-top: 24px; max-width: 720px;">
        {{ $this->table }}
    </div>

    <div style="background: #ffffff; border: 1px solid #D8DBE0; border-radius: 8px; padding: 24px; margin-top: 24px; max-width: 720px;">
        <div style="font-size: 1.125rem; font-weight: 600; color: #1C2430; margin-bottom: 16px;">Messages</div>

        @forelse ($messages as $message)
            <div style="border-bottom: 1px solid #F4F5F7; padding: 12px 0;">
                <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                    <span style="font-weight: 600; color: #1C2430; font-size: 0.875rem;">{{ $message->from_label }}</span>
                    <span style="font-size: 0.75rem; color: #4A5568;">{{ $message->created_at->format('d M Y, H:i') }}</span>
                </div>
                <div style="font-size: 0.875rem; color: #1C2430; white-space: pre-wrap;">{{ $message->body }}</div>
            </div>
        @empty
            <div style="font-size: 0.875rem; color: #4A5568;">No messages yet.</div>
        @endforelse
    </div>

    <x-portal.legal-footer :tenant="$matter->tenant" style="max-width: 720px; margin-top: 24px;" />
</x-filament-panels::page>
