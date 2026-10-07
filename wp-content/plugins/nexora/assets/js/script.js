document.addEventListener("DOMContentLoaded", () => {

    const elements = document.querySelectorAll('.nx-card, .nx-stat-box');

    const observer = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if(entry.isIntersecting){
                entry.target.style.opacity = 1;
                entry.target.style.transform = 'translateY(0)';
            }
        });
    });

    elements.forEach(el => {
        el.style.opacity = 0;
        el.style.transform = 'translateY(40px)';
        el.style.transition = 'all 0.5s ease';
        observer.observe(el);
    });

});