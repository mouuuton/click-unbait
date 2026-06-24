'use strict';

/*
 * Click Unbait — automatically rewrites article titles via AI.
 *
 * The PHP hook injects a hidden <div class="ai-title-marker" data-entry-id="…">
 * at the start of every article's content. This script finds each marker,
 * locates the matching headline in the DOM, and replaces it with an
 * AI-generated, de-clickbaited title. No button, no clicks: it runs on load
 * and as new articles appear. Results are cached in localStorage so each
 * article's title is only generated once per browser.
 */

(function () {
	var CACHE_PREFIX = 'aiTitle:';
	var MAX_CONCURRENT = 3;

	// Selectors for the headline link inside a FreshRSS entry, most specific first.
	var TITLE_SELECTORS = [
		'.flux_header .title a',
		'.flux_header .item.title a',
		'.flux_header a.item-element.title',
		'.flux_header h1 a',
		'.flux_header h1.title',
		'.title a',
	];

	var queue = [];
	var active = 0;

	function cacheGet(entryId) {
		try {
			return window.localStorage.getItem(CACHE_PREFIX + entryId);
		} catch (e) {
			return null;
		}
	}

	function cacheSet(entryId, title) {
		try {
			window.localStorage.setItem(CACHE_PREFIX + entryId, title);
		} catch (e) {
			/* storage full or unavailable — ignore, we just won't cache */
		}
	}

	// Find the headline element associated with a marker.
	function findTitleEl(marker) {
		var flux = marker.closest('.flux');
		if (!flux) {
			return null;
		}
		for (var i = 0; i < TITLE_SELECTORS.length; i++) {
			var el = flux.querySelector(TITLE_SELECTORS[i]);
			if (el && el.textContent.trim() !== '') {
				return el;
			}
		}
		return null;
	}

	// Normalise an AI-produced title: single line, no wrapping quotes.
	function cleanTitle(text) {
		var t = (text || '').replace(/\s+/g, ' ').trim();
		// Keep only the first line if the model returned several.
		t = t.split('\n')[0].trim();
		// Strip a single pair of surrounding quotes (straight or curly).
		t = t.replace(/^["'“‘«]\s*/, '').replace(/\s*["'”’»]$/, '');
		return t.trim();
	}

	function applyTitle(el, newTitle) {
		newTitle = cleanTitle(newTitle);
		if (newTitle === '') {
			return;
		}
		var original = el.dataset.aiOriginalTitle;
		if (typeof original === 'undefined') {
			original = el.textContent.trim();
			el.dataset.aiOriginalTitle = original;
		}
		if (el.textContent.trim() === newTitle) {
			return;
		}
		el.textContent = newTitle;
		el.classList.add('ai-title-replaced');
		// Reveal the original on hover so nothing is lost.
		el.setAttribute('title', original);
	}

	function pump() {
		while (active < MAX_CONCURRENT && queue.length > 0) {
			var job = queue.shift();
			active++;
			runJob(job).then(function () {
				active--;
				pump();
			});
		}
	}

	function enqueue(entryId, el) {
		queue.push({ entryId: entryId, el: el });
		pump();
	}

	function runJob(job) {
		var formData = new URLSearchParams();
		formData.append('_csrf', context.csrf);
		formData.append('id', job.entryId);

		return fetch('./?c=AiTitle&a=title', {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: formData.toString(),
		}).then(function (response) {
			var contentType = response.headers.get('Content-Type') || '';

			// JSON error response (validation errors) — give up silently on this entry.
			if (contentType.indexOf('application/json') !== -1) {
				return response.json().then(function (data) {
					if (data && data.error) {
						console.warn('Click Unbait: ' + data.error);
					}
				});
			}

			// SSE streaming response — accumulate chunks, apply once complete.
			var reader = response.body.getReader();
			var decoder = new TextDecoder();
			var sseBuffer = '';
			var currentEvent = '';
			var fullText = '';
			var failed = false;

			function processLine(line) {
				if (line.indexOf('event: ') === 0) {
					currentEvent = line.substring(7);
				} else if (line.indexOf('data: ') === 0) {
					var data;
					try { data = JSON.parse(line.substring(6)); } catch (ex) { return; }

					if (currentEvent === 'chunk') {
						fullText += data.text || '';
					} else if (currentEvent === 'error') {
						failed = true;
						console.warn('Click Unbait: ' + (data.message || 'unknown error'));
					}
					currentEvent = '';
				}
			}

			function finish() {
				if (!failed && fullText.trim() !== '') {
					var clean = cleanTitle(fullText);
					if (clean !== '') {
						applyTitle(job.el, clean);
						cacheSet(job.entryId, clean);
					}
				}
			}

			function read() {
				return reader.read().then(function (result) {
					if (result.done) {
						if (sseBuffer.trim()) {
							processLine(sseBuffer.trim());
						}
						finish();
						return;
					}
					sseBuffer += decoder.decode(result.value, { stream: true });
					var lines = sseBuffer.split('\n');
					sseBuffer = lines.pop();
					lines.forEach(function (line) {
						line = line.trim();
						if (line) {
							processLine(line);
						}
					});
					return read();
				});
			}

			return read();
		}).catch(function (err) {
			console.warn('Click Unbait: ' + err.message);
		});
	}

	function processMarkers() {
		var markers = document.querySelectorAll('.ai-title-marker:not(.ai-title-processed)');
		markers.forEach(function (marker) {
			var entryId = marker.dataset.entryId;
			if (!entryId) {
				marker.classList.add('ai-title-processed');
				return;
			}

			var titleEl = findTitleEl(marker);
			// Headline not in the DOM yet (collapsed/lazy view): leave the marker
			// unprocessed so a later observer/poll tick can retry.
			if (!titleEl) {
				return;
			}

			marker.classList.add('ai-title-processed');

			var cached = cacheGet(entryId);
			if (cached) {
				applyTitle(titleEl, cached);
				return;
			}

			enqueue(entryId, titleEl);
		});
	}

	// Run on load.
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', processMarkers);
	} else {
		processMarkers();
	}

	// React to dynamically loaded articles (FreshRSS injects entries via AJAX).
	var observer = new MutationObserver(function () {
		processMarkers();
	});
	if (document.body) {
		observer.observe(document.body, { childList: true, subtree: true });
	} else {
		document.addEventListener('DOMContentLoaded', function () {
			observer.observe(document.body, { childList: true, subtree: true });
		});
	}

	// Fallback poll for views that inject content in ways MutationObserver misses.
	setInterval(processMarkers, 1500);
})();
