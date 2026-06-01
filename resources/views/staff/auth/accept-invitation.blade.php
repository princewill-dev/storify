<!DOCTYPE html>
<html lang="en">
<head>
    <title>Accept Invitation</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; min-height: 100vh; display: flex; align-items: center; }
        .invite-card { max-width: 460px; width: 100%; margin: 0 auto; }
    </style>
</head>
<body>
    <div class="container">
        <div class="invite-card">
            <div class="text-center mb-4">
                <div class="mb-3">
                    <span class="bg-primary-light text-primary fs-1 rounded-circle p-3">&#9993;</span>
                </div>
                <h3 class="fw-bold">Accept Invitation</h3>
                <p class="text-muted">
                    Welcome, <strong>{{ $user->name }}</strong>!<br>
                    You've been invited to join <strong>{{ $user->vendor?->name ?? 'the team' }}</strong>.
                </p>
                <p class="small text-muted">Set your password to activate your account.</p>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <form method="POST" action="{{ route('management.staff.invitation.accept.store', ['token' => $token]) }}">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" class="form-control @error('password') is-invalid @enderror" required autofocus>
                            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" name="password_confirmation" class="form-control" required>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 py-2">Activate Account</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
