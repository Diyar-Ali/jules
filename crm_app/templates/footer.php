<?php
// templates/footer.php
$app_name = "Connect CRM"; // Consistent with header
$current_year = date('Y');
?>
        </main> <?php // End of flex-grow main content area from header.php ?>
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="container mx-auto px-4 sm:px-6 lg:px-8 py-4 text-center text-sm text-gray-500">
                &copy; <?= $current_year ?> <?= htmlspecialchars($app_name) ?>. All rights reserved.
                <p class="text-xs">Developed by Jules AI for Small Consulting Company, Amsterdam.</p>
            </div>
        </footer>

        <script>
            // Basic mobile menu toggle
            const mobileMenuButton = document.getElementById('mobile-menu-button');
            const mobileMenu = document.getElementById('mobile-menu');
            const iconBurger = document.getElementById('icon-burger');
            const iconClose = document.getElementById('icon-close');

            if (mobileMenuButton && mobileMenu && iconBurger && iconClose) {
                mobileMenuButton.addEventListener('click', () => {
                    const expanded = mobileMenuButton.getAttribute('aria-expanded') === 'true' || false;
                    mobileMenuButton.setAttribute('aria-expanded', !expanded);
                    mobileMenu.classList.toggle('hidden');
                    iconBurger.classList.toggle('hidden');
                    iconClose.classList.toggle('hidden');
                });
            }

            /**
             * Generic confirmation dialog for hard delete actions.
             * @param {Event} event - The form submission event.
             * @param {string} entityType - The type of entity being deleted (e.g., 'user', 'organisation').
             */
            function confirmHardDelete(event, entityType = 'item') {
                let message = `Are you sure you want to PERMANENTLY DELETE this ${entityType}? This action cannot be undone.`;

                if (entityType === 'activity') {
                    message += '\\nThis will also delete all attached files.';
                } else if (entityType === 'organisation') {
                    message += '\\nNote: Deletion may fail if there are related records like active deals or contacts.';
                } else if (entityType === 'user') {
                    message += '\\nNote: Deletion may fail if the user is referenced by other records or is the last admin.';
                }
                // Add other entity-specific warnings here as needed

                if (!confirm(message)) {
                    event.preventDefault(); // Stop form submission
                }
            }


            // Optional: Auto-close success/error messages after a few seconds
            // This requires messages to have a common class, e.g., 'dismissable-alert'
            // and an ID or way to select them.
            document.addEventListener('DOMContentLoaded', () => {
                setTimeout(() => {
                    const alerts = document.querySelectorAll('.dismissable-alert');
                    alerts.forEach(alert => {
                        if (alert) {
                            alert.style.transition = 'opacity 0.5s ease-out';
                            alert.style.opacity = '0';
                            setTimeout(() => alert.remove(), 500); // Remove from DOM after fade
                        }
                    });
                }, 5000); // Hide after 5 seconds
            });
        </script>
        <?php // Potential global JS includes
        ?>
    </body>
    </html>
