'use strict';
const menu = document.getElementById('navToggle');
if (menu) menu.addEventListener('click', () => document.getElementById('sidebar').classList.toggle('open'));
document.querySelectorAll('[data-confirm]').forEach(el => el.addEventListener('click', e => {if (!confirm(el.dataset.confirm || 'Are you sure?')) e.preventDefault();}));
