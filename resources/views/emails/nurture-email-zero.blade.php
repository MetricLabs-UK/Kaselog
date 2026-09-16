@php
    $bookingLink = url('/book-a-consultation');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Thanks for getting in touch</title>
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
                            <p style="margin: 0 0 16px; font-size: 16px;">Hi {{ $lead->first_name }},</p>

                            <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.6;">
                                Thank you for contacting Kaselog about your <strong>{{ $lead->practice_area }}</strong>
                                enquiry. One of our team will be in touch shortly to discuss how we can help.
                            </p>

                            <p style="margin: 0 0 24px; font-size: 14px; line-height: 1.6;">
                                In the meantime, you can book a consultation directly using the link below.
                            </p>

                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background-color: #0B4F9E; border-radius: 6px;">
                                        <a href="{{ $bookingLink }}" style="display: inline-block; padding: 12px 24px; color: #ffffff; font-size: 14px; font-weight: bold; text-decoration: none;">
                                            Book a consultation
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
