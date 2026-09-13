<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lima Digital Farm Management System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #1b4d3e;
            --primary-dark: #123529;
            --accent: #2e8b57;
            --light-bg: #f4f7f5;
            --text-dark: #2c3e50;
            --text-light: #6c757d;
            --white: #ffffff;
            --transition: all 0.3s ease;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Inter', sans-serif;
        }

        body {
            color: var(--text-dark);
            background-color: var(--white);
            line-height: 1.6;
        }

        /* Navigation */
        nav {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 8%;
            background: var(--white);
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .logo {
            font-weight: 700;
            font-size: 1.4rem;
            color: var(--primary);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-links {
            list-style: none;
            display: flex;
            gap: 30px;
            align-items: center;
        }

        .nav-links a {
            text-decoration: none;
            color: var(--text-dark);
            font-weight: 500;
            font-size: 0.95rem;
            transition: var(--transition);
        }

        .nav-links a:hover {
            color: var(--accent);
        }

        .btn-nav {
            background-color: var(--primary);
            color: var(--white) !important;
            padding: 10px 20px;
            border-radius: 6px;
            transition: var(--transition);
        }

        .btn-nav:hover {
            background-color: var(--primary-dark);
        }

        /* Hero Section */
        .hero {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 50px;
            padding: 100px 8%;
            background: linear-gradient(135deg, #f4f7f5 0%, #e8f0eb 100%);
            align-items: center;
        }

        .hero-content h1 {
            font-size: 3.2rem;
            color: var(--primary-dark);
            line-height: 1.2;
            margin-bottom: 20px;
        }

        .hero-content p {
            font-size: 1.15rem;
            color: var(--text-light);
            margin-bottom: 30px;
        }

        .hero-image-box {
            width: 100%;
            height: 380px;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.12);
        }

        .hero-image-box img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        /* Features Section */
        .features {
            padding: 90px 8%;
            text-align: center;
        }

        .features h2 {
            font-size: 2.5rem;
            color: var(--primary-dark);
            margin-bottom: 15px;
        }

        .section-subtitle {
            color: var(--text-light);
            font-size: 1.1rem;
            max-width: 600px;
            margin: 0 auto 60px auto;
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 30px;
            text-align: left;
        }

        .feature-card {
            background: var(--light-bg);
            padding: 30px;
            border-radius: 10px;
            transition: var(--transition);
            border: 1px solid transparent;
        }

        .feature-card:hover {
            transform: translateY(-5px);
            border-color: var(--accent);
            background: var(--white);
            box-shadow: 0 10px 25px rgba(0,0,0,0.05);
        }

        .feature-card h3 {
            color: var(--primary);
            margin-bottom: 12px;
            font-size: 1.25rem;
        }

        .feature-card p {
            color: var(--text-light);
            font-size: 0.95rem;
        }

        /* Footer */
        footer {
            background: var(--primary-dark);
            color: var(--white);
            text-align: center;
            padding: 40px 8%;
            font-size: 0.9rem;
        }

        footer p {
            opacity: 0.8;
        }

        /* Responsive Breakpoints */
        @media (max-width: 900px) {
            .hero {
                grid-template-columns: 1fr;
                padding: 60px 5%;
                text-align: center;
            }
            .hero-content h1 {
                font-size: 2.5rem;
            }
            .hero-image-box {
                height: 280px;
            }
            .nav-links {
                display: none;
            }
        }
    </style>
</head>
<body>

    <!-- Nav Bar -->
    <nav>
        <a href="/Farm_Management_System/index.php" class="logo">Lima Digital</a>
        <ul class="nav-links">
            <li><a href="/Farm_Management_System/modules/EmployeeManagement/attendanceTracking/attendance.php">Attendance</a></li>
            <li><a href="/Farm_Management_System/modules/EmployeeManagement/employeeReg/employeeReg.php">Employee Registration</a></li>
            <li><a href="/Farm_Management_System/modules/EmployeeManagement/performanceTracking/performance.php">Performance Monitoring</a></li>
            <li><a href="/Farm_Management_System/modules/EmployeeManagement/taskManagement/taskManagement.php">Task Management</a></li>
            <li><a href="/Farm_Management_System/login.php" class="btn-nav">System Portal</a></li>
        </ul>
    </nav>

    <!-- Hero Section -->
    <header class="hero">
        <div class="hero-content">
            <h1>Farm Management Made Seamless</h1>
            <p>Centralize your agricultural operations, track heavy machinery depreciation, log operating hours, automate maintenance schedules, and boost farm productivity through data-driven decisions.</p>
        </div>
        
        <div class="hero-image-box">
            <img src="https://images.unsplash.com/photo-1500382017468-9049fed747ef?auto=format&fit=crop&w=1200&q=80" alt="Green agricultural farm field">
        </div>
    </header>

    <!-- Core Features Section -->
    <section class="features" id="features">
        <h2>Engineered for Modern Agriculture</h2>
        <p class="section-subtitle">Everything you need to oversee heavy assets, field workflows, and financial forecasting in a single unified dashboard.</p>
        
        <div class="feature-grid">
            <div class="feature-card">
                <h3>Machinery & Depreciation</h3>
                <p>Register tractors and heavy capital equipment, monitor real-time asset depreciation curves, and evaluate salvage values automatically.</p>
            </div>
            <div class="feature-card">
                <h3>Operations & Hours Tracking</h3>
                <p>Allocate machinery dynamically to specific field tasks, assign operators, and log live operational hours to measure field productivity.</p>
            </div>
            <div class="feature-card">
                <h3>Maintenance & Reminders</h3>
                <p>Maintain precise service schedules and repair histories while triggering real-time alerts before equipment reaches critical operating thresholds.</p>
            </div>
            <div class="feature-card">
                <h3>Fuel & Resource Control</h3>
                <p>Monitor diesel and petrol consumption per machine unit to minimize overhead costs and optimize your farm budget efficiency.</p>
            </div>
            <div class="feature-card">
                <h3>Centralized Finances</h3>
                <p>Track repair overheads, fuel expenses, and asset lifespans alongside general farm inventory and sales performance metrics.</p>
            </div>
            <div class="feature-card">
                <h3>AI-Assisted Insights</h3>
                <p>Leverage intelligent analytics and recommendations to decide optimal maintenance windows and field deployment cycles.</p>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer>
        <p>&copy; 2026 Lima Digital Farm Management System. All rights reserved.</p>
    </footer>

</body>
</html>