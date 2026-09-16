@php
    $portalUrl = url("/{$matter->tenant->slug}/{$matter->reference}");
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Set up your client portal account</title>
</head>
<body style="margin: 0; padding: 0; background-color: #F4F5F7; font-family: Arial, Helvetica, sans-serif; color: #1C2430;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #F4F5F7; padding: 24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden;">
                    <tr>
                        <td style="background-color: #1C2430; padding: 20px 24px;">
                            <span style="color: #F4F5F7; font-size: 18px; font-weight: bold;">{{ $matter->tenant->name }}</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 24px;">
                            <p style="margin: 0 0 16px; font-size: 16px;">Hi {{ $client->first_name }},</p>

                            <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.6;">
                                You can now track the progress of your matter <strong>{{ $matter->reference }}</strong>
                                online. Set a password to activate your client portal account — this link is valid
                                until <strong>{{ $expiresAt->format('d M Y, H:i') }}</strong>.
                            </p>

                            <table role="presentation" cellpadding="0" cellspacing="0">
                                <tr>
                                    <td style="background-color: #0B4F9E; border-radius: 6px;">
                                        <a href="{{ $signedUrl }}" style="display: inline-block; padding: 12px 24px; color: #ffffff; font-size: 14px; font-weight: bold; text-decoration: none;">
                                            Set up my portal account
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 24px 0 0; font-size: 12px; line-height: 1.6; color: #4A5568;">
                                Once your account is set up, you can return to your matter at any time at:<br>
                                <span style="color: #0B4F9E;">{{ $portalUrl }}</span>
                            </p>

                            <p style="margin: 24px 0 0; font-size: 12px; line-height: 1.6; color: #4A5568;">
                                If you didn't expect this email, please ignore it or contact us.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 0 24px 24px;">
                            <x-portal.legal-footer :tenant="$matter->tenant" />
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
