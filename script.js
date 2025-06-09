document.addEventListener('DOMContentLoaded', function () {

    // 1. Benefit Icons Animation (Intersection Observer)
    const benefitItems = document.querySelectorAll('.benefit-item');

    if (benefitItems.length > 0) {
        const observerOptions = {
            root: null, // relative to document viewport
            rootMargin: '0px',
            threshold: 0.1 // 10% of item visible
        };

        const observerCallback = (entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    // Remove initial animation classes and add final state classes
                    entry.target.classList.remove('opacity-0', 'translate-y-5');
                    entry.target.classList.add('opacity-100', 'translate-y-0');
                    observer.unobserve(entry.target); // Stop observing once animated
                }
            });
        };

        const benefitObserver = new IntersectionObserver(observerCallback, observerOptions);
        benefitItems.forEach(item => benefitObserver.observe(item));
    }

    // 2. Hero Stats Count-Up Animation (GSAP)
    const heroStatsContainer = document.querySelector('.hero-stats'); // Selector confirmed to be in HTML
    if (heroStatsContainer && typeof gsap !== 'undefined') {
        const statNumbers = heroStatsContainer.querySelectorAll('.stat-number'); // Selector confirmed to be in HTML

        const animateStats = () => {
            statNumbers.forEach(statNumber => {
                // Use data-original-text if available, otherwise fallback to textContent
                const originalText = statNumber.dataset.originalText || statNumber.textContent;
                const targetValue = parseInt(originalText.replace(/\D/g, ''), 10);

                gsap.fromTo(statNumber,
                    { textContent: 0 },
                    {
                        textContent: targetValue,
                        duration: 2,
                        ease: 'power1.out',
                        snap: { textContent: 1 },
                        onUpdate: function() {
                            let currentVal = Math.round(this.targets()[0].textContent);
                            this.targets()[0].textContent = currentVal; // Base number
                            // Re-add '+' if original text had it and it's not zero (or handle other suffixes)
                            if (originalText.includes('+') && currentVal > 0) {
                                this.targets()[0].textContent += '+';
                            } else if (originalText.includes('%') && currentVal > 0) {
                                this.targets()[0].textContent += '%';
                            }
                            // Add more conditions for other suffixes if needed
                        },
                        onComplete: function() {
                            // Ensure final text is exactly as original
                            this.targets()[0].textContent = originalText;
                        }
                    }
                );
            });
        };

        // Ensure original text is stored if not present in HTML (it is, from previous steps)
        statNumbers.forEach(stat => {
            if (!stat.dataset.originalText) {
                 stat.dataset.originalText = stat.textContent;
            }
        });

        const statsObserverOptions = {
            root: null,
            threshold: 0.5 // Trigger when 50% of the element is visible
        };

        let hasAnimatedStats = false; // Ensure animation runs only once

        const statsObserverCallback = (entries, observer) => {
            entries.forEach(entry => {
                if (entry.isIntersecting && !hasAnimatedStats) {
                    animateStats();
                    hasAnimatedStats = true;
                    observer.unobserve(entry.target); // Stop observing after animation
                }
            });
        };

        const statsObserver = new IntersectionObserver(statsObserverCallback, statsObserverOptions);
        statsObserver.observe(heroStatsContainer);

    } else if (typeof gsap === 'undefined') {
        console.warn('GSAP library is not loaded. Hero stats animation will not run.');
    }


    // 3. FAQ Toggle Functionality
    const allFaqItems = document.querySelectorAll('.faq-item'); // Retained class

    allFaqItems.forEach(item => {
        const question = item.querySelector('.faq-question');     // Retained class
        const answer = item.querySelector('.faq-answer');         // Retained class
        const plusIcon = question.querySelector('.plus-icon');    // New class for SVG
        const minusIcon = question.querySelector('.minus-icon');  // New class for SVG

        if (question && answer && plusIcon && minusIcon) {
            question.addEventListener('click', () => {
                const isActive = item.classList.toggle('active');

                if (isActive) {
                    answer.classList.remove('max-h-0', 'opacity-0', 'py-0');
                    answer.classList.add('max-h-[500px]', 'opacity-100', 'py-5', 'sm:py-6'); // Tailwind classes for expanded state
                    plusIcon.classList.add('hidden');
                    minusIcon.classList.remove('hidden');
                } else {
                    answer.classList.add('max-h-0', 'opacity-0', 'py-0');
                    answer.classList.remove('max-h-[500px]', 'opacity-100', 'py-5', 'sm:py-6'); // Tailwind classes for collapsed state
                    plusIcon.classList.remove('hidden');
                    minusIcon.classList.add('hidden');
                }
            });
        }
    });
});
