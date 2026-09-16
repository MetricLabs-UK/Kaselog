@php
    $matter = $instalment->paymentPlan->matter;
    $client = $matter->client;
    $paymentLink = url("/pay/instalments/{$instalment->id}");
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Overdue payment</title>
</head>
<body style="margin: 0; padding: 0; background-color: #F4F5F7; font-family: Arial, Helvetica, sans-serif; color: #1C2430;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #F4F5F7; padding: 24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden;">
                    <tr>
                        <td style="background-color: #1C2430; padding: 20px 24px;">
                            <span style="color: #F4F5F7; font-size: 18px; font-weight: bold;">Kaselog</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 24px;">
                            <p style="margin: 0 0 16px; font-size: 16px;">Dear {{ $client->first_name }},</p>

                            <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.6;">
                                We previously wrote to you about an overdue payment on matter
                                <strong>{{ $matter->reference }}</strong>. This remains unpaid and now requires
                                your urgent attention.
                            </p>

                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #F4F5F7; border-radius: 6px; margin: 0 0 16px;">
                                <tr>
                                    <td style="padding: 12px 16px; font-size: 14px;">
                                        <div><strong>Amount due:</strong> £{{ number_format((float) $instalment->amount, 2) }}</div>
                                        <div><strong>Due date:</strong> {{ $instalment->due_date->format('d/m/Y') }}</div>
                                        <div><strong>Days overdue:</strong> {{ $daysOverdue }}</div>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 0 0 24px; font-size: 14px; line-height: 1.6;">
                                Please settle this payment as soon as possible to avoid further action on your matter.
                            </p>

                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background-color: #0B4F9E; border-radius: 6px;">
                                        <a href="{{ $paymentLink }}" style="display: inline-block; padding: 12px 24px; color: #ffffff; font-size: 14px; font-weight: bold; text-decoration: none;">
                                            Make a payment
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 24px 0 0; font-size: 13px; color: #1C2430; opacity: 0.7;">
                                If you have already paid, please disregard this message.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
