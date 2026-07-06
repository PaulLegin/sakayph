<?php
require_once __DIR__ . '/../config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SakayPH - Premium Private Car Rental with Driver</title>
    
    <!-- Force Dark Scheme for Extensions (Dark Reader etc.) -->
    <meta name="color-scheme" content="dark">
    <meta name="darkreader-lock">
    
    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Google Fonts (Outfit) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    
    <!-- SEO & Metadata Configuration -->
    <meta name="description" content="SakayPH - Rent premium private chauffeur-driven cars across the Philippines. Safe, reliable, and comfortable private car rentals for your long-distance travel and business trips.">
    <meta name="keywords" content="car rental philippines, chauffeur service manila, rent car with driver, private transport PH, premium travel manila, long distance car rent">
    <meta name="author" content="SakayPH Developer">

    <!-- Open Graph (OG) / Facebook Link Previews -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="http://localhost/sakayph/">
    <meta property="og:title" content="SakayPH - Premium Private Car Rental with Driver">
    <meta property="og:description" content="Safe, reliable, and premium private chauffeur services in the Philippines. Book your exclusive ride with professional drivers today.">
    <meta property="og:image" content="http://localhost/sakayph/uploads/og-preview.jpg">

    <!-- Twitter/X Previews -->
    <meta property="twitter:card" content="summary_large_image">
    <meta property="twitter:url" content="http://localhost/sakayph/">
    <meta property="twitter:title" content="SakayPH - Premium Private Car Rental with Driver">
    <meta property="twitter:description" content="Book exclusive private rides with professional drivers in the Philippines. Safe and reliable chauffeured transport services.">
    <meta property="twitter:image" content="http://localhost/sakayph/uploads/og-preview.jpg">

    <!-- Custom Premium CSS Framework -->
    <style>
        :root {
            color-scheme: dark;
            --bg-main: #0f172a; /* Slate 900 */
            --bg-card: #1e293b; /* Slate 800 */
            --bg-card-hover: #334155; /* Slate 700 */
            --primary: #6366f1; /* Indigo 500 */
            --primary-gradient: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
            --accent-success: #10b981; /* Emerald 500 */
            --accent-success-gradient: linear-gradient(135deg, #10b981 0%, #059669 100%);
            --text-main: #f8fafc; /* Slate 50 */
            --text-muted: #94a3b8; /* Slate 400 */
            --border-color: #334155; /* Slate 700 */
            --border-radius: 16px;
        }

        /* Anti-Inversion Overrides for Dark Mode Extensions */
        h1, h2, h3, h4, h5, h6, label {
            color: var(--text-main) !important;
        }
        p {
            color: var(--text-main);
        }
        
        /* Preserve specifically synchronized colors */
        .text-white { color: #ffffff !important; }
        .text-white-50 { color: rgba(255, 255, 255, 0.5) !important; }
        .text-muted { color: var(--text-muted) !important; }
        .text-primary { color: var(--primary) !important; }
        .text-success { color: var(--accent-success) !important; }
        .text-danger { color: #ef4444 !important; }
        .text-warning { color: #f59e0b !important; }
        .text-info { color: #0ea5e9 !important; }

        body {
            font-family: 'Outfit', sans-serif;
            background-color: var(--bg-main) !important;
            color: var(--text-main) !important;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        /* Glassmorphism Navbar */
        .navbar-custom {
            background-color: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border-color);
            position: sticky;
            top: 0;
            z-index: 1030;
        }

        .navbar-brand-custom {
            font-weight: 800;
            font-size: 1.5rem;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            letter-spacing: -0.5px;
        }

        .navbar-brand-custom span {
            color: var(--text-main);
            -webkit-text-fill-color: var(--text-main);
        }

        /* Premium Cards */
        .card-custom {
            background-color: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--border-radius);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        .card-custom:hover {
            transform: translateY(-5px);
            border-color: var(--primary);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.25), 0 10px 10px -5px rgba(0, 0, 0, 0.15);
        }

        /* Custom Inputs */
        .form-control-custom {
            background-color: rgba(15, 23, 42, 0.7) !important;
            border: 1.5px solid var(--border-color) !important;
            border-radius: 12px !important;
            color: #ffffff !important;
            padding: 0.75rem 1rem !important;
            transition: all 0.2s ease !important;
        }

        .form-control-custom::placeholder {
            color: #64748b !important; /* Muted grey for placeholders */
            opacity: 1 !important;
        }

        .form-control-custom:focus {
            border-color: var(--primary) !important;
            background-color: rgba(15, 23, 42, 0.9) !important;
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.3) !important;
            outline: none !important;
            color: #ffffff !important;
        }

        /* Fix for Chrome Autofill issues where background becomes white/yellow and text invisible */
        .form-control-custom:-webkit-autofill,
        .form-control-custom:-webkit-autofill:hover, 
        .form-control-custom:-webkit-autofill:focus, 
        .form-control-custom:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 1000px #0f172a inset !important;
            -webkit-text-fill-color: #ffffff !important;
            transition: background-color 5000s ease-in-out 0s !important;
        }

        /* Gradient Buttons */
        .btn-gradient-primary {
            background: var(--primary-gradient);
            color: var(--text-main);
            border: none;
            border-radius: 12px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-gradient-primary:hover {
            transform: scale(1.02);
            box-shadow: 0 10px 15px -3px rgba(99, 102, 241, 0.4);
            color: var(--text-main);
        }

        .btn-gradient-success {
            background: var(--accent-success-gradient);
            color: var(--text-main);
            border: none;
            border-radius: 12px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-gradient-success:hover {
            transform: scale(1.02);
            box-shadow: 0 10px 15px -3px rgba(16, 185, 129, 0.4);
            color: var(--text-main);
        }

        .btn-outline-custom {
            border: 1px solid var(--border-color);
            background-color: transparent;
            color: var(--text-main);
            border-radius: 12px;
            padding: 0.75rem 1.5rem;
            font-weight: 600;
            transition: all 0.2s ease;
        }

        .btn-outline-custom:hover {
            background-color: var(--border-color);
            border-color: var(--text-muted);
            color: var(--text-main);
        }

        /* Responsive UI elements */
        .badge-verified {
            background-color: rgba(16, 185, 129, 0.15);
            color: var(--accent-success);
            border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: 8px;
            padding: 0.35rem 0.65rem;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .badge-pending {
            background-color: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: 8px;
            padding: 0.35rem 0.65rem;
            font-size: 0.8rem;
            font-weight: 600;
        }

        /* Map styling */
        #map {
            border-radius: var(--border-radius);
            border: 1px solid var(--border-color);
            height: 400px;
            z-index: 1;
        }
    </style>
</head>
<body>

    <!-- Dynamic Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-custom py-3">
        <div class="container">
            <a class="navbar-brand navbar-brand-custom" href="<?php echo BASE_URL; ?>index.php">
                <i class="bi bi-car-front-fill me-2"></i>Sakay<span>PH</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto align-items-center gap-2">
                    <li class="nav-item">
                        <a class="nav-link text-white" href="<?php echo BASE_URL; ?>index.php">
                            <i class="bi bi-search me-1"></i>Search Rentals
                        </a>
                    </li>
                    
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <?php if ($_SESSION['user_role'] === 'client'): ?>
                            <li class="nav-item">
                                <a class="nav-link text-white" href="<?php echo BASE_URL; ?>client/dashboard.php">
                                    <i class="bi bi-speedometer2 me-1"></i>Client Dashboard
                                </a>
                            </li>
                        <?php elseif ($_SESSION['user_role'] === 'driver'): ?>
                            <li class="nav-item">
                                <a class="nav-link text-white" href="<?php echo BASE_URL; ?>driver/dashboard.php">
                                    <i class="bi bi-speedometer2 me-1"></i>Driver Dashboard
                                </a>
                            </li>
                        <?php elseif ($_SESSION['user_role'] === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link text-white" href="<?php echo BASE_URL; ?>admin/dashboard.php">
                                    <i class="bi bi-shield-lock me-1"></i>Admin Dashboard
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <li class="nav-item ms-lg-2">
                            <div class="d-flex align-items-center gap-2">
                                <span class="text-muted d-none d-lg-inline-block">Hi, <?php echo htmlspecialchars(explode(' ', $_SESSION['user_name'])[0]); ?>!</span>
                                <a class="btn btn-outline-danger btn-sm rounded-pill px-3 py-2" href="<?php echo BASE_URL; ?>logout.php">
                                    <i class="bi bi-box-arrow-right me-1"></i>Logout
                                </a>
                            </div>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link text-white" href="<?php echo BASE_URL; ?>login.php">
                                <i class="bi bi-box-arrow-in-right me-1"></i>Login
                            </a>
                        </li>
                        <li class="nav-item ms-lg-2">
                            <a class="btn btn-gradient-primary px-4 py-2" href="<?php echo BASE_URL; ?>register.php">
                                <i class="bi bi-person-plus me-1"></i>Get Started
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main Content Container wrapper -->
    <main class="flex-grow-1 py-4">
