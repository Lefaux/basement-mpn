import './stimulus_bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import './styles/app.css';

document.addEventListener('click', (event) => {
    const close = event.target.closest('.inspector-close');
    if (close) { close.closest('.track-card').classList.remove('selected'); return; }
    const previewClose = event.target.closest('.audio-preview-close');
    if (previewClose) { const preview = previewClose.closest('.audio-preview'); preview.classList.remove('open'); preview.querySelector('iframe').src = ''; return; }
    const audioLink = event.target.closest('.audio-link');
    if (audioLink) { event.preventDefault(); const preview = document.querySelector('.audio-preview'); preview.querySelector('iframe').src = audioLink.dataset.previewUrl; preview.classList.add('open'); return; }
    const mainFilter = event.target.closest('[data-main-filter]');
    if (mainFilter) {
        const url = new URL(window.location.href);
        const selected = url.searchParams.getAll('file_filter[]');
        const key = mainFilter.dataset.mainFilter;
        const next = selected.includes(key) ? selected.filter((item) => item !== key) : [...selected, key];
        url.searchParams.delete('file_filter[]');
        next.forEach((item) => url.searchParams.append('file_filter[]', item));
        window.location.assign(url);
        return;
    }
    const card = event.target.closest('.card-select');
    if (card) {
        const track = card.closest('.track-card');
        const wasSelected = track.classList.contains('selected');
        document.querySelectorAll('.track-card.selected').forEach((item) => item.classList.remove('selected'));
        if (!wasSelected) track.classList.add('selected');
    }

    const filter = event.target.closest('[data-issue-filter]');
    if (!filter) return;

    filter.classList.toggle('active');
    const selected = [...document.querySelectorAll('[data-issue-filter].active')]
        .map((button) => button.dataset.issueFilter);

    applyListFilters();
});

document.addEventListener('change', (event) => {
    if (event.target.matches('.list-filter')) applyListFilters();
});

function applyListFilters() {
    const selectedIssues = [...document.querySelectorAll('[data-issue-filter].active')].map((button) => button.dataset.issueFilter);
    const filters = Object.fromEntries([...document.querySelectorAll('.list-filter')].map((select) => [select.dataset.filterKey, select.value]));
    document.querySelectorAll('.issue-list .track-card').forEach((track) => {
        const issues = [...track.querySelectorAll('.issue')].map((issue) => issue.textContent.trim());
        const issueMatch = selectedIssues.length === 0 || selectedIssues.some((issue) => issues.includes(issue));
        const dropdownMatch = Object.entries(filters).every(([key, value]) => value === '' || track.dataset[key] === value);
        track.hidden = !issueMatch || !dropdownMatch;
    });
}

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');
