(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		var toggle = document.querySelector('.nxt-nav__toggle');
		var nav = document.getElementById('nxt-primary-nav');

		if (!toggle || !nav) {
			return;
		}

		toggle.addEventListener('click', function (event) {
			event.preventDefault();
			var isOpen = nav.classList.toggle('is-open');
			toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
		});

		document.addEventListener('keyup', function (event) {
			if ('Escape' === event.key && nav.classList.contains('is-open')) {
				nav.classList.remove('is-open');
				toggle.setAttribute('aria-expanded', 'false');
				toggle.focus();
			}
		});
	});
})();
