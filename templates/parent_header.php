<?php
// Learnova parent portal header.
$page_title = isset($page_title) ? $page_title : 'Parent Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="application-name" content="Learnova">
    <meta name="theme-color" content="#17a2b8">
    <title><?= htmlspecialchars($page_title, ENT_QUOTES, 'UTF-8') ?> | Learnova</title>
    <link rel="manifest" href="/site.webmanifest">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css">
    <style>
        :root { --learnova-navy: #081f2c; --learnova-teal: #17a2b8; --learnova-cyan: #35d0ff; }
        .sticky-top { top: 20px; }
        .card { transition: transform .2s; }
        .card:hover { transform: translateY(-5px); }
        .navbar-learnova { background: linear-gradient(110deg, var(--learnova-navy), var(--learnova-teal)); }
    </style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark navbar-learnova">
    <div class="container">
        <a class="navbar-brand fw-bold" href="/parent/dashboard.php">Learnova</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav ms-auto">
                <li class="nav-item"><a class="nav-link" href="/parent/profile.php">Profile</a></li>
                <li class="nav-item"><a class="nav-link" href="/logout.php">Logout</a></li>
            </ul>
        </div>
    </div>
</nav>
<script src="/assets/js/learnova-branding.js" defer></script>
