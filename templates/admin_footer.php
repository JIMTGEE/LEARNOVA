<?php
// templates/admin_footer.php - Admin Footer Template
// Matches ultra modern design system with sea blue accents

// Prevent direct access
defined('ABSPATH') or exit;
?>

<footer class="admin-footer">
    <div class="footer-content">
        <p class="copyright">&copy; <?php echo date('Y'); ?> Edusync School Management System. All rights reserved.</p>
        <p class="powered-by">Powered by xAI Grok - Version 1.0</p>
        <nav class="footer-nav">
            <a href="/privacy.php">Privacy Policy</a>
            <a href="/terms.php">Terms of Service</a>
            <a href="/support.php">Support</a>
        </nav>
    </div>
</footer>

<style>
    .admin-footer {
        background-color: var(--accent-deep-navy);
        color: var(--primary-white);
        padding: 16px;
        text-align: center;
        font-family: var(--font-ui);
        font-size: 14px;
        margin-top: 32px;
    }

    .footer-content {
        max-width: 1200px;
        margin: 0 auto;
    }

    .copyright {
        margin-bottom: 8px;
    }

    .powered-by {
        opacity: 0.8;
        margin-bottom: 8px;
    }

    .footer-nav a {
        color: var(--accent-soft-cyan);
        margin: 0 8px;
        text-decoration: none;
        transition: color 0.2s;
    }

    .footer-nav a:hover {
        color: var(--primary-sea-blue);
    }

    @media (max-width: 768px) {
        .admin-footer {
            font-size: 12px;
        }

        .footer-nav {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
    }
</style>