/* 秀明ファーム茨木 かんたん投稿 — 写真はブラウザ内で縮小してから送る */
(function () {
	'use strict';

	var cfg = window.SKT_CONFIG || {};
	var MAX_EDGE = 1600;
	var QUALITY = 0.82;
	var STORE_PASS = 'skt_pass';
	var STORE_ROLE = 'skt_role';
	var STORE_AUTHOR = 'skt_author';
	var STORE_DRAFT = 'skt_draft';
	var STORE_TPL = 'skt_tpl';
	var NEW_CATEGORY = '__new__';

	var photos = []; // { blob, url, name }

	var el = {
		tabs: document.getElementById('skt-tabs'),
		help: document.getElementById('skt-help'),
		manage: document.getElementById('skt-manage'),
		manageToast: document.getElementById('skt-manage-toast'),
		list: document.getElementById('skt-list'),
		reload: document.getElementById('skt-reload'),
		login: document.getElementById('skt-login'),
		setup: document.getElementById('skt-setup'),
		form: document.getElementById('skt-form'),
		sending: document.getElementById('skt-sending'),
		sendingText: document.getElementById('skt-sending-text'),
		progress: document.getElementById('skt-progress-bar'),
		done: document.getElementById('skt-done'),
		doneTitle: document.getElementById('skt-done-title'),
		doneText: document.getElementById('skt-done-text'),
		pass: document.getElementById('skt-pass'),
		loginBtn: document.getElementById('skt-login-btn'),
		loginError: document.getElementById('skt-login-error'),
		formError: document.getElementById('skt-form-error'),
		pick: document.getElementById('skt-pick'),
		fileInput: document.getElementById('skt-photos'),
		previews: document.getElementById('skt-previews'),
		author: document.getElementById('skt-author'),
		authorList: document.getElementById('skt-author-list'),
		category: document.getElementById('skt-category'),
		newCategory: document.getElementById('skt-new-category'),
		catList: document.getElementById('skt-cat-list'),
		catNew: document.getElementById('skt-cat-new'),
		catAdd: document.getElementById('skt-cat-add'),
		title: document.getElementById('skt-title'),
		body: document.getElementById('skt-body'),
		submit: document.getElementById('skt-submit'),
		template: document.getElementById('skt-template'),
		templateSave: document.getElementById('skt-template-save'),
		again: document.getElementById('skt-again'),
		max: document.getElementById('skt-max')
	};

	/* ---------- 保存まわり（端末に保存できない設定でも落ちないように） ---------- */

	function store(key, value) {
		try {
			if (value === null) {
				localStorage.removeItem(key);
			} else {
				localStorage.setItem(key, value);
			}
		} catch (e) { /* プライベートモード等 */ }
	}

	function load(key) {
		try {
			return localStorage.getItem(key);
		} catch (e) {
			return null;
		}
	}

	/**
	 * 自分の定型文があればそれ、なければ共通の雛形。
	 */
	function myTemplate() {
		return load(STORE_TPL) || cfg.template || '';
	}

	/**
	 * ボタンの文字を一瞬だけ変えて、押せたことを知らせる。
	 */
	function flash(button, message) {
		var original = button.dataset.label || button.textContent;
		button.dataset.label = original;
		button.textContent = message;
		window.setTimeout(function () {
			button.textContent = original;
		}, 2000);
	}

	/* ---------- 画面の出し分け ---------- */

	function show(section) {
		[el.login, el.setup, el.form, el.sending, el.done, el.manage].forEach(function (node) {
			if (node) { node.hidden = node !== section; }
		});
		el.help.hidden = section !== el.form;
		markTab(section === el.manage ? 'manage' : 'post');
		window.scrollTo(0, 0);
	}

	function isAdmin() {
		return load(STORE_ROLE) === 'admin';
	}

	function markTab(target) {
		Array.prototype.forEach.call(el.tabs.querySelectorAll('.skt-tab'), function (tab) {
			tab.classList.toggle('is-active', tab.dataset.target === target);
		});
	}

	function showError(node, message) {
		if (!node) { return; }
		node.textContent = message;
		node.hidden = !message;
	}

	/* ---------- 起動 ---------- */

	function init() {
		if (cfg.needsSetup) {
			show(el.setup);
			return;
		}

		el.max.textContent = cfg.maxPhotos;
		fillAuthors();
		fillCategories();

		restoreDraft();
		bind();

		if (load(STORE_PASS)) {
			el.tabs.hidden = !isAdmin();
			show(el.form);
		} else {
			show(el.login);
		}
	}

	/**
	 * 名前の候補。自分の名前は端末に覚えさせる。
	 */
	function fillAuthors() {
		el.authorList.innerHTML = '';
		(cfg.authors || []).forEach(function (name) {
			var option = document.createElement('option');
			option.value = name;
			el.authorList.appendChild(option);
		});
		el.author.value = load(STORE_AUTHOR) || '';
	}

	/**
	 * カテゴリの選択肢。末尾は「新しいカテゴリを作る」。
	 */
	function fillCategories() {
		var items = (cfg.categories || []).map(function (cat) {
			return { value: String(cat.id), label: cat.name };
		});
		items.push({ value: NEW_CATEGORY, label: '＋ 新しいカテゴリを作る' });
		fillSelect(el.category, items, String(cfg.defaultCategory || ''), null);
		toggleNewCategory();
	}

	function toggleNewCategory() {
		var isNew = el.category.value === NEW_CATEGORY;
		el.newCategory.hidden = !isNew;
		if (!isNew) { el.newCategory.value = ''; }
	}

	function fillSelect(select, items, selected, placeholder) {
		select.innerHTML = '';
		if (placeholder) {
			var blank = document.createElement('option');
			blank.value = '';
			blank.textContent = placeholder;
			select.appendChild(blank);
		}
		items.forEach(function (item) {
			var option = document.createElement('option');
			option.value = item.value;
			option.textContent = item.label;
			if (selected && item.value === selected) { option.selected = true; }
			select.appendChild(option);
		});
	}

	function bind() {
		el.loginBtn.addEventListener('click', doLogin);
		el.pass.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') { doLogin(); }
		});
		el.pick.addEventListener('click', function () { el.fileInput.click(); });
		el.fileInput.addEventListener('change', onPick);
		el.form.addEventListener('submit', onSubmit);
		el.again.addEventListener('click', function () {
			showError(el.formError, '');
			show(el.form);
		});
		[el.title, el.body].forEach(function (node) {
			node.addEventListener('input', saveDraft);
		});
		el.author.addEventListener('change', function () {
			store(STORE_AUTHOR, el.author.value.trim());
		});
		el.category.addEventListener('change', toggleNewCategory);
		el.template.hidden = !myTemplate();
		el.templateSave.hidden = el.template.hidden;
		el.template.addEventListener('click', function () {
			// {日付} は今日の日付に差し替えて入れる。
			var text = myTemplate().split('{日付}').join(cfg.today || '');
			var current = el.body.value.trim();
			el.body.value = current ? current + '\n\n' + text : text;
			saveDraft();
			el.body.focus();
		});
		el.templateSave.addEventListener('click', function () {
			var current = el.body.value.trim();
			// 今日の日付は {日付} に戻して覚える。空なら共通の雛形に戻す。
			store(STORE_TPL, current ? current.split(cfg.today || '\u0000').join('{日付}') : null);
			el.template.hidden = !myTemplate();
			flash(el.templateSave, current ? '覚えました' : '共通に戻しました');
		});
		el.catAdd.addEventListener('click', function () {
			var name = el.catNew.value.trim();
			if (!name) { return; }
			categoryOp({ op: 'create', name: name }).then(function () {
				el.catNew.value = '';
			});
		});
		Array.prototype.forEach.call(el.tabs.querySelectorAll('.skt-tab'), function (tab) {
			tab.addEventListener('click', function () {
				if (tab.dataset.target === 'manage') {
					show(el.manage);
					loadPosts();
				} else {
					show(el.form);
				}
			});
		});
		el.reload.addEventListener('click', loadPosts);
	}

	/* ---------- 合言葉 ---------- */

	function doLogin() {
		var value = el.pass.value.trim();
		if (!value) {
			showError(el.loginError, '合言葉を入れてください。');
			return;
		}
		el.loginBtn.disabled = true;
		showError(el.loginError, '');

		var form = new FormData();
		form.append('passphrase', value);

		fetch(cfg.verifyUrl, { method: 'POST', body: form })
			.then(function (res) {
				if (!res.ok) {
					return res.json().then(function (data) {
						showError(el.loginError, (data && data.message) || '合言葉が違います。');
					});
				}
				return res.json().then(function (data) {
					store(STORE_PASS, value);
					store(STORE_ROLE, (data && data.role) || 'producer');
					el.pass.value = '';
					el.tabs.hidden = !isAdmin();
					show(el.form);
				});
			})
			.catch(function () {
				showError(el.loginError, '通信できませんでした。電波の良い場所でお試しください。');
			})
			.then(function () {
				el.loginBtn.disabled = false;
			});
	}

	/* ---------- 写真の選択と縮小 ---------- */

	function onPick(event) {
		var files = Array.prototype.slice.call(event.target.files || []);
		el.fileInput.value = '';
		if (!files.length) { return; }

		var room = cfg.maxPhotos - photos.length;
		if (room <= 0) {
			showError(el.formError, '写真は' + cfg.maxPhotos + '枚までです。');
			return;
		}
		if (files.length > room) {
			files = files.slice(0, room);
			showError(el.formError, '写真は' + cfg.maxPhotos + '枚までなので、はじめの' + room + '枚を使います。');
		} else {
			showError(el.formError, '');
		}

		var label = el.pick.querySelector('span:last-child');
		var original = label.textContent;
		el.pick.disabled = true;
		label.textContent = '読み込み中…';

		files.reduce(function (chain, file) {
			return chain.then(function () {
				return shrink(file).then(function (item) {
					if (item) { photos.push(item); }
				});
			});
		}, Promise.resolve()).then(function () {
			el.pick.disabled = false;
			label.textContent = original;
			renderPreviews();
		});
	}

	/**
	 * 画像を長辺 MAX_EDGE px の JPEG に縮小する。
	 * 縮小できない形式のときは元のファイルをそのまま送る。
	 */
	function shrink(file) {
		if (!/^image\//.test(file.type)) {
			return Promise.resolve(null);
		}

		return loadBitmap(file)
			.then(function (source) {
				var scale = Math.min(1, MAX_EDGE / Math.max(source.width, source.height));
				var canvas = document.createElement('canvas');
				canvas.width = Math.round(source.width * scale);
				canvas.height = Math.round(source.height * scale);

				canvas.getContext('2d').drawImage(source, 0, 0, canvas.width, canvas.height);
				if (source.close) { source.close(); }

				return new Promise(function (resolve) {
					canvas.toBlob(function (blob) {
						if (!blob) {
							resolve(asIs(file));
							return;
						}
						resolve({
							blob: blob,
							url: URL.createObjectURL(blob),
							name: safeName(file.name).replace(/\.[^.]+$/, '') + '.jpg'
						});
					}, 'image/jpeg', QUALITY);
				});
			})
			.catch(function () {
				return asIs(file);
			});
	}

	function asIs(file) {
		return { blob: file, url: URL.createObjectURL(file), name: safeName(file.name) };
	}

	function loadBitmap(file) {
		if (window.createImageBitmap) {
			return createImageBitmap(file, { imageOrientation: 'from-image' }).catch(function () {
				return loadImageElement(file);
			});
		}
		return loadImageElement(file);
	}

	function loadImageElement(file) {
		return new Promise(function (resolve, reject) {
			var url = URL.createObjectURL(file);
			var img = new Image();
			img.onload = function () {
				URL.revokeObjectURL(url);
				resolve(img);
			};
			img.onerror = function () {
				URL.revokeObjectURL(url);
				reject(new Error('load failed'));
			};
			img.src = url;
		});
	}

	function safeName(name) {
		var cleaned = String(name || 'photo.jpg').replace(/[^\w.\-]/g, '_');
		return /\.[a-zA-Z0-9]+$/.test(cleaned) ? cleaned : cleaned + '.jpg';
	}

	function renderPreviews() {
		el.previews.innerHTML = '';
		photos.forEach(function (photo, index) {
			var wrap = document.createElement('div');
			wrap.className = 'skt-thumb';

			var img = document.createElement('img');
			img.src = photo.url;
			img.alt = (index + 1) + '枚目の写真';
			wrap.appendChild(img);

			var remove = document.createElement('button');
			remove.type = 'button';
			remove.className = 'skt-thumb-remove';
			remove.textContent = '×';
			remove.setAttribute('aria-label', (index + 1) + '枚目を取り消す');
			remove.addEventListener('click', function () {
				URL.revokeObjectURL(photo.url);
				photos.splice(index, 1);
				renderPreviews();
			});
			wrap.appendChild(remove);

			el.previews.appendChild(wrap);
		});
	}

	/* ---------- 書きかけの一時保存 ---------- */

	function saveDraft() {
		store(STORE_DRAFT, JSON.stringify({ title: el.title.value, body: el.body.value }));
	}

	function restoreDraft() {
		var raw = load(STORE_DRAFT);
		if (!raw) { return; }
		try {
			var draft = JSON.parse(raw);
			el.title.value = draft.title || '';
			el.body.value = draft.body || '';
		} catch (e) { /* 壊れていたら無視 */ }
	}

	/* ---------- 送信 ---------- */

	function onSubmit(event) {
		event.preventDefault();
		showError(el.formError, '');

		if (!photos.length && !el.body.value.trim()) {
			showError(el.formError, '写真か文章のどちらかは入れてください。');
			return;
		}

		var pass = load(STORE_PASS);
		if (!pass) {
			show(el.login);
			return;
		}

		var form = new FormData();
		form.append('passphrase', pass);
		form.append('title', el.title.value);
		form.append('body', el.body.value);
		form.append('author_name', el.author.value.trim());
		form.append('category_id', el.category.value === NEW_CATEGORY ? '0' : el.category.value);
		form.append('new_category', el.category.value === NEW_CATEGORY ? el.newCategory.value.trim() : '');
		photos.forEach(function (photo) {
			form.append('photos[]', photo.blob, photo.name);
		});

		show(el.sending);
		el.sendingText.textContent = photos.length ? '写真を送っています…' : '送信しています…';
		el.progress.style.width = '0%';

		var xhr = new XMLHttpRequest();
		xhr.open('POST', cfg.submitUrl);

		xhr.upload.addEventListener('progress', function (e) {
			if (!e.lengthComputable) { return; }
			var percent = Math.round((e.loaded / e.total) * 100);
			el.progress.style.width = percent + '%';
			if (percent >= 100) { el.sendingText.textContent = '記事を作っています…'; }
		});

		xhr.addEventListener('load', function () {
			var data = {};
			try { data = JSON.parse(xhr.responseText); } catch (e) { /* noop */ }

			if (xhr.status >= 200 && xhr.status < 300 && data.ok) {
				onSuccess(data);
			} else {
				show(el.form);
				showError(el.formError, data.message || '送信できませんでした。もう一度お試しください。');
			}
		});

		xhr.addEventListener('error', function () {
			show(el.form);
			showError(el.formError, '通信が途切れました。電波の良い場所でもう一度お試しください。');
		});

		xhr.send(form);
	}

	function onSuccess(data) {
		store(STORE_AUTHOR, el.author.value.trim());
		if (data.categories) {
			cfg.categories = data.categories;
			fillCategories();
		}
		photos.forEach(function (photo) { URL.revokeObjectURL(photo.url); });
		photos = [];
		renderPreviews();
		el.title.value = '';
		el.body.value = '';
		store(STORE_DRAFT, null);

		el.doneTitle.textContent = data.published ? '公開しました' : '送信しました';
		el.doneText.textContent = data.message || '';
		show(el.done);
	}

	/* ---------- 管理モード（確認・公開） ---------- */

	/**
	 * 合言葉を添えてサーバーに問い合わせる。
	 */
	function api(url, fields) {
		var form = new FormData();
		form.append('passphrase', load(STORE_PASS) || '');
		Object.keys(fields || {}).forEach(function (key) {
			form.append(key, fields[key]);
		});

		return fetch(url, { method: 'POST', body: form }).then(function (res) {
			return res.json().catch(function () { return {}; }).then(function (data) {
				if (!res.ok) {
					var error = new Error(data.message || 'うまくいきませんでした。');
					error.status = res.status;
					throw error;
				}
				return data;
			});
		});
	}

	function toast(message, isError) {
		el.manageToast.textContent = message;
		el.manageToast.hidden = !message;
		el.manageToast.classList.toggle('is-error', !!isError);
		if (message) {
			window.clearTimeout(toast._timer);
			toast._timer = window.setTimeout(function () {
				el.manageToast.hidden = true;
			}, 5000);
		}
	}

	function loadPosts() {
		el.list.textContent = '';
		el.list.appendChild(note('読み込んでいます…'));

		api(cfg.postsUrl).then(function (data) {
			renderList(data.posts || []);
			applyCategories(data.categories);
		}).catch(function (error) {
			el.list.textContent = '';
			el.list.appendChild(note(error.message));
			if (error.status === 403) {
				store(STORE_PASS, null);
				store(STORE_ROLE, null);
				el.tabs.hidden = true;
				show(el.login);
			}
		});
	}

	function note(text) {
		var p = document.createElement('p');
		p.className = 'skt-note';
		p.textContent = text;
		return p;
	}

	/* ---------- カテゴリの整理 ---------- */

	function applyCategories(categories) {
		if (!categories) { return; }
		cfg.categories = categories;
		fillCategories();
		renderCategories(categories);
	}

	function renderCategories(categories) {
		el.catList.textContent = '';
		categories.forEach(function (cat) {
			var row = document.createElement('div');
			row.className = 'skt-cat-row';

			var name = document.createElement('span');
			name.className = 'skt-cat-name';
			name.textContent = cat.name + '（' + cat.count + '件）';
			row.appendChild(name);

			var rename = document.createElement('button');
			rename.type = 'button';
			rename.className = 'skt-btn skt-btn-small skt-btn-ghost';
			rename.textContent = '名前を変える';
			rename.addEventListener('click', function () {
				var next = window.prompt('新しい名前', cat.name);
				if (next && next.trim() && next.trim() !== cat.name) {
					categoryOp({ op: 'rename', id: cat.id, name: next.trim() });
				}
			});
			row.appendChild(rename);

			var remove = document.createElement('button');
			remove.type = 'button';
			remove.className = 'skt-btn skt-btn-small skt-btn-ghost skt-btn-quiet';
			remove.textContent = '削除';
			remove.addEventListener('click', function () {
				var warn = cat.count
					? 'このカテゴリの記事' + cat.count + '件は、既定のカテゴリに移ります。削除しますか？'
					: 'このカテゴリを削除しますか？';
				if (window.confirm(warn)) {
					categoryOp({ op: 'delete', id: cat.id });
				}
			});
			row.appendChild(remove);

			el.catList.appendChild(row);
		});
	}

	function categoryOp(fields) {
		return api(cfg.categoryUrl, fields)
			.then(function (data) {
				toast(data.message);
				applyCategories(data.categories);
			})
			.catch(function (error) {
				toast(error.message, true);
			});
	}

	function renderList(posts) {
		el.list.textContent = '';
		if (!posts.length) {
			el.list.appendChild(note('まだ記事がありません。'));
			return;
		}
		posts.forEach(function (post) {
			el.list.appendChild(buildCard(post));
		});
	}

	function buildCard(post) {
		var card = document.createElement('article');
		card.className = 'skt-item';

		var head = document.createElement('div');
		head.className = 'skt-item-head';

		if (post.thumb) {
			var thumb = document.createElement('img');
			thumb.className = 'skt-item-thumb';
			thumb.src = post.thumb;
			thumb.alt = '';
			head.appendChild(thumb);
		}

		var texts = document.createElement('div');
		texts.className = 'skt-item-texts';

		var meta = document.createElement('p');
		meta.className = 'skt-item-meta';

		var badge = document.createElement('span');
		badge.className = 'skt-badge' + ('publish' === post.status ? ' is-public' : '');
		badge.textContent = post.statusText;
		meta.appendChild(badge);
		meta.appendChild(document.createTextNode(
			' ' + post.date + (post.author ? ' ／ ' + post.author : '')
		));
		texts.appendChild(meta);

		var title = document.createElement('h3');
		title.className = 'skt-item-title';
		title.textContent = post.title;
		texts.appendChild(title);

		if (post.excerpt) {
			var excerpt = document.createElement('p');
			excerpt.className = 'skt-item-excerpt';
			excerpt.textContent = post.excerpt;
			texts.appendChild(excerpt);
		}

		head.appendChild(texts);
		card.appendChild(head);
		card.appendChild(buildActions(post, card));

		return card;
	}

	function buildActions(post, card) {
		var row = document.createElement('div');
		row.className = 'skt-item-actions';

		var isPublic = 'publish' === post.status;

		var toggle = document.createElement('button');
		toggle.type = 'button';
		toggle.className = 'skt-btn skt-btn-small ' + (isPublic ? 'skt-btn-ghost' : 'skt-btn-primary');
		toggle.textContent = isPublic ? '非公開にする' : '公開する';
		toggle.addEventListener('click', function () {
			var next = isPublic ? 'draft' : 'publish';
			if (isPublic && !window.confirm('この記事をブログから下げます。よろしいですか？')) { return; }
			toggle.disabled = true;
			api(cfg.statusUrl, { post_id: post.id, status: next })
				.then(function (data) {
					toast(data.message);
					loadPosts();
				})
				.catch(function (error) {
					toggle.disabled = false;
					toast(error.message, true);
				});
		});
		row.appendChild(toggle);

		if (post.link) {
			var view = document.createElement('a');
			view.className = 'skt-btn skt-btn-small skt-btn-ghost';
			view.href = post.link;
			view.target = '_blank';
			view.rel = 'noopener';
			view.textContent = '見る';
			row.appendChild(view);
		}

		if (post.editable) {
			var edit = document.createElement('button');
			edit.type = 'button';
			edit.className = 'skt-btn skt-btn-small skt-btn-ghost';
			edit.textContent = '直す';
			edit.addEventListener('click', function () {
				openEditor(post, card);
			});
			row.appendChild(edit);
		}

		var trash = document.createElement('button');
		trash.type = 'button';
		trash.className = 'skt-btn skt-btn-small skt-btn-ghost skt-btn-quiet';
		trash.textContent = 'ゴミ箱';
		trash.addEventListener('click', function () {
			if (!window.confirm('この記事をゴミ箱へ移します。よろしいですか？')) { return; }
			trash.disabled = true;
			api(cfg.trashUrl, { post_id: post.id })
				.then(function (data) {
					toast(data.message);
					loadPosts();
				})
				.catch(function (error) {
					trash.disabled = false;
					toast(error.message, true);
				});
		});
		row.appendChild(trash);

		return row;
	}

	/**
	 * カードの中でタイトルと文章を直す。
	 */
	function openEditor(post, card) {
		if (card.querySelector('.skt-editor')) { return; }

		var editor = document.createElement('div');
		editor.className = 'skt-editor';

		var titleLabel = document.createElement('label');
		titleLabel.className = 'skt-label';
		titleLabel.textContent = 'タイトル';
		var titleInput = document.createElement('input');
		titleInput.type = 'text';
		titleInput.className = 'skt-input';
		titleInput.value = post.title;

		var bodyLabel = document.createElement('label');
		bodyLabel.className = 'skt-label';
		bodyLabel.textContent = '文章';
		var bodyInput = document.createElement('textarea');
		bodyInput.className = 'skt-textarea';
		bodyInput.rows = 6;
		bodyInput.value = post.body || '';

		var row = document.createElement('div');
		row.className = 'skt-item-actions';

		var save = document.createElement('button');
		save.type = 'button';
		save.className = 'skt-btn skt-btn-small skt-btn-primary';
		save.textContent = '保存する';
		save.addEventListener('click', function () {
			save.disabled = true;
			api(cfg.updateUrl, { post_id: post.id, title: titleInput.value, body: bodyInput.value })
				.then(function (data) {
					toast(data.message);
					loadPosts();
				})
				.catch(function (error) {
					save.disabled = false;
					toast(error.message, true);
				});
		});

		var cancel = document.createElement('button');
		cancel.type = 'button';
		cancel.className = 'skt-btn skt-btn-small skt-btn-ghost';
		cancel.textContent = 'やめる';
		cancel.addEventListener('click', function () {
			editor.remove();
		});

		row.appendChild(save);
		row.appendChild(cancel);

		editor.appendChild(titleLabel);
		editor.appendChild(titleInput);
		editor.appendChild(bodyLabel);
		editor.appendChild(bodyInput);
		editor.appendChild(row);
		card.appendChild(editor);

		bodyInput.focus();
	}

	init();
})();
