/* Monarch admin app — vanilla JS, no build step. */
(function () {
	'use strict';

	var C = window.Monarch || {};
	var app = document.getElementById('monarch-app');
	if (!app) { return; }

	/* ================= helpers ================= */
	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function $(sel, root) { return (root || app).querySelector(sel); }
	function $$(sel, root) { return Array.prototype.slice.call((root || app).querySelectorAll(sel)); }
	function on(sel, ev, fn) { var el = $(sel); if (el) { el.addEventListener(ev, fn); } return el; }
	function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
	function fmtBytes(b) {
		if (!b) { return '0 B'; }
		var u = ['B', 'KB', 'MB', 'GB', 'TB'], i = Math.min(4, Math.floor(Math.log(b) / Math.log(1024)));
		return (b / Math.pow(1024, i)).toFixed(i ? 1 : 0) + ' ' + u[i];
	}
	function fmtDur(s) {
		if (s == null || !isFinite(s)) { return '—'; }
		s = Math.max(0, Math.round(s));
		var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), r = s % 60;
		return h ? h + 'h ' + m + 'm' : (m ? m + 'm ' + (r < 10 ? '0' : '') + r + 's' : r + 's');
	}
	function clock(t) {
		var d = t ? new Date(t * 1000) : new Date();
		return d.toLocaleTimeString([], { hour12: false });
	}

	function icon(name) {
		var p = {
			upload: '<path d="M12 15V4m0 0L8 8m4-4 4 4"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
			download: '<path d="M12 4v11m0 0 4-4m-4 4-4-4"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
			send: '<path d="m21 3-9.5 9.5M21 3l-6.5 18-3-8.5L3 9.5 21 3Z"/>',
			check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
			alert: '<path d="M12 8v5m0 3.5v.01"/><path d="M10.3 3.9 2.2 18a2 2 0 0 0 1.7 3h16.2a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>',
			info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5m0-8.5v.01"/>',
			copy: '<rect x="9" y="9" width="11" height="11" rx="1.5"/><path d="M5 15V5.5A1.5 1.5 0 0 1 6.5 4H15"/>',
			archive: '<rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8M10 12h4"/>',
			key: '<circle cx="7.5" cy="15.5" r="3.5"/><path d="m10 13 9-9m-3 3 2 2m-4 0 1.5 1.5"/>',
			restore: '<path d="M3 12a9 9 0 1 0 2.6-6.4L3 8"/><path d="M3 3v5h5"/>',
			trash: '<path d="M4 7h16M10 11v6m4-6v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/>',
			left: '<path d="m15 18-6-6 6-6"/>',
			arrow: '<path d="M5 12h14m-5-5 5 5-5 5"/>',
			terminal: '<path d="m5 8 4 4-4 4m6 0h8"/>',
			scroll: '<path d="M12 5v14m0 0 5-5m-5 5-5-5"/>',
			expand: '<path d="M4 9V4h5M20 9V4h-5M4 15v5h5m11-5v5h-5"/>',
			file: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z"/><path d="M14 3v5h5"/>',
			ext: '<path d="M14 4h6v6m0-6-9 9"/><path d="M18 14v4a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4"/>'
		}[name] || '';
		return '<svg class="pb-i" viewBox="0 0 24 24" aria-hidden="true">' + p + '</svg>';
	}

	/* ================= AJAX with retry ================= */
	function api(sub, data, opts) {
		opts = opts || {};
		var tries = opts.retries == null ? 5 : opts.retries;
		var attempt = 0;
		function once() {
			var fd = new FormData();
			fd.append('action', 'monarch_step');
			fd.append('nonce', C.nonce);
			fd.append('sub', sub);
			Object.keys(data || {}).forEach(function (k) {
				var v = data[k];
				if (v instanceof Blob) { fd.append(k, v, 'chunk.bin'); } else { fd.append(k, typeof v === 'object' ? JSON.stringify(v) : v); }
			});
			return fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
				return r.text().then(function (t) {
					var j = null;
					try { j = JSON.parse(t); } catch (e) {
						var m = t.match(/\{"success":(true|false)[\s\S]*\}$/);
						if (m) { try { j = JSON.parse(m[0]); } catch (e2) { j = null; } }
					}
					if (r.status >= 500 || !j) {
						var err = new Error('Server error (HTTP ' + r.status + ')');
						err.retry = true;
						throw err;
					}
					if (!j.success) {
						var e3 = new Error((j.data && j.data.message) || 'Request failed');
						e3.data = j.data;
						throw e3;
					}
					return j.data;
				});
			}, function () {
				var e = new Error('Network error — check your connection.');
				e.retry = true;
				throw e;
			});
		}
		function run() {
			return once().catch(function (e) {
				if (e.retry && attempt < tries) {
					attempt++;
					var wait = Math.min(16000, 1000 * Math.pow(2, attempt - 1));
					Log.local('Request failed (' + e.message + ') — retrying in ' + (wait / 1000) + 's (attempt ' + attempt + ' of ' + tries + ')', 'warn');
					return sleep(wait).then(run);
				}
				throw e;
			});
		}
		return run();
	}

	function toast(msg, type) {
		var t = document.createElement('div');
		t.className = 'pb-toast pb-toast--' + (type || 'info');
		t.setAttribute('role', 'status');
		t.textContent = msg;
		document.body.appendChild(t);
		requestAnimationFrame(function () { t.classList.add('is-in'); });
		setTimeout(function () { t.classList.remove('is-in'); setTimeout(function () { t.remove(); }, 250); }, 3600);
	}
	function copy(text, what) {
		function ok() { toast((what || 'Text') + ' copied', 'ok'); }
		if (navigator.clipboard && window.isSecureContext) {
			navigator.clipboard.writeText(text).then(ok, function () { toast('Copy failed', 'err'); });
		} else {
			var ta = document.createElement('textarea');
			ta.value = text; ta.style.position = 'fixed'; ta.style.opacity = '0';
			document.body.appendChild(ta); ta.select();
			try { document.execCommand('copy'); ok(); } catch (e) { toast('Copy failed', 'err'); }
			ta.remove();
		}
	}

	/* ================= state ================= */
	var S = {
		tab: 'migrate',
		running: false,
		backups: C.backups || [],
		settings: C.settings || {},
		job: null,
		started: 0,
		cancelFlag: false,
		exportOpts: { database: true, uploads: true, plugins: true, themes: true, muplugins: true, other: true, no_spam: true, no_revisions: false, no_transients: true }
	};
	try { var saved = JSON.parse(localStorage.getItem('pb_export_opts') || 'null'); if (saved) { S.exportOpts = Object.assign(S.exportOpts, saved); } } catch (e) { /* ignore */ }

	window.addEventListener('beforeunload', function (e) {
		if (S.running) { e.preventDefault(); e.returnValue = ''; return ''; }
	});

	/* ================= live log component ================= */
	var Log = {
		seq: 0,
		entries: [],
		follow: true,
		filter: 'all',
		reset: function () { this.seq = 0; this.entries = []; this.follow = true; },
		html: function (title) {
			return '<section class="pb-panel pb-log">' +
				'<div class="pb-log__head">' + icon('terminal') + '<h3>' + esc(title || 'Activity') + '</h3><span class="pb-live" data-live>Idle</span>' +
				'<div class="pb-log__filters" role="group" aria-label="Filter log"><button type="button" data-lf="all" class="is-active">All</button><button type="button" data-lf="warn">Warnings &amp; errors</button></div>' +
				'<span class="pb-grow" style="flex:1"></span>' +
				'<button type="button" class="pb-icon-btn is-on" data-lfollow title="Auto-scroll to newest" aria-pressed="true">' + icon('scroll') + '</button>' +
				'<button type="button" class="pb-icon-btn" data-lexpand title="Taller log">' + icon('expand') + '</button>' +
				'<button type="button" class="pb-icon-btn" data-lcopy title="Copy log">' + icon('copy') + '</button>' +
				'<button type="button" class="pb-icon-btn" data-ldl title="Download log as .txt">' + icon('download') + '</button>' +
				'</div><div class="pb-log__body" data-lbody role="log" aria-live="polite"><div class="pb-log__empty">Waiting for the first update…</div></div></section>';
		},
		bind: function () {
			var self = this, body = $('[data-lbody]');
			if (!body) { return; }
			this.paint();
			body.addEventListener('scroll', function () {
				var atBottom = body.scrollHeight - body.scrollTop - body.clientHeight < 24;
				if (atBottom !== self.follow) { self.follow = atBottom; self.syncFollow(); }
			});
			on('[data-lfollow]', 'click', function () { self.follow = !self.follow; self.syncFollow(); if (self.follow) { body.scrollTop = body.scrollHeight; } });
			on('[data-lexpand]', 'click', function () { body.classList.toggle('is-tall'); });
			on('[data-lcopy]', 'click', function () { copy(self.text(), 'Log'); });
			on('[data-ldl]', 'click', function () {
				var a = document.createElement('a');
				a.href = URL.createObjectURL(new Blob([self.text()], { type: 'text/plain' }));
				a.download = 'monarch-log-' + new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-') + '.txt';
				document.body.appendChild(a); a.click(); a.remove();
			});
			$$('[data-lf]').forEach(function (b) {
				b.addEventListener('click', function () {
					self.filter = b.dataset.lf;
					$$('[data-lf]').forEach(function (x) { x.classList.toggle('is-active', x === b); });
					body.classList.toggle('f-warn', self.filter === 'warn');
				});
			});
		},
		syncFollow: function () {
			var b = $('[data-lfollow]');
			if (b) { b.classList.toggle('is-on', this.follow); b.setAttribute('aria-pressed', String(this.follow)); }
		},
		live: function (state) {
			var el = $('[data-live]');
			if (!el) { return; }
			el.className = 'pb-live' + (state === 'on' ? ' is-on' : '');
			el.textContent = state === 'on' ? 'Live' : (state === 'done' ? 'Finished' : (state === 'paused' ? 'Paused' : 'Idle'));
		},
		line: function (e) {
			return '<div class="pb-log__line l-' + esc(e.level) + '"><span class="pb-log__time">' + esc(clock(e.t)) + '</span><span class="pb-log__lvl" aria-hidden="true"></span><span class="pb-log__msg">' + esc(e.msg) + '</span></div>';
		},
		paint: function () {
			var body = $('[data-lbody]');
			if (!body || !this.entries.length) { return; }
			body.innerHTML = this.entries.map(this.line).join('');
			body.scrollTop = body.scrollHeight;
		},
		push: function (list) {
			var self = this, body = $('[data-lbody]');
			if (!list || !list.length) { return; }
			var fresh = list.filter(function (e) { return e.seq > self.seq; });
			if (!fresh.length) { return; }
			fresh.forEach(function (e) { self.seq = Math.max(self.seq, e.seq); self.entries.push(e); });
			if (this.entries.length > 3000) { this.entries = this.entries.slice(-3000); }
			if (!body) { return; }
			var empty = body.querySelector('.pb-log__empty');
			if (empty) { empty.remove(); }
			body.insertAdjacentHTML('beforeend', fresh.map(this.line).join(''));
			if (this.follow) { body.scrollTop = body.scrollHeight; }
		},
		local: function (msg, level) {
			this.push([{ seq: this.seq + 0.001, t: Date.now() / 1000, level: level || 'info', msg: msg }]);
		},
		replace: function (lines) { // for croc output (not sequence based)
			var body = $('[data-lbody]');
			if (!body || !lines || !lines.length) { return; }
			body.innerHTML = lines.map(function (l) { return '<div class="pb-log__line"><span class="pb-log__time">' + esc(clock()) + '</span><span class="pb-log__lvl"></span><span class="pb-log__msg">' + esc(l) + '</span></div>'; }).join('');
			if (this.follow) { body.scrollTop = body.scrollHeight; }
		},
		text: function () {
			return this.entries.map(function (e) { return clock(e.t) + '  [' + e.level.toUpperCase() + ']  ' + e.msg; }).join('\n') || ($('[data-lbody]') ? $('[data-lbody]').innerText : '');
		}
	};

	/* ================= layout ================= */
	function render(inner, opts) {
		opts = opts || {};
		var tabs = [['migrate', 'Migrate'], ['backups', 'Backups'], ['settings', 'Settings']];
		app.innerHTML =
			'<div class="pb-head">' + (C.icon ? '<img class="pb-logo" src="' + esc(C.icon) + '" alt="" width="32" height="32">' : '') + '<h1>Monarch <span class="pb-sub">Site Migration</span></h1><span class="pb-version">v' + esc(C.version) + '</span></div>' +
			'<p class="pb-lede">Move this WordPress site to another server — database, media, plugins and themes.</p>' +
			'<nav class="pb-nav" aria-label="Monarch sections">' + tabs.map(function (t) {
				return '<button type="button" data-tab="' + t[0] + '" class="' + (S.tab === t[0] ? 'is-active' : '') + '"' + (S.tab === t[0] ? ' aria-current="page"' : '') + (S.running ? ' disabled' : '') + '>' + t[1] +
					(t[0] === 'backups' ? '<span class="pb-count" data-bcount>' + S.backups.length + '</span>' : '') + '</button>';
			}).join('') + '</nav>' +
			(opts.sidebar ? '<div class="pb-layout"><div class="pb-stack">' + inner + '</div><aside class="pb-stack">' + sidebar() + '</aside></div>' : '<div class="pb-stack">' + inner + '</div>');
		$$('[data-tab]').forEach(function (b) {
			b.addEventListener('click', function () { S.tab = b.dataset.tab; route(); });
		});
		$$('[data-copy]').forEach(function (b) { b.addEventListener('click', function () { copy(b.dataset.copy, b.dataset.what || 'Text'); }); });
	}

	function sidebar() {
		var env = (C.env || []).map(function (r) {
			var bad = /disabled/.test(r[1]);
			return '<div><dt>' + esc(r[0]) + '</dt><dd' + (bad ? ' class="is-bad"' : '') + '>' + esc(r[1]) + '</dd></div>';
		}).join('');
		var recent = S.backups.slice(0, 4).map(function (b) {
			return '<li><strong>' + esc(b.name) + '</strong><span>' + esc(b.date) + ' · ' + esc(b.human) + '</span></li>';
		}).join('');
		return '<section class="pb-panel"><div class="pb-panel__head"><h3>This server</h3></div><dl class="pb-facts">' + env + '</dl></section>' +
			'<section class="pb-panel"><div class="pb-panel__head"><h3 class="pb-grow">Recent backups</h3>' + (S.backups.length ? '<button type="button" class="pb-btn pb-btn--link" data-tab-go="backups" style="color:var(--pb-accent)">View all</button>' : '') + '</div>' +
			(recent ? '<ul class="pb-mini">' + recent + '</ul>' : '<p class="pb-empty-mini">No backups yet.</p>') + '</section>';
	}

	function bindSidebar() {
		on('[data-tab-go]', 'click', function () { S.tab = 'backups'; route(); });
	}

	function route() {
		if (S.tab === 'backups') { return viewBackups(); }
		if (S.tab === 'settings') { return viewSettings(); }
		return viewHome();
	}

	function crumbs(list, current) {
		return '<ol class="pb-crumbs">' + list.map(function (s, i) {
			var cls = i < current ? 'is-done' : (i === current ? 'is-current' : '');
			return '<li class="' + cls + '"><span class="pb-crumbs__n">' + (i < current ? icon('check') : (i + 1)) + '</span>' + esc(s) + '</li>';
		}).join('') + '</ol>';
	}
	function back(label) { return '<button type="button" class="pb-back" data-back>' + icon('left') + esc(label || 'Back') + '</button>'; }
	function notice(type, title, body, extra) {
		var ic = { err: 'alert', warn: 'alert', info: 'info', ok: 'check' }[type];
		return '<div class="pb-notice pb-notice--' + type + '" role="' + (type === 'err' ? 'alert' : 'status') + '">' + icon(ic) + '<div>' + (title ? '<strong>' + title + '</strong>' : '') + (body ? '<p>' + body + '</p>' : '') + (extra || '') + '</div></div>';
	}
	function cmd(text) {
		return '<div class="pb-cmd"><code>' + esc(text) + '</code><button type="button" class="pb-icon-btn" data-copy="' + esc(text) + '" data-what="Command" title="Copy command">' + icon('copy') + '</button></div>';
	}

	/* ================= home ================= */
	function viewHome() {
		if (C.multisite) {
			return render(notice('warn', 'Multisite is not supported yet.', 'Monarch currently migrates single WordPress sites.'));
		}
		render(
			'<section class="pb-panel">' +
				'<div class="pb-panel__head"><h2>Start a migration</h2></div>' +
				'<div class="pb-option"><span class="pb-option__icon">' + icon('upload') + '</span><div class="pb-option__text"><strong>Export this site</strong><span>Package the database and files into a .pbk archive, then send it with croc or download it.</span></div><button type="button" class="pb-btn pb-btn--primary" data-go="export">Export</button></div>' +
				'<div class="pb-option"><span class="pb-option__icon">' + icon('download') + '</span><div class="pb-option__text"><strong>Import a site</strong><span>Receive a package with a croc code phrase, upload a .pbk file, or restore a saved backup. Replaces this site.</span></div><button type="button" class="pb-btn" data-go="import">Import</button></div>' +
			'</section>' +
			'<section class="pb-panel"><div class="pb-panel__head"><h3>How it works</h3></div><div class="pb-panel__body"><ol class="pb-steps-text">' +
				'<li>The database is exported page by page and imported one statement at a time. Every step saves its position, so large databases never hit PHP time or memory limits.</li>' +
				'<li>Data is imported into temporary tables. Your live site keeps running until the final step swaps all tables in at once.</li>' +
				'<li>URLs and server paths are replaced safely, including inside serialized and JSON data.</li>' +
				'<li>croc transfers the package end-to-end encrypted, directly between the two servers, using a one-time code phrase.</li>' +
			'</ol></div></section>',
			{ sidebar: true }
		);
		bindSidebar();
		$$('[data-go]').forEach(function (b) {
			b.addEventListener('click', function () { if (b.dataset.go === 'export') { viewExportOptions(); } else { viewImportSource(); } });
		});
	}

	/* ================= export ================= */
	function switchRow(key, label, hint, checked, child) {
		return '<label class="pb-row' + (child ? ' is-child' : '') + '" data-row="' + key + '"><span class="pb-row__text"><strong>' + esc(label) + '</strong>' + (hint ? '<small>' + esc(hint) + '</small>' : '') + '</span>' +
			'<span class="pb-switch"><input type="checkbox" data-opt="' + key + '"' + (checked ? ' checked' : '') + '><span></span></span></label>';
	}

	function viewExportOptions() {
		var o = S.exportOpts;
		render(
			back() + crumbs(['Choose content', 'Create package', 'Transfer'], 0) +
			'<section class="pb-panel">' +
				'<div class="pb-panel__head"><h2>What should be included?</h2></div>' +
				'<div class="pb-section-label">Database</div>' +
				switchRow('database', 'Database', 'All tables with the "' + ((C.env || []).filter(function (r) { return r[0] === 'Table prefix'; })[0] || ['', ''])[1] + '" prefix', o.database) +
				switchRow('no_spam', 'Leave out spam and trashed comments', '', o.no_spam, true) +
				switchRow('no_revisions', 'Leave out post revisions', 'Smaller package; old revisions are not migrated', o.no_revisions, true) +
				switchRow('no_transients', 'Leave out transients', 'Temporary cache data — recommended', o.no_transients, true) +
				'<div class="pb-section-label">Files in wp-content</div>' +
				switchRow('uploads', 'Uploads', 'Media library', o.uploads) +
				switchRow('plugins', 'Plugins', '', o.plugins) +
				switchRow('themes', 'Themes', '', o.themes) +
				switchRow('muplugins', 'Must-use plugins', '', o.muplugins) +
				switchRow('other', 'Other directories in wp-content', 'Languages, fonts and custom folders. Cache and other backup plugins’ folders are always skipped.', o.other) +
				'<div class="pb-panel__foot"><span class="pb-muted pb-grow">Free disk space: ' + esc(C.diskFree || 'unknown') + '</span><button type="button" class="pb-btn pb-btn--primary pb-btn--lg" data-start>Create package</button></div>' +
			'</section>'
		);
		on('[data-back]', 'click', viewHome);
		function syncChildren() {
			['no_spam', 'no_revisions', 'no_transients'].forEach(function (k) { $('[data-row="' + k + '"]').classList.toggle('is-disabled', !S.exportOpts.database); });
		}
		syncChildren();
		$$('[data-opt]').forEach(function (cb) {
			cb.addEventListener('change', function () {
				S.exportOpts[cb.dataset.opt] = cb.checked;
				try { localStorage.setItem('pb_export_opts', JSON.stringify(S.exportOpts)); } catch (e) { /* ignore */ }
				syncChildren();
			});
		});
		on('[data-start]', 'click', function () {
			var x = S.exportOpts;
			if (!x.database && !x.uploads && !x.plugins && !x.themes && !x.muplugins && !x.other) {
				return toast('Select at least one thing to export.', 'err');
			}
			startExport();
		});
	}

	/* ---------- shared job view ---------- */
	var EXPORT_METRICS = [['elapsed', 'Elapsed'], ['eta', 'Remaining'], ['tables', 'Tables'], ['rows', 'Rows'], ['files', 'Files'], ['package', 'Package size']];
	var IMPORT_METRICS = [['elapsed', 'Elapsed'], ['eta', 'Remaining'], ['statements', 'SQL statements'], ['tables', 'Tables'], ['updated', 'Rows updated'], ['files', 'Files']];
	var IMPORT_STAGES = [
		['db', 'Database', 'Into temporary tables'],
		['replace', 'Replace URLs', 'Serialization-safe'],
		['files', 'Files', 'Restore wp-content'],
		['swap', 'Activate', 'Atomic table swap']
	];

	function jobView(kind, title) {
		var metrics = kind === 'import' ? IMPORT_METRICS : EXPORT_METRICS;
		return crumbs(kind === 'import' ? ['Get package', 'Review', 'Import'] : ['Choose content', 'Create package', 'Transfer'], kind === 'import' ? 2 : 1) +
			'<section class="pb-panel" data-job>' +
				'<div class="pb-panel__body">' +
					'<div class="pb-job__title"><h2 data-j-title>' + esc(title) + '</h2><span class="pb-status is-running" data-j-status>Running</span><span class="pb-job__pct" data-j-pct>0%</span></div>' +
					'<p class="pb-sublabel" data-j-label>Preparing…</p>' +
					'<div class="pb-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" data-j-barwrap><span data-j-bar></span></div>' +
				'</div>' +
				(kind === 'import' ? '<ol class="pb-stages">' + IMPORT_STAGES.map(function (s) { return '<li data-st="' + s[0] + '"><span class="pb-stage-dot"></span><div><strong>' + s[1] + '</strong><small>' + s[2] + '</small></div></li>'; }).join('') + '</ol>' : '') +
				'<dl class="pb-metrics">' + metrics.map(function (m) { return '<div class="pb-metric"><dt>' + m[1] + '</dt><dd data-m="' + m[0] + '">—</dd></div>'; }).join('') + '</dl>' +
				'<div data-j-error></div>' +
				'<div class="pb-panel__foot"><span class="pb-muted pb-grow">Keep this tab open while the job runs. If it closes, the job resumes where it stopped.</span><button type="button" class="pb-btn pb-btn--plain" data-cancel>Cancel</button></div>' +
			'</section>' + Log.html('Activity log');
	}

	var ETA = { pts: [] };
	function updateJob(p) {
		var pct = Math.max(0, Math.min(100, p.percent || 0));
		var bar = $('[data-j-bar]');
		if (!bar) { return; }
		bar.style.width = pct + '%';
		$('[data-j-barwrap]').setAttribute('aria-valuenow', Math.round(pct));
		$('[data-j-pct]').textContent = (pct < 10 && pct > 0 ? pct.toFixed(1) : Math.round(pct)) + '%';
		if (p.label) { $('[data-j-label]').textContent = p.label; }
		var elapsed = (Date.now() - S.started) / 1000;
		ETA.pts.push([elapsed, pct]);
		if (ETA.pts.length > 40) { ETA.pts.shift(); }
		var eta = null;
		if (pct > 2 && pct < 100 && ETA.pts.length > 3) {
			var a = ETA.pts[0], b = ETA.pts[ETA.pts.length - 1];
			var rate = (b[1] - a[1]) / Math.max(0.001, b[0] - a[0]);
			if (rate > 0) { eta = (100 - pct) / rate; }
		}
		setMetric('elapsed', fmtDur(elapsed));
		setMetric('eta', pct >= 100 ? '0s' : (eta == null ? 'Estimating…' : '~' + fmtDur(eta)));
		Object.keys(p.stats || {}).forEach(function (k) { setMetric(k, p.stats[k]); });
		Log.push(p.log);
		if (p.stages) { updateStages(p); }
	}
	function setMetric(k, v) { var el = $('[data-m="' + k + '"]'); if (el) { el.textContent = v; } }

	function setStatus(state, text) {
		var el = $('[data-j-status]');
		if (el) { el.className = 'pb-status is-' + state; el.textContent = text; }
		var wrap = $('[data-j-barwrap]');
		if (wrap) { wrap.classList.toggle('is-ok', state === 'ok'); wrap.classList.toggle('is-err', state === 'err'); }
		Log.live(state === 'running' ? 'on' : (state === 'ok' ? 'done' : 'paused'));
	}

	function updateStages(p) {
		var order = ['db', 'replace', 'files', 'swap', 'done'];
		var cur = order.indexOf(p.stage);
		$$('[data-st]').forEach(function (li) {
			var i = order.indexOf(li.dataset.st);
			li.className = i < cur ? 'is-done' : (i === cur ? (p.error ? 'is-error' : 'is-current') : '');
			li.querySelector('.pb-stage-dot').innerHTML = i < cur ? icon('check') : '';
		});
		var st = IMPORT_STAGES.filter(function (s) { return s[0] === p.stage; })[0];
		if (st && $('[data-j-title]')) { $('[data-j-title]').textContent = st[1] === 'Database' ? 'Importing database' : (st[1] === 'Files' ? 'Restoring files' : (st[1] === 'Activate' ? 'Activating site' : 'Replacing URLs')); }
	}

	function bindJob() {
		Log.bind();
		Log.live('on');
		on('[data-cancel]', 'click', function () {
			if (!confirm('Cancel this job? Nothing on this site will be changed.')) { return; }
			S.cancelFlag = true;
			S.running = false;
			var id = S.job;
			(id ? api('cancel', { id: id }) : Promise.resolve()).then(function () { toast('Job cancelled'); S.job = null; route(); });
		});
	}

	function beginJob(kind, title) {
		S.running = true; S.cancelFlag = false; S.started = Date.now(); ETA.pts = [];
		Log.reset();
		render(jobView(kind, title));
		bindJob();
	}

	function runJob(p) {
		updateJob(p);
		if (S.cancelFlag) { return; }
		if (p.error) { S.running = false; return showJobError(p); }
		if (p.done) { S.running = false; return p.stages ? importDone(p) : exportDone(p); }
		return sleep(p.busy ? 2500 : 80).then(function () {
			if (S.cancelFlag) { return; }
			return api('step', { id: S.job, since: Math.floor(Log.seq) }).then(runJob);
		});
	}

	function failGeneric(e) {
		S.running = false;
		setStatus('err', 'Stopped');
		Log.local(e.message, 'err');
		var box = $('[data-j-error]');
		var html = '<div class="pb-panel__body" style="padding-top:0">' + notice('err', 'The job stopped', esc(e.message),
			'<div class="pb-btn-row">' + (e.data && e.data.login ? '<a class="pb-btn pb-btn--primary" href="' + esc(e.data.login) + '">Log in again</a>' : '') +
			(S.job ? '<button type="button" class="pb-btn pb-btn--primary" data-retry>Retry</button>' : '') + '</div>') + '</div>';
		if (box) { box.innerHTML = html; } else { render(html); }
		on('[data-retry]', 'click', function () {
			if (!box) { beginJob(S.job && /import/.test(String(e.data && e.data.type)) ? 'import' : 'export', 'Resuming'); } else { box.innerHTML = ''; }
			S.running = true;
			setStatus('running', 'Running');
			api('step', { id: S.job, since: Math.floor(Log.seq) }).then(runJob).catch(failGeneric);
		});
	}

	function startExport() {
		beginJob('export', 'Creating package');
		api('export_start', { opts: S.exportOpts }).then(function (p) {
			S.job = p.id;
			return runJob(p);
		}).catch(failGeneric);
	}

	function exportDone(p) {
		S.job = null;
		setStatus('ok', 'Completed');
		refreshBackups();
		var elapsed = fmtDur((Date.now() - S.started) / 1000);
		var jobEl = $('[data-job]');
		jobEl.outerHTML =
			'<section class="pb-panel" data-job>' +
				'<div class="pb-done-head"><span class="pb-done-head__icon">' + icon('check') + '</span><div class="pb-grow" style="flex:1"><h2>Package ready</h2><p class="pb-muted"><code>' + esc(p.file) + '</code> · ' + esc(p.size) + ' · created in ' + esc(elapsed) + '</p></div></div>' +
				'<div class="pb-option"><span class="pb-option__icon">' + icon('send') + '</span><div class="pb-option__text"><strong>Send with croc</strong><span>Encrypted server-to-server transfer. You get a code phrase to enter on the destination site.</span></div><button type="button" class="pb-btn pb-btn--primary" data-send>Send with croc</button></div>' +
				'<div class="pb-option"><span class="pb-option__icon">' + icon('download') + '</span><div class="pb-option__text"><strong>Download</strong><span>Save the .pbk to your computer, then upload it on the destination site.</span></div><a class="pb-btn" href="' + esc(C.download + encodeURIComponent(p.file)) + '">Download .pbk</a></div>' +
			'</section>';
		$('.pb-crumbs').outerHTML = crumbs(['Choose content', 'Create package', 'Transfer'], 2);
		on('[data-send]', 'click', function () { viewCrocSend(p.file); });
		$$('[data-tab]').forEach(function (b) { b.disabled = false; });
	}

	/* ================= croc ================= */
	function crocInfo() { return api('croc_info', {}); }

	function crocBlocked(info, mode, file) {
		var code = 'your-code-phrase', f = file || 'backup.pbk';
		var cmds = mode === 'send'
			? '<p class="pb-cmd-label">Linux / macOS</p>' + cmd('CROC_SECRET="' + code + '" croc send ' + f) + '<p class="pb-cmd-label">Windows</p>' + cmd('croc send --code ' + code + ' ' + f)
			: '<p class="pb-cmd-label">Linux / macOS</p>' + cmd('CROC_SECRET="' + code + '" croc') + '<p class="pb-cmd-label">Windows</p>' + cmd('croc ' + code);
		if (!info.exec) {
			return notice('warn', 'This host does not allow running programs (proc_open is disabled).',
				'croc can’t run on this server. You can still use croc on your own computer: ' + (mode === 'send' ? 'download the .pbk and send it' : 'receive the .pbk, then upload it here') + ' with these commands (get croc from <a href="https://github.com/schollz/croc" target="_blank" rel="noopener">github.com/schollz/croc</a>):', cmds);
		}
		if (!info.installed) {
			return '<div class="pb-option" style="padding:0"><span class="pb-option__icon">' + icon('key') + '</span><div class="pb-option__text"><strong>Install croc (one time)</strong><span>Downloads the official ' + (info.asset ? '<code>' + esc(info.asset) + '</code>' : 'croc build') + ' from GitHub and verifies its SHA-256 checksum.</span></div><button type="button" class="pb-btn pb-btn--primary" data-install' + (info.asset ? '' : ' disabled') + '>Install croc</button></div>';
		}
		return '';
	}

	function bindInstall(after) {
		var b = on('[data-install]', 'click', function () {
			b.disabled = true; b.innerHTML = '<span class="pb-spin"></span> Installing…';
			api('croc_install', {}, { retries: 0 }).then(function (info) { toast('croc ' + (info.version || '') + ' installed', 'ok'); after(); })
				.catch(function (e) { b.disabled = false; b.textContent = 'Install croc'; toast(e.message, 'err'); });
		});
	}

	function viewCrocSend(file) {
		S.tab = 'migrate';
		render(back() + '<section class="pb-panel"><div class="pb-panel__head"><h2>Send with croc</h2></div><div class="pb-panel__body pb-muted"><span class="pb-spin"></span> Checking this server…</div></section>');
		on('[data-back]', 'click', route);
		crocInfo().then(function (info) {
			var blocked = crocBlocked(info, 'send', file);
			render(
				back() + crumbs(['Choose content', 'Create package', 'Transfer'], 2) +
				'<section class="pb-panel"><div class="pb-panel__head"><h2 class="pb-grow">Send with croc</h2><code>' + esc(file) + '</code></div>' +
				'<div class="pb-panel__body">' + (blocked || (
					'<p class="pb-muted" style="margin-bottom:10px">A strong code phrase is generated automatically. You can type your own instead — letters, numbers and dashes, at least 6 characters.</p>' +
					'<div class="pb-inline-form"><input type="text" class="pb-input pb-input--code" data-code placeholder="Auto-generate" maxlength="64" autocomplete="off" spellcheck="false" aria-label="Custom code phrase (optional)"><button type="button" class="pb-btn pb-btn--primary pb-btn--lg" data-go>' + icon('send') + 'Start sending</button></div>'
				)) + '</div></section>'
			);
			on('[data-back]', 'click', route);
			$$('[data-copy]').forEach(function (b) { b.addEventListener('click', function () { copy(b.dataset.copy, 'Command'); }); });
			bindInstall(function () { viewCrocSend(file); });
			var go = on('[data-go]', 'click', function () {
				var code = $('[data-code]').value.trim();
				if (code && !/^[A-Za-z0-9-]{6,64}$/.test(code)) { return toast('Use letters, numbers and dashes only (6–64 characters).', 'err'); }
				go.disabled = true;
				api('croc_send', { name: file, code: code }, { retries: 0 }).then(function (s) { crocSending(file, s); }).catch(function (e) { go.disabled = false; toast(e.message, 'err'); });
			});
		}).catch(function (e) { toast(e.message, 'err'); });
	}

	function crocTransferPanel(title, sub, body) {
		return '<section class="pb-panel" data-job><div class="pb-panel__body">' +
			'<div class="pb-job__title"><h2>' + esc(title) + '</h2><span class="pb-status is-running" data-j-status>Waiting</span><span class="pb-job__pct" data-j-pct>0%</span></div>' +
			'<p class="pb-sublabel" data-j-label>' + esc(sub) + '</p>' +
			'<div class="pb-bar" data-j-barwrap><span data-j-bar></span></div></div>' +
			(body || '') +
			'<dl class="pb-metrics"><div class="pb-metric"><dt>Elapsed</dt><dd data-m="elapsed">0s</dd></div><div class="pb-metric"><dt>State</dt><dd data-m="state">Connecting</dd></div><div class="pb-metric"><dt>Transfer</dt><dd data-m="detail">—</dd></div></dl>' +
			'<div class="pb-panel__foot"><span class="pb-muted pb-grow">croc relays traffic end-to-end encrypted; the relay cannot read it.</span><button type="button" class="pb-btn pb-btn--plain" data-stop>Stop</button></div></section>' +
			Log.html('croc output');
	}

	function crocSending(file, s) {
		S.running = true; S.started = Date.now();
		Log.reset();
		render(
			crumbs(['Choose content', 'Create package', 'Transfer'], 2) +
			crocTransferPanel('Sending ' + file, 'Waiting for the destination site to connect…',
				'<div class="pb-panel__body" style="border-top:1px solid var(--pb-border-soft)">' +
					'<p style="margin-bottom:8px"><strong>On the destination site:</strong> Monarch → Import → Receive with croc, then enter this code phrase:</p>' +
					'<div class="pb-code"><span>' + esc(s.code) + '</span><button type="button" class="pb-btn" data-copy="' + esc(s.code) + '" data-what="Code phrase">' + icon('copy') + 'Copy</button></div>' +
					'<details style="margin-top:10px"><summary class="pb-muted" style="cursor:pointer">Receive on a computer instead</summary><p class="pb-cmd-label">Linux / macOS</p>' + cmd(s.manual.receive_unix) + '<p class="pb-cmd-label">Windows</p>' + cmd(s.manual.receive_win) + '</details>' +
				'</div>')
		);
		Log.bind(); Log.live('on');
		on('[data-stop]', 'click', function () {
			if (S.running && !confirm('Stop sending? The receiver will not get the package.')) { return; }
			S.running = false;
			api('croc_cancel', { id: s.id }).then(function () { toast('Transfer stopped'); S.tab = 'backups'; route(); });
		});
		pollCroc(s.id, function () {
			toast('Package sent', 'ok');
			$('[data-stop]').textContent = 'Close';
		});
	}

	function pollCroc(id, onDone) {
		function tick() {
			if (!S.running) { return; }
			api('croc_poll', { id: id }).then(function (r) {
				if (!$('[data-j-bar]')) { return; }
				$('[data-j-bar]').style.width = r.percent + '%';
				$('[data-j-pct]').textContent = r.percent + '%';
				setMetric('elapsed', fmtDur(r.elapsed));
				var stateText = { waiting: 'Waiting for peer', transferring: 'Transferring', done: 'Complete', error: 'Failed' }[r.state];
				setMetric('state', stateText);
				var m = (r.message || '').match(/\(([^)]+)\)/);
				if (m) { setMetric('detail', m[1]); }
				if (r.state === 'transferring') { setStatus('running', 'Transferring'); $('[data-j-label]').textContent = 'Transferring…'; }
				Log.replace(r.lines);
				if (r.state === 'done') {
					S.running = false;
					setStatus('ok', 'Completed');
					$('[data-j-label]').textContent = 'Transfer complete';
					return onDone(r);
				}
				if (r.state === 'error') {
					S.running = false;
					setStatus('err', 'Failed');
					$('[data-j-label]').textContent = r.message || 'croc stopped';
					return;
				}
				setTimeout(tick, 1500);
			}).catch(function (e) { toast(e.message, 'err'); setTimeout(tick, 4000); });
		}
		tick();
	}

	/* ================= import ================= */
	function viewImportSource(preset) {
		S.tab = 'migrate';
		var src = preset || 'croc';
		var options = S.backups.map(function (b) { return '<option value="' + esc(b.name) + '">' + esc(b.name) + ' — ' + esc(b.human) + ' — ' + esc(b.date) + '</option>'; }).join('');
		render(
			back() + crumbs(['Get package', 'Review', 'Import'], 0) +
			'<section class="pb-panel">' +
				'<div class="pb-tabs2" role="tablist">' +
					'<button type="button" role="tab" data-src="croc" class="' + (src === 'croc' ? 'is-active' : '') + '">' + icon('key') + 'Receive with croc</button>' +
					'<button type="button" role="tab" data-src="upload" class="' + (src === 'upload' ? 'is-active' : '') + '">' + icon('upload') + 'Upload file</button>' +
					'<button type="button" role="tab" data-src="existing" class="' + (src === 'existing' ? 'is-active' : '') + '">' + icon('archive') + 'Saved backups <span class="pb-count" style="border:1px solid var(--pb-border);border-radius:9px;padding:0 6px;font-size:11px">' + S.backups.length + '</span></button>' +
				'</div>' +
				'<div class="pb-panel__body">' +
				(src === 'croc' ? '<p style="margin-bottom:10px">Enter the code phrase shown on the source site after you chose <em>Send with croc</em>.</p><div data-croc-setup></div><div class="pb-inline-form" data-croc-form><input type="text" class="pb-input pb-input--code" data-code placeholder="e.g. 4821-amber-tiger-ocean-maple" maxlength="64" autocomplete="off" spellcheck="false" aria-label="Code phrase"><button type="button" class="pb-btn pb-btn--primary pb-btn--lg" data-receive>Receive</button></div>' : '') +
				(src === 'upload' ? '<label class="pb-drop" data-drop tabindex="0"><input type="file" accept=".pbk" hidden data-file>' + icon('upload') + '<strong>Drop a .pbk file here, or click to choose</strong><span class="pb-muted">Any size — uploaded in ' + fmtBytes(C.chunk) + ' pieces and resumable if the connection drops.</span></label>' : '') +
				(src === 'existing' ? (S.backups.length ? '<div class="pb-inline-form"><select class="pb-input" data-existing aria-label="Backup">' + options + '</select><button type="button" class="pb-btn pb-btn--primary pb-btn--lg" data-use>Continue</button></div>' : '<p class="pb-muted">No backups on this server yet.</p>') : '') +
				'</div>' +
			'</section>'
		);
		on('[data-back]', 'click', viewHome);
		$$('[data-src]').forEach(function (b) { b.addEventListener('click', function () { viewImportSource(b.dataset.src); }); });
		if (src === 'croc') {
			crocInfo().then(function (info) {
				var box = $('[data-croc-setup]');
				if (!box) { return; }
				var blocked = crocBlocked(info, 'receive');
				box.innerHTML = blocked ? '<div style="margin-bottom:12px">' + blocked + '</div>' : '';
				if (blocked) { $('[data-croc-form]').classList.add('pb-hidden'); }
				$$('[data-copy]', box).forEach(function (b) { b.addEventListener('click', function () { copy(b.dataset.copy, 'Command'); }); });
				bindInstall(function () { viewImportSource('croc'); });
			});
			on('[data-receive]', 'click', function () {
				var code = $('[data-code]').value.trim();
				if (!/^[A-Za-z0-9-]{6,64}$/.test(code)) { return toast('Enter the code phrase shown on the source site.', 'err'); }
				api('croc_receive', { code: code }, { retries: 0 }).then(function (s) { crocReceiving(s.id); }).catch(function (e) { toast(e.message, 'err'); });
			});
			on('[data-code]', 'keydown', function (e) { if (e.key === 'Enter') { $('[data-receive]').click(); } });
		}
		if (src === 'upload') { bindUpload(); }
		if (src === 'existing' && S.backups.length) { on('[data-use]', 'click', function () { viewConfirm($('[data-existing]').value); }); }
	}

	function crocReceiving(id) {
		S.running = true; S.started = Date.now();
		Log.reset();
		render(crumbs(['Get package', 'Review', 'Import'], 0) + crocTransferPanel('Receiving package', 'Connecting to the sender…'));
		Log.bind(); Log.live('on');
		on('[data-stop]', 'click', function () {
			S.running = false;
			api('croc_cancel', { id: id }).then(function () { toast('Cancelled'); viewImportSource('croc'); });
		});
		pollCroc(id, function (r) { toast('Package received', 'ok'); refreshBackups(); setTimeout(function () { viewConfirm(r.file); }, 700); });
	}

	function bindUpload() {
		var drop = $('[data-drop]'), input = $('[data-file]');
		drop.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); input.click(); } });
		['dragenter', 'dragover'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); }); });
		['dragleave', 'drop'].forEach(function (ev) { drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); }); });
		drop.addEventListener('drop', function (e) { if (e.dataTransfer.files[0]) { upload(e.dataTransfer.files[0]); } });
		input.addEventListener('change', function () { if (input.files[0]) { upload(input.files[0]); } });
	}

	function safeName(n) {
		n = n.replace(/[^A-Za-z0-9._-]/g, '-').replace(/^[^A-Za-z0-9]+/, '');
		if (!/\.pbk$/i.test(n)) { n += '.pbk'; }
		return n.replace(/\.PBK$/, '.pbk').slice(-180);
	}

	function upload(file) {
		if (!/\.pbk$/i.test(file.name)) { return toast('Choose a .pbk file created by Monarch.', 'err'); }
		var name = safeName(file.name), size = file.size, stop = false;
		S.running = true; S.started = Date.now();
		Log.reset();
		render(
			crumbs(['Get package', 'Review', 'Import'], 0) +
			'<section class="pb-panel" data-job><div class="pb-panel__body">' +
				'<div class="pb-job__title"><h2>Uploading package</h2><span class="pb-status is-running" data-j-status>Uploading</span><span class="pb-job__pct" data-j-pct>0%</span></div>' +
				'<p class="pb-sublabel"><code>' + esc(file.name) + '</code></p>' +
				'<div class="pb-bar" data-j-barwrap><span data-j-bar></span></div></div>' +
				'<dl class="pb-metrics"><div class="pb-metric"><dt>Uploaded</dt><dd data-m="up">—</dd></div><div class="pb-metric"><dt>Speed</dt><dd data-m="speed">—</dd></div><div class="pb-metric"><dt>Elapsed</dt><dd data-m="elapsed">0s</dd></div><div class="pb-metric"><dt>Remaining</dt><dd data-m="eta">—</dd></div></dl>' +
				'<div class="pb-panel__foot"><span class="pb-muted pb-grow">If the connection drops, choose the same file again — the upload resumes.</span><button type="button" class="pb-btn pb-btn--plain" data-stop>Cancel</button></div>' +
			'</section>' + Log.html('Activity log')
		);
		Log.bind(); Log.live('on');
		Log.local('Uploading ' + file.name + ' (' + fmtBytes(size) + ') in ' + fmtBytes(C.chunk) + ' chunks', 'step');
		on('[data-stop]', 'click', function () { stop = true; S.running = false; viewImportSource('upload'); });
		var startOffset = 0, chunkNo = 0;
		api('upload_status', { name: name }).then(function (st) {
			var offset = st.received > size ? 0 : st.received;
			startOffset = offset;
			if (offset > 0) { Log.local('Resuming: the server already has ' + fmtBytes(offset), 'ok'); }
			function next() {
				if (stop) { return; }
				var secs = (Date.now() - S.started) / 1000, speed = (offset - startOffset) / Math.max(0.5, secs);
				if ($('[data-j-bar]')) {
					$('[data-j-bar]').style.width = (offset / size * 100) + '%';
					$('[data-j-pct]').textContent = Math.floor(offset / size * 100) + '%';
					setMetric('up', fmtBytes(offset) + ' / ' + fmtBytes(size));
					setMetric('speed', secs > 1 ? fmtBytes(speed) + '/s' : '—');
					setMetric('elapsed', fmtDur(secs));
					setMetric('eta', speed > 0 && secs > 2 ? '~' + fmtDur((size - offset) / speed) : 'Estimating…');
				}
				var t0 = Date.now(), blob = file.slice(offset, Math.min(size, offset + C.chunk));
				chunkNo++;
				return api('upload_chunk', { name: name, offset: offset, total: size, chunk: blob }, { retries: 5 }).then(function (r) {
					if (r.resync) { Log.local('Server is at ' + fmtBytes(r.received) + ' — re-syncing', 'warn'); }
					else { Log.local('Chunk ' + chunkNo + ': ' + fmtBytes(r.received - offset) + ' in ' + ((Date.now() - t0) / 1000).toFixed(1) + 's · ' + fmtBytes(r.received) + ' of ' + fmtBytes(size)); }
					offset = r.received;
					if (r.complete) {
						S.running = false;
						Log.local('Upload complete; archive verified', 'ok');
						toast('Upload complete', 'ok');
						refreshBackups();
						return viewConfirm(r.name);
					}
					return next();
				});
			}
			return next();
		}).catch(function (e) { S.running = false; setStatus('err', 'Failed'); Log.local(e.message, 'err'); toast(e.message, 'err'); });
	}

	function viewConfirm(name) {
		S.tab = 'migrate';
		render(crumbs(['Get package', 'Review', 'Import'], 1) + '<section class="pb-panel"><div class="pb-panel__body pb-muted"><span class="pb-spin"></span> Checking package…</div></section>');
		api('import_inspect', { name: name }).then(function (m) {
			if (m.multisite) { throw new Error('This package comes from a multisite network, which is not supported yet.'); }
			var facts = [['Package', m.size], ['Tables', m.database ? m.tables : 'none'], ['Files', Number(m.files).toLocaleString()], ['WordPress', m.wp_version], ['PHP', m.php], ['Database', m.mysql], ['Created', (m.created || '').replace('T', ' ').slice(0, 16)]];
			render(
				back() + crumbs(['Get package', 'Review', 'Import'], 1) +
				'<section class="pb-panel">' +
					'<div class="pb-panel__head"><h2 class="pb-grow">Review the migration</h2><code>' + esc(name) + '</code></div>' +
					'<div class="pb-route"><div><small>From</small><strong>' + esc(m.source) + '</strong></div>' + icon('arrow') + '<div><small>To (this site)</small><strong>' + esc(m.target) + '</strong></div></div>' +
					'<dl class="pb-metrics">' + facts.map(function (f) { return '<div class="pb-metric"><dt>' + f[0] + '</dt><dd>' + esc(f[1]) + '</dd></div>'; }).join('') + '</dl>' +
					'<div class="pb-panel__body" style="border-top:1px solid var(--pb-border-soft)">' +
						'<details><summary style="cursor:pointer">Additional find &amp; replace <span class="pb-muted">(optional)</span></summary>' +
						'<p class="pb-muted" style="margin:8px 0">The site URL and server paths are replaced automatically. Add extra pairs one per line as <code>old =&gt; new</code>.</p>' +
						'<textarea class="pb-input" rows="3" data-extra placeholder="old@example.com => new@example.com"></textarea></details>' +
					'</div>' +
					'<div class="pb-panel__body" style="border-top:1px solid var(--pb-border-soft)">' +
						notice('warn', 'This replaces this site’s database and wp-content files.', 'Your current site keeps working until the very last step. Afterwards you log in with the <strong>source site’s</strong> username and password.') +
						'<label class="pb-check" style="margin-top:12px"><input type="checkbox" data-agree> <span>I understand that this site will be replaced.</span></label>' +
					'</div>' +
					'<div class="pb-panel__foot"><span class="pb-grow"></span><button type="button" class="pb-btn pb-btn--plain" data-back2>Cancel</button><button type="button" class="pb-btn pb-btn--danger pb-btn--lg" data-import disabled>Start import</button></div>' +
				'</section>'
			);
			on('[data-back]', 'click', function () { viewImportSource('existing'); });
			on('[data-back2]', 'click', function () { viewImportSource('existing'); });
			on('[data-agree]', 'change', function (e) { $('[data-import]').disabled = !e.target.checked; });
			on('[data-import]', 'click', function () {
				var extra = $('[data-extra]').value.split('\n').map(function (l) {
					var i = l.indexOf('=>'); return i > 0 ? [l.slice(0, i).trim(), l.slice(i + 2).trim()] : null;
				}).filter(Boolean);
				startImport(name, extra);
			});
		}).catch(function (e) {
			render(back() + notice('err', 'This package can’t be imported', esc(e.message)));
			on('[data-back]', 'click', function () { viewImportSource('existing'); });
		});
	}

	function startImport(name, extra) {
		beginJob('import', 'Importing database');
		api('import_start', { name: name, extra: extra }).then(function (p) {
			S.job = p.id;
			return runJob(p);
		}).catch(failGeneric);
	}

	function showJobError(p) {
		setStatus('err', 'Paused');
		var e = p.error || {};
		var isDb = e.stage === 'db';
		$('[data-j-error]').innerHTML = '<div class="pb-panel__body" style="border-top:1px solid var(--pb-border-soft)">' + notice('err',
			isDb ? 'Statement #' + esc(e.statement) + ' could not be imported' : 'The import paused',
			esc(e.message),
			(e.sql ? '<details style="margin-top:6px"><summary style="cursor:pointer">Show SQL</summary><pre>' + esc(e.sql) + '</pre></details>' : '') +
			'<p class="pb-muted" style="margin-top:6px">Your live site has not been changed' + (e.stage === 'files' ? ' (apart from files already restored)' : '') + '.</p>' +
			'<div class="pb-btn-row"><button type="button" class="pb-btn pb-btn--primary" data-res="retry">Retry</button>' +
			(isDb ? '<button type="button" class="pb-btn" data-res="skip">Skip statement and continue</button>' : '') +
			'<button type="button" class="pb-btn pb-btn--link" data-res="cancel">Cancel and roll back</button></div>') + '</div>';
		$$('[data-res]').forEach(function (b) {
			b.addEventListener('click', function () {
				var d = b.dataset.res;
				if (d === 'cancel') {
					return api('cancel', { id: S.job }).then(function () { S.job = null; toast('Import cancelled — temporary tables removed'); route(); });
				}
				$('[data-j-error]').innerHTML = '';
				S.running = true;
				setStatus('running', 'Running');
				Log.local(d === 'skip' ? 'You chose: skip this statement' : 'You chose: retry', 'step');
				api('import_resolve', { id: S.job, decision: d, since: Math.floor(Log.seq) }).then(function (r) { Log.push(r.log); return api('step', { id: S.job, since: Math.floor(Log.seq) }); }).then(runJob).catch(failGeneric);
			});
		});
	}

	function importDone(p) {
		S.job = null;
		setStatus('ok', 'Completed');
		updateStages(p);
		var jobEl = $('[data-job]');
		var metrics = jobEl.querySelector('.pb-metrics').outerHTML;
		jobEl.outerHTML =
			'<section class="pb-panel" data-job>' +
				'<div class="pb-done-head"><span class="pb-done-head__icon">' + icon('check') + '</span><div style="flex:1"><h2>Migration complete</h2><p class="pb-muted">' + esc(p.source) + ' is now running at <strong>' + esc(C.home) + '</strong>.</p></div></div>' +
				metrics +
				'<div class="pb-panel__body" style="border-top:1px solid var(--pb-border-soft)">' + notice('info', 'Log in with the source site’s username and password.', 'Users were imported with the database. After logging in, open Settings → Permalinks and click Save once to refresh your .htaccess rules.') + '</div>' +
				'<div class="pb-panel__foot"><span class="pb-grow"></span><a class="pb-btn pb-btn--primary pb-btn--lg" href="' + esc(p.login) + '">Log in</a></div>' +
			'</section>';
		$('.pb-crumbs').outerHTML = crumbs(['Get package', 'Review', 'Import'], 3);
	}

	/* ================= backups ================= */
	function refreshBackups() {
		return api('backups', {}).then(function (r) {
			S.backups = r.backups;
			var c = $('[data-bcount]');
			if (c) { c.textContent = S.backups.length; }
			return r.backups;
		}).catch(function () { return S.backups; });
	}

	function viewBackups() {
		var list = S.backups;
		render(
			'<section class="pb-panel">' +
				'<div class="pb-panel__head"><h2 class="pb-grow">Backups on this server</h2><button type="button" class="pb-btn pb-btn--primary" data-new>Create backup</button></div>' +
				(list.length ? '<table class="pb-table"><thead><tr><th>File</th><th>Created</th><th>Size</th><th><span class="screen-reader-text">Actions</span></th></tr></thead><tbody>' + list.map(function (b) {
					return '<tr><td class="pb-name">' + esc(b.name) + '</td><td>' + esc(b.date) + '</td><td>' + esc(b.human) + '</td>' +
						'<td class="pb-actions-cell"><div class="pb-btn-row" style="justify-content:flex-end">' +
							'<a class="pb-btn pb-btn--plain" href="' + esc(C.download + encodeURIComponent(b.name)) + '">' + icon('download') + 'Download</a>' +
							'<button type="button" class="pb-btn pb-btn--plain" data-send="' + esc(b.name) + '">' + icon('send') + 'Send</button>' +
							'<button type="button" class="pb-btn pb-btn--plain" data-restore="' + esc(b.name) + '">' + icon('restore') + 'Restore</button>' +
							'<button type="button" class="pb-icon-btn" data-del="' + esc(b.name) + '" title="Delete" aria-label="Delete ' + esc(b.name) + '">' + icon('trash') + '</button>' +
						'</div></td></tr>';
				}).join('') + '</tbody></table>' : '<div class="pb-empty">' + icon('archive') + '<p>No backups yet. Create one to see it here.</p></div>') +
			'</section>' +
			'<p class="pb-muted">Backups are stored in a protected folder inside wp-content that cannot be opened from the web. The last ' + esc(S.settings.keep_backups || 'N') + ' are kept (change this in Settings).</p>'
		);
		on('[data-new]', 'click', function () { S.tab = 'migrate'; viewExportOptions(); });
		$$('[data-send]').forEach(function (b) { b.addEventListener('click', function () { viewCrocSend(b.dataset.send); }); });
		$$('[data-restore]').forEach(function (b) { b.addEventListener('click', function () { viewConfirm(b.dataset.restore); }); });
		$$('[data-del]').forEach(function (b) {
			b.addEventListener('click', function () {
				if (!confirm('Delete ' + b.dataset.del + '? This cannot be undone.')) { return; }
				api('delete_backup', { name: b.dataset.del }).then(function (r) { S.backups = r.backups; toast('Backup deleted'); viewBackups(); }).catch(function (e) { toast(e.message, 'err'); });
			});
		});
	}

	/* ================= settings ================= */
	function field(label, input, help) {
		return '<div class="pb-field"><label>' + label + '</label><div>' + input + (help ? '<small>' + help + '</small>' : '') + '</div></div>';
	}

	function viewSettings() {
		var s = S.settings;
		render(
			'<section class="pb-panel"><div class="pb-panel__head"><h2>Performance</h2></div>' +
				field('Time per step', '<input type="number" min="3" max="60" class="pb-input" style="max-width:100px" data-s="time_budget" value="' + esc(s.time_budget) + '"> <span class="pb-muted">seconds</span>', 'Each request works this long, saves its position and continues in the next one. Lower it if your host stops requests early.') +
				field('Keep backups', '<input type="number" min="0" max="100" class="pb-input" style="max-width:100px" data-s="keep_backups" value="' + esc(s.keep_backups) + '">', 'Older packages are deleted automatically. 0 keeps all.') +
				field('On uninstall', '<label class="pb-check"><input type="checkbox" data-s="delete_on_remove"' + (Number(s.delete_on_remove) ? ' checked' : '') + '> <span>Delete all backups when the plugin is deleted</span></label>') +
			'</section>' +
			'<section class="pb-panel"><div class="pb-panel__head"><h2 class="pb-grow">croc transfer</h2><span data-croc-status class="pb-muted"><span class="pb-spin"></span></span></div>' +
				'<div data-croc-install></div>' +
				field('croc binary', '<input type="text" class="pb-input" data-s="croc_path" value="' + esc(s.croc_path) + '" placeholder="/usr/local/bin/croc">', 'Optional. Leave empty to use the automatically installed binary.') +
				field('Relay', '<input type="text" class="pb-input" data-s="croc_relay" value="' + esc(s.croc_relay) + '" placeholder="relay.example.com:9009">', 'Optional. Leave empty for the public croc relays. Both sites must use the same relay.') +
				field('Relay password', '<input type="password" class="pb-input" data-s="croc_pass" value="' + esc(s.croc_pass) + '" autocomplete="new-password">') +
				'<div class="pb-panel__foot"><span class="pb-grow"></span><button type="button" class="pb-btn pb-btn--primary" data-save>Save changes</button></div>' +
			'</section>'
		);
		crocInfo().then(function (info) {
			var st = $('[data-croc-status]');
			if (!st) { return; }
			st.innerHTML = !info.exec ? '<span class="pb-status is-err">Can’t run on this host</span>' : (info.installed ? '<span class="pb-status is-ok">Installed ' + esc(info.version) + '</span>' : '<span class="pb-status">Not installed</span>');
			var box = $('[data-croc-install]');
			if (info.exec && !info.installed) { box.innerHTML = '<div class="pb-panel__body" style="border-bottom:1px solid var(--pb-border-soft)">' + crocBlocked(info, 'send') + '</div>'; bindInstall(viewSettings); }
			if (!info.exec) { box.innerHTML = '<div class="pb-panel__body" style="border-bottom:1px solid var(--pb-border-soft)">' + notice('warn', 'proc_open() is disabled by your host.', 'Use the upload/download option, or run croc on your own computer.') + '</div>'; }
		});
		on('[data-save]', 'click', function () {
			var out = {};
			$$('[data-s]').forEach(function (i) { out[i.dataset.s] = i.type === 'checkbox' ? (i.checked ? 1 : 0) : i.value; });
			api('save_settings', { settings: out }).then(function (r) { S.settings = r.settings; toast('Settings saved', 'ok'); viewSettings(); }).catch(function (e) { toast(e.message, 'err'); });
		});
	}

	/* ================= resume ================= */
	function resumeBanner() {
		var r = C.resume;
		render(notice('info', 'An unfinished ' + esc(r.type) + ' was found.', 'It can continue exactly where it stopped.',
			'<div class="pb-btn-row"><button type="button" class="pb-btn pb-btn--primary" data-resume>Continue ' + esc(r.type) + '</button><button type="button" class="pb-btn pb-btn--plain" data-discard>Discard</button></div>'));
		on('[data-resume]', 'click', function () {
			S.job = r.id;
			beginJob(r.type, 'Resuming ' + r.type);
			api('step', { id: r.id, since: 0 }).then(runJob).catch(failGeneric);
		});
		on('[data-discard]', 'click', function () {
			api('cancel', { id: r.id }).then(function () { C.resume = null; route(); });
		});
	}

	if (C.resume) { resumeBanner(); } else { route(); }
})();
