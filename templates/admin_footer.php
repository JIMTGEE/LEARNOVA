<?php
// Learnova admin portal footer.
defined('ABSPATH') or exit;
?>
<footer class="admin-footer">
    <div class="footer-content">
        <p class="copyright">&copy; <?= date('Y') ?> Learnova. A new beginning for learning.</p>
        <nav class="footer-nav" aria-label="Footer navigation">
            <a href="/privacy.php">Privacy Policy</a>
            <a href="/terms.php">Terms of Service</a>
            <a href="/contact.php">Support</a>
        </nav>
    </div>
</footer>
<script src="/assets/js/learnova-branding.js" defer></script>
<style>
    .admin-footer {
        background: #081f2c;
        color: #fff;
        padding: 16px;
        text-align: center;
        font-family: var(--font-ui, sans-serif);
        font-size: 14px;
        margin-top: 32px;
    }
    .footer-content { max-width: 1200px; margin: 0 auto; }
    .copyright { margin-bottom: 8px; }
    .footer-nav a { color: #35d0ff; margin: 0 8px; text-decoration: none; }
    .footer-nav a:hover { color: #17a2b8; }
    @media (max-width: 768px) {
        .admin-footer { font-size: 12px; }
        .footer-nav { display: flex; flex-direction: column; gap: 8px; }
    }
</style>
