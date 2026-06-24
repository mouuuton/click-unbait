'use strict';

/*
 * Click Unbait — automatically rewrites article titles via AI.
 *
 * It scans the article list for FreshRSS entry containers (div.flux), grabs
 * the entry id and the headline (h1.title > a), and replaces the headline
 * with an AI-generated, de-clickbaited title. No button, no clicks: it runs
 * on load and as new articles appear. Results are cached in localStorage so
 * each article's title is only generated once per browser.
 *
 * The article content is NOT needed in the page — the PHP controller fetches
 * it server-side — so this works even when the entry is collapsed.
 *
 * Set `window.AI_TITLE_DEBUG = true` in the console for verbose logging.
 */

(function () {
	var CACHE_PREFIX = 'aiTitle:';
	var MAX_CONCURRENT = 3;

	// Headline link inside a FreshRSS entry, most specific first.
	var TITLE_SELECTORS = [
		'h1.title a',
		'.title a',
		'a.go_website',
		'h1.title',
		'.item.title a',
		'.title',
	];

	var queue = [];
	var active = 0;
	var loggedNoTitle = false;
	var processedCount = 0;

	function log() {
		if (window.AI_TITLE_DEBUG) {
			console.log.apply(console, ['[Click Unbait]'].concat([].slice.call(arguments)));
		}
	}
	function warn(msg) {
		console.warn('[Click Unbait] ' + msg);
	}

	console.info('[Click Unbait] active — set window.AI_TITLE_DEBUG = true for verbose logs');

	function cacheGet(entryId) {
		try { return window.localStorage.getItem(CACHE_PREFIX + entryId); }
		catch (e) { return null; }
	}
	function cacheSet(entryId, title) {
		try { window.localStorage.setItem(CACHE_PREFIX + entryId, title); }
		catch (e) { /* storage full/unavailable — skip caching */ }
	}

	// Resolve the entry id from a .flux container.
	function getEntryId(flux) {
		if (flux.dataset && flux.dataset.entry) {
			return flux.dataset.entry;
		}
		if (flux.id && flux.id.indexOf('flux_') === 0) {
			return flux.id.slice(5);
		}
		var marker = flux.querySelector('.ai-title-marker[data-entry-id]');
		if (marker) {
			return marker.dataset.entryId;
		}
		return null;
	}

	function findTitleEl(flux) {
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
		t = t.split('\n')[0].trim();
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
		el.setAttribute('title', original); // hover shows the original
		log('replaced:', JSON.stringify(original), '→', JSON.stringify(newTitle));
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
		if (typeof context === 'undefined' || !context.csrf) {
			warn('FreshRSS CSRF token (context.csrf) not available — cannot call the API');
			return Promise.resolve();
		}

		var formData = new URLSearchParams();
		formData.append('_csrf', context.csrf);
		formData.append('id', job.entryId);

		log('requesting title for entry', job.entryId);

		return fetch('./?c=AiTitle&a=title', {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: formData.toString(),
		}).then(function (response) {
			var contentType = response.headers.get('Content-Type') || '';

			if (contentType.indexOf('application/json') !== -1) {
				return response.json().then(function (data) {
					if (data && data.error) {
						warn('entry ' + job.entryId + ': ' + data.error);
					}
				});
			}

			if (!response.body || !response.body.getReader) {
				warn('entry ' + job.entryId + ': unexpected response (status ' + response.status + ', type "' + contentType + '")');
				return;
			}

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
						warn('entry ' + job.entryId + ': ' + (data.message || 'unknown error'));
					}
					currentEvent = '';
				}
			}

			function finish() {
				if (failed) { return; }
				var clean = cleanTitle(fullText);
				if (clean !== '') {
					applyTitle(job.el, clean);
					cacheSet(job.entryId, clean);
				} else {
					warn('entry ' + job.entryId + ': empty response from AI');
				}
			}

			function read() {
				return reader.read().then(function (result) {
					if (result.done) {
						if (sseBuffer.trim()) { processLine(sseBuffer.trim()); }
						finish();
						return;
					}
					sseBuffer += decoder.decode(result.value, { stream: true });
					var lines = sseBuffer.split('\n');
					sseBuffer = lines.pop();
					lines.forEach(function (line) {
						line = line.trim();
						if (line) { processLine(line); }
					});
					return read();
				});
			}

			return read();
		}).catch(function (err) {
			warn('entry ' + job.entryId + ': ' + err.message);
		});
	}

	function processEntries() {
		var entries = document.querySelectorAll('.flux:not(.ai-title-done)');
		entries.forEach(function (flux) {
			var titleEl = findTitleEl(flux);
			// Headline not in the DOM yet — leave it for a later tick.
			if (!titleEl) {
				if (!loggedNoTitle) {
					loggedNoTitle = true;
					log('no headline found in a .flux container yet (will keep retrying). Sample:', flux.outerHTML.slice(0, 300));
				}
				return;
			}

			var entryId = getEntryId(flux);
			if (!entryId) {
				flux.classList.add('ai-title-done');
				warn('could not determine entry id for a .flux container; skipping');
				return;
			}

			flux.classList.add('ai-title-done');
			processedCount++;
			if (processedCount === 1) {
				log('first entry processed (id ' + entryId + ')');
			}

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
		document.addEventListener('DOMContentLoaded', processEntries);
	} else {
		processEntries();
	}

	// React to dynamically loaded articles (FreshRSS injects entries via AJAX).
	var observer = new MutationObserver(function () { processEntries(); });
	if (document.body) {
		observer.observe(document.body, { childList: true, subtree: true });
	} else {
		document.addEventListener('DOMContentLoaded', function () {
			observer.observe(document.body, { childList: true, subtree: true });
		});
	}

	// Fallback poll for views that inject content in ways MutationObserver misses.
	setInterval(processEntries, 1500);
})();
