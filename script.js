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
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target); // Optional: stop observing once animated
                }
            });
        };

        const benefitObserver = new IntersectionObserver(observerCallback, observerOptions);
        benefitItems.forEach(item => benefitObserver.observe(item));
    }

    // 2. Hero Stats Count-Up Animation (GSAP)
    const heroStatsContainer = document.querySelector('.hero-stats');
    if (heroStatsContainer && typeof gsap !== 'undefined') {
        const statNumbers = document.querySelectorAll('.stat-number');

        const animateStats = () => {
            statNumbers.forEach(statNumber => {
                const targetValue = parseInt(statNumber.textContent.replace(/\D/g, ''), 10); // Extract number
                gsap.fromTo(statNumber,
                    { textContent: 0 },
                    {
                        textContent: targetValue,
                        duration: 2,
                        ease: 'power1.out',
                        snap: { textContent: 1 }, // Snap to whole numbers
                        // Modifiers to add '+' if it was there or other formatting
                        onUpdate: function() {
                            // Re-add '+' if original text had it and it's not zero
                            if (statNumber.dataset.originalText && statNumber.dataset.originalText.includes('+')) {
                                if (Math.round(this.targets()[0].textContent) > 0) {
                                     this.targets()[0].textContent = Math.round(this.targets()[0].textContent) + '+';
                                } else {
                                     this.targets()[0].textContent = Math.round(this.targets()[0].textContent);
                                }
                            } else {
                                this.targets()[0].textContent = Math.round(this.targets()[0].textContent);
                            }
                        },
                        onComplete: function() { // Ensure final text is exactly as original if complex
                             if(statNumber.dataset.originalText) {
                                 this.targets()[0].textContent = statNumber.dataset.originalText;
                             }
                        }
                    }
                );
            });
        };

        // Store original text for formatting
        statNumbers.forEach(stat => {
            stat.dataset.originalText = stat.textContent;
        });

        // Use Intersection Observer to trigger animation when .hero-stats is in view
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
    const faqItems = document.querySelectorAll('.faq-item');

    faqItems.forEach(item => {
        const question = item.querySelector('.faq-question');
        if (question) {
            question.addEventListener('click', () => {
                // Optional: Close other open FAQs
                // faqItems.forEach(otherItem => {
                //     if (otherItem !== item && otherItem.classList.contains('active')) {
                //         otherItem.classList.remove('active');
                //     }
                // });
                item.classList.toggle('active');
            });
        }
    });

});
