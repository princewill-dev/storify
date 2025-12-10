<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Live First KYC Application</title>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #dc3545; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; }
        .application-details { background: white; padding: 15px; margin: 20px 0; border-radius: 5px; border-left: 4px solid #dc3545; }
        .customer-info { background: #fff3cd; padding: 15px; margin: 15px 0; border-radius: 5px; }
        .footer { text-align: center; padding: 20px; color: #666; font-size: 12px; }
        .button { display: inline-block; padding: 12px 30px; background: #dc3545; color: white; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        table { width: 100%; border-collapse: collapse; margin: 15px 0; }
        table td { padding: 8px; border-bottom: 1px solid #eee; }
        table td:first-child { font-weight: bold; width: 40%; }
        .urgent-badge { display: inline-block; padding: 5px 15px; background: #ff5722; color: white; border-radius: 20px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚨 New Live First KYC Application</h1>
            <p>Action Required: Review and Approve</p>
        </div>
        
        <div class="content">
            <p><span class="urgent-badge">URGENT</span></p>
            
            <p>A new Live First KYC application has been submitted and requires your review.</p>
            
            <div class="application-details">
                <h2>Application Information</h2>
                <table>
                    <tr>
                        <td>Application ID:</td>
                        <td><strong>{{ $kycId }}</strong></td>
                    </tr>
                    <tr>
                        <td>Submitted On:</td>
                        <td>{{ $application->submitted_at->format('F d, Y \a\t H:i A') }}</td>
                    </tr>
                    <tr>
                        <td>Documents Uploaded:</td>
                        <td>{{ $documentsCount }} files</td>
                    </tr>
                    <tr>
                        <td>Status:</td>
                        <td><strong style="color: #ffc107;">Pending Review</strong></td>
                    </tr>
                </table>
            </div>
            
            <div class="customer-info">
                <h3>Customer Details</h3>
                <table>
                    <tr>
                        <td>Name:</td>
                        <td>{{ $application->full_name }}</td>
                    </tr>
                    <tr>
                        <td>Email:</td>
                        <td>{{ $customer->email }}</td>
                    </tr>
                    <tr>
                        <td>Phone:</td>
                        <td>{{ $application->phone_number }}</td>
                    </tr>
                    <tr>
                        <td>Date of Birth:</td>
                        <td>{{ \Carbon\Carbon::parse($application->date_of_birth)->format('F d, Y') }}</td>
                    </tr>
                </table>
                
                <h3>Employment Information</h3>
                <table>
                    <tr>
                        <td>Employer:</td>
                        <td>{{ $application->employer_name }}</td>
                    </tr>
                    <tr>
                        <td>Years with Employer:</td>
                        <td>{{ $application->years_with_employer }} years</td>
                    </tr>
                </table>
                
                <h3>Residential Information</h3>
                <table>
                    <tr>
                        <td>State:</td>
                        <td>{{ $application->residential_state }}</td>
                    </tr>
                    <tr>
                        <td>LGA:</td>
                        <td>{{ $application->residential_lga }}</td>
                    </tr>
                    <tr>
                        <td>Address:</td>
                        <td>{{ $application->residential_address }}</td>
                    </tr>
                </table>
            </div>
            
            <div class="application-details">
                <h3>📎 Submitted Documents</h3>
                <ul style="margin: 10px 0; padding-left: 20px;">
                    <li>National Identification Number (NIN)</li>
                    <li>Old Payslip</li>
                    <li>Recent Payslip</li>
                    <li>Verification Video</li>
                    <li>Selfie with ID</li>
                    <li>Appointment Letter</li>
                    <li>Bank Authorization Form</li>
                </ul>
            </div>
            
            <p style="text-align: center;">
                <a href="{{ $appUrl }}/superadmin/live-first/applications/{{ $kycId }}" class="button">Review Application Now</a>
            </p>
            
            <p style="color: #666; font-size: 14px; text-align: center; margin-top: 20px;">
                <strong>⏱️ SLA:</strong> Applications should be reviewed within 2-3 business days
            </p>
        </div>
        
        <div class="footer">
            <p>© {{ date('Y') }} {{ $appName }}. All rights reserved.</p>
            <p>This is an automated notification from the Live First program.</p>
        </div>
    </div>
</body>
</html>
