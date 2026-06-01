<!DOCTYPE html>
<html lang="en">
<head>
    <title>No Store Assigned</title>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="d-flex align-items-center vh-100 bg-light">
    <div class="container text-center">
        <h3 class="mb-2">No POS Store Assigned</h3>
        <p class="text-muted mb-3">You haven't been assigned to any store with POS enabled.</p>
        <form method="POST" action="{{ route('management.auth.logout') }}">
            @csrf
            <button class="btn btn-outline-secondary">Logout</button>
        </form>
    </div>
</body>
</html>
