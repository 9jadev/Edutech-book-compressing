/*
 * lesson.js — behaviour of a built lesson page (Step 6). Needs lesson-scene.js for 3D.
 * Reads window.LESSON = { scenes: { blockId: {scene, steps, tasks} }, questions: { qid: question } }.
 *  - Narration: the browser's voice reads the page (Listen) and each scene step; words light up as they are read.
 *  - Scenes: steps (Back / Next / Play all), tasks that light up their part, sliders, toggles and buttons.
 *  - Practice: 8 question kinds marked on the page; the explanation opens after the first try.
 */
(function () {
  'use strict';
  var L = window.LESSON || { scenes: {}, questions: {} };
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  function el(tag, cls, text) { var e = document.createElement(tag); if (cls) e.className = cls; if (text != null) e.textContent = text; return e; }
  function btn(text, cls, fn) { var b = el('button', 'btn ' + (cls || ''), text); b.type = 'button'; if (fn) b.addEventListener('click', fn); return b; }
  function norm(s) { return String(s == null ? '' : s).toLowerCase().replace(/[^\p{L}\p{N}.\-]+/gu, ' ').trim(); }
  function store(k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } }
  window.__lessonErrors = window.__lessonErrors || [];
  window.addEventListener('error', function (e) { window.__lessonErrors.push(String(e.message)); });

  // Seeded shuffle so the same question looks the same every visit.
  function shuffle(list, seed) {
    var a = list.slice(), h = 2166136261;
    for (var i = 0; i < seed.length; i++) { h ^= seed.charCodeAt(i); h = Math.imul(h, 16777619); }
    for (var j = a.length - 1; j > 0; j--) { h = Math.imul(h ^ (h >>> 15), 2246822507); var k = Math.abs(h) % (j + 1); var t = a[j]; a[j] = a[k]; a[k] = t; }
    if (a.length > 1 && a.every(function (x, i) { return x === list[i]; })) { a.push(a.shift()); }   // never leave it in the answer order
    return a;
  }

  // ------------------------------------------------------------------ theme
  (function () {
    var root = document.documentElement, saved = store('lesson-theme');
    if (saved) root.setAttribute('data-theme', saved);
    var b = $('#theme');
    if (b) b.addEventListener('click', function () {
      var dark = root.getAttribute('data-theme') === 'dark' || (!root.getAttribute('data-theme') && matchMedia('(prefers-color-scheme: dark)').matches);
      root.setAttribute('data-theme', dark ? 'light' : 'dark'); store('lesson-theme', dark ? 'light' : 'dark');
    });
  })();

  // ------------------------------------------------------------------ narration
  var N = { voice: null, on: false, queue: [], current: null, timer: null };
  function pickVoice() {
    if (!window.speechSynthesis) return;
    var vs = speechSynthesis.getVoices();
    N.voice = vs.filter(function (v) { return /en[-_](NG|GB)/i.test(v.lang); })[0] || vs.filter(function (v) { return /^en/i.test(v.lang); })[0] || null;
  }
  if (window.speechSynthesis) { pickVoice(); speechSynthesis.onvoiceschanged = pickVoice; }

  function wrapWords(node) {
    if (node.__words) return node.__words;
    var words = node.textContent.split(/\s+/).filter(Boolean);
    node.innerHTML = '';
    node.__words = words.map(function (w, i) { var s = el('span', 'w', w); node.appendChild(s); if (i < words.length - 1) node.appendChild(document.createTextNode(' ')); return s; });
    return node.__words;
  }
  function clearMarks() {
    $$('.w.on').forEach(function (w) { w.classList.remove('on'); });
    $$('.reading').forEach(function (x) { x.classList.remove('reading'); });
    clearInterval(N.timer);
  }
  // Speak text; light up node (word by word when the spoken text is the shown text).
  function speak(text, node, done) {
    stopVoice();
    if (!text) { if (done) done(); return; }
    if (node) node.classList.add('reading');
    var words = node && node.hasAttribute('data-say-same') ? wrapWords(node) : null;
    if (!window.speechSynthesis) {   // no voice: just highlight for a moment
      setTimeout(function () { clearMarks(); if (done) done(); }, 1200); return;
    }
    var u = new SpeechSynthesisUtterance(text);
    if (N.voice) u.voice = N.voice;
    u.lang = (N.voice && N.voice.lang) || 'en-GB'; u.rate = 0.95;
    var starts = [], pos = 0, got = false;
    if (words) words.forEach(function (w) { var i = text.indexOf(w.textContent, pos); starts.push(i < 0 ? pos : i); pos = (i < 0 ? pos : i) + w.textContent.length; });
    function mark(i) { if (!words) return; words.forEach(function (w, k) { w.classList.toggle('on', k === i); }); }
    u.onboundary = function (e) {
      if (!words || e.name === 'sentence') return; got = true;
      var i = 0; while (i + 1 < starts.length && starts[i + 1] <= e.charIndex) i++; mark(i);
    };
    u.onstart = function () {   // some voices send no word events: move the light at reading speed instead
      if (!words) return; var i = 0;
      setTimeout(function () { if (!got) N.timer = setInterval(function () { if (i < words.length) mark(i++); }, 360); }, 400);
    };
    var finished = false;
    var end = function () { if (finished) return; finished = true; clearMarks(); if (done) done(); };
    u.onend = end; u.onerror = end;
    N.current = u;
    speechSynthesis.speak(u);
  }
  function stopVoice() { if (window.speechSynthesis) speechSynthesis.cancel(); clearMarks(); }
  function stopAll() { N.on = false; N.queue = []; stopVoice(); $('#listen-bar') && $('#listen-bar').classList.remove('on'); }
  function readPage() {
    N.queue = $$('[data-say]').filter(function (n) { return !n.closest('.no-read'); });
    N.on = true; $('#listen-bar').classList.add('on');
    (function next() {
      if (!N.on) return;
      var n = N.queue.shift(); if (!n) { stopAll(); return; }
      n.scrollIntoView({ block: 'center', behavior: 'smooth' });
      speak(n.getAttribute('data-say'), n, function () { if (N.on) setTimeout(next, 250); });
    })();
  }
  var lb = $('#listen'); if (lb) lb.addEventListener('click', function () { N.on ? stopAll() : readPage(); });
  var ls = $('#listen-stop'); if (ls) ls.addEventListener('click', stopAll);
  var lp = $('#listen-pause'); if (lp) lp.addEventListener('click', function () {
    if (!window.speechSynthesis) return;
    if (speechSynthesis.paused) { speechSynthesis.resume(); lp.textContent = 'Pause'; } else { speechSynthesis.pause(); lp.textContent = 'Resume'; }
  });
  window.LessonVoice = { speak: speak, stop: stopAll };

  // ------------------------------------------------------------------ scenes
  window.LessonScenes = {};
  $$('[data-scene]').forEach(function (host) {
    var id = host.getAttribute('data-scene'), d = L.scenes[id];
    if (!d || !window.LessonScene) return;
    var s = window.LessonScenes[id] = LessonScene.mount(host, d.scene, {});
    var blk = host.closest('.blk'), ctl = $('.scene-ctl', blk), cur = 0, playing = false;
    var stepList = $('.steps.walk', blk), items = stepList ? $$('li', stepList) : [];
    function go(i, talk, then) {
      cur = Math.max(0, Math.min(items.length - 1, i));
      s.state(cur);
      items.forEach(function (li, k) { li.classList.toggle('on', k === cur); li.setAttribute('aria-current', k === cur ? 'step' : 'false'); });
      if (talk) speak(d.steps[cur] && d.steps[cur].say, items[cur], then); else if (then) then();
    }
    if (items.length) {
      items.forEach(function (li, i) { li.addEventListener('click', function () { playing = false; go(i, true); }); });
      ctl.appendChild(btn('Back', 'ghost small', function () { playing = false; go(cur - 1, true); }));
      ctl.appendChild(btn('Next', 'small', function () { playing = false; go(cur + 1, true); }));
      var play = btn('Play all', 'ghost small', function () {
        if (playing) { playing = false; stopVoice(); play.textContent = 'Play all'; return; }
        playing = true; play.textContent = 'Stop';
        (function step(i) {
          if (!playing || i >= items.length) { playing = false; play.textContent = 'Play all'; return; }
          go(i, true, function () { setTimeout(function () { step(i + 1); }, 600); });
        })(0);
      });
      ctl.appendChild(play);
      go(0, false);
    }
    (d.scene.controls || []).forEach(function (c) {
      var w = el('div', 'control');
      if (c.type === 'buttons') {
        w.appendChild(el('span', '', c.label + ':'));
        c.options.forEach(function (o) { w.appendChild(btn(o.label, 'ghost small', function () { s.state(o.state - 1); })); });
      } else if (c.type === 'slider') {
        var lab = el('label', '', c.label + ' '), r = el('input'); r.type = 'range'; r.min = c.min; r.max = c.max; r.step = c.step; r.value = c.value;
        r.id = 'ctl-' + id + '-' + c.id; r.addEventListener('input', function () { s.set(c.sets, +r.value); });
        lab.appendChild(r); w.appendChild(lab); s.set(c.sets, c.value);
      } else {
        var lab2 = el('label', ''), cb = el('input'); cb.type = 'checkbox'; cb.checked = !!c.value; cb.id = 'ctl-' + id + '-' + c.id;
        cb.addEventListener('change', function () { s.set(c.sets, cb.checked); });
        lab2.appendChild(cb); lab2.appendChild(document.createTextNode(' ' + c.label)); w.appendChild(lab2);
      }
      ctl.appendChild(w);
    });
    var targets = {}; (d.scene.tasks || []).forEach(function (t) { targets[t.task] = t.target; });
    $$('.steps.tasks li', blk).forEach(function (li, i) {
      li.addEventListener('click', function () {
        $$('.steps.tasks li', blk).forEach(function (x) { x.classList.toggle('on', x === li); });
        var tg = targets[i + 1];
        if (tg) { var c = document.getElementById('ctl-' + id + '-' + tg); if (c) c.focus(); else if (s.parts[tg]) s.pick(tg); else s.highlight([tg]); }
        speak(d.tasks[i] && d.tasks[i].say, li);
      });
    });
  });

  // ------------------------------------------------------------------ practice
  var score = { done: 0, right: 0, total: $$('[data-q]').length };
  function updateScore() { $$('.score').forEach(function (s) { s.textContent = score.right + ' of ' + score.total + ' right first time'; }); }
  function matches(answer, phrase) {   // every word (3+ letters) of the phrase appears, by its stem
    var a = ' ' + norm(answer) + ' ';
    return norm(phrase).split(' ').filter(function (w) { return w.length >= 3; }).every(function (w) {
      var stem = w.length > 5 ? w.slice(0, w.length - 2) : w; return a.indexOf(' ' + stem) >= 0;
    });
  }

  $$('[data-q]').forEach(function (box) {
    var qid = box.getAttribute('data-q'), q = L.questions[qid];
    if (!q) return;
    var area = el('div', 'q-body'), fb = el('div', 'fb-area'), acts = el('div', 'q-actions');
    box.appendChild(area); box.appendChild(acts); box.appendChild(fb);
    var tries = 0, read, show;   // read(): {ok, wrong} ; show(): reveal the right answer
    var K = {
      mcq: function () {
        var opts = el('div', 'opts'), order = shuffle(q.options.map(function (_, i) { return i; }), qid);
        order.forEach(function (i) {
          var l = el('label', 'opt'), r = el('input'); r.type = 'radio'; r.name = qid; r.value = i;
          l.appendChild(r); l.appendChild(el('span', '', q.options[i])); opts.appendChild(l);
        });
        area.appendChild(opts);
        read = function () {
          var c = $('input:checked', opts); if (!c) return null;
          $$('.opt', opts).forEach(function (o) { o.classList.remove('right', 'wrong'); });
          var ok = +c.value === q.answer; c.parentNode.classList.add(ok ? 'right' : 'wrong');
          return { ok: ok, wrong: q.options[+c.value] };
        };
        show = function () { $$('input', opts).forEach(function (r) { if (+r.value === q.answer) { r.checked = true; r.parentNode.classList.add('right'); } }); };
      },
      true_false: function () {
        var opts = el('div', 'opts'); var pick = null;
        [true, false].forEach(function (v) {
          var l = el('label', 'opt'), r = el('input'); r.type = 'radio'; r.name = qid; r.value = v ? '1' : '0';
          r.addEventListener('change', function () { pick = v; });
          l.appendChild(r); l.appendChild(el('span', '', v ? 'True' : 'False')); opts.appendChild(l);
        });
        area.appendChild(opts);
        read = function () {
          if (pick === null) return null; var ok = pick === q.answer;
          $$('.opt', opts).forEach(function (o) { o.classList.remove('right', 'wrong'); });
          $('input:checked', opts).parentNode.classList.add(ok ? 'right' : 'wrong');
          return { ok: ok, wrong: pick ? 'True' : 'False' };
        };
        show = function () { $$('input', opts).forEach(function (r) { if ((r.value === '1') === q.answer) { r.checked = true; r.parentNode.classList.add('right'); } }); };
      },
      match: function () {
        var rights = shuffle(q.pairs.map(function (p) { return p[1]; }), qid), sels = [];
        q.pairs.forEach(function (p, i) {
          var row = el('div', 'row'), s = el('select'); s.setAttribute('aria-label', 'Match for ' + p[0]);
          s.appendChild(new Option('Choose…', ''));
          rights.forEach(function (r) { s.appendChild(new Option(r, r)); });
          row.appendChild(el('span', 'left', p[0])); row.appendChild(s); area.appendChild(row); sels.push(s);
        });
        read = function () {
          if (sels.some(function (s) { return !s.value; })) return null;
          var bad = 0; sels.forEach(function (s, i) { var ok = s.value === q.pairs[i][1]; s.style.borderColor = ok ? 'var(--good)' : 'var(--bad)'; if (!ok) bad++; });
          return { ok: bad === 0, note: bad ? bad + ' of ' + sels.length + ' do not match yet.' : '' };
        };
        show = function () { sels.forEach(function (s, i) { s.value = q.pairs[i][1]; s.style.borderColor = 'var(--good)'; }); };
      },
      sort: function () {
        var items = []; q.groups.forEach(function (g) { g.members.forEach(function (m) { items.push({ m: m, g: g.name }); }); });
        var sels = [];
        shuffle(items, qid).forEach(function (it) {
          var row = el('div', 'row'), s = el('select'); s.setAttribute('aria-label', 'Group for ' + it.m);
          s.appendChild(new Option('Choose a group…', ''));
          q.groups.forEach(function (g) { s.appendChild(new Option(g.name, g.name)); });
          row.appendChild(el('span', 'left', it.m)); row.appendChild(s); area.appendChild(row); sels.push({ s: s, it: it });
        });
        read = function () {
          if (sels.some(function (x) { return !x.s.value; })) return null;
          var bad = 0; sels.forEach(function (x) { var ok = x.s.value === x.it.g; x.s.style.borderColor = ok ? 'var(--good)' : 'var(--bad)'; if (!ok) bad++; });
          return { ok: bad === 0, note: bad ? bad + ' of ' + sels.length + ' are in the wrong group.' : '' };
        };
        show = function () { sels.forEach(function (x) { x.s.value = x.it.g; x.s.style.borderColor = 'var(--good)'; }); };
      },
      order: function () {
        var ol = el('ol', 'order-list'), now = shuffle(q.sequence, qid);
        function draw() {
          ol.innerHTML = '';
          now.forEach(function (t, i) {
            var li = el('li'); li.appendChild(el('span', '', t));
            var up = el('button', '', '↑'), dn = el('button', '', '↓'); up.type = dn.type = 'button';
            up.setAttribute('aria-label', 'Move up: ' + t); dn.setAttribute('aria-label', 'Move down: ' + t);
            up.disabled = i === 0; dn.disabled = i === now.length - 1;
            up.onclick = function () { var x = now[i - 1]; now[i - 1] = now[i]; now[i] = x; draw(); };
            dn.onclick = function () { var x = now[i + 1]; now[i + 1] = now[i]; now[i] = x; draw(); };
            li.appendChild(up); li.appendChild(dn); ol.appendChild(li);
          });
        }
        draw(); area.appendChild(el('p', 'meta', 'Use the arrows to put them in order, first at the top.')); area.appendChild(ol);
        read = function () {
          var bad = now.filter(function (t, i) { return t !== q.sequence[i]; }).length;
          $$('li', ol).forEach(function (li, i) { li.style.borderColor = now[i] === q.sequence[i] ? 'var(--good)' : 'var(--bad)'; });
          return { ok: bad === 0, note: bad ? bad + ' are not in the right place yet.' : '' };
        };
        show = function () { now = q.sequence.slice(); draw(); $$('li', ol).forEach(function (li) { li.style.borderColor = 'var(--good)'; }); };
      },
      label: function () {
        // Find a scene on this page whose parts carry these labels; light each part up and ask for its name.
        var found = null, ids = [];
        Object.keys(L.scenes).some(function (k) {
          var parts = L.scenes[k].scene.parts || [], got = [];
          q.targets.forEach(function (t) { var p = parts.filter(function (pp) { return pp.label && (norm(pp.label) === norm(t) || matches(pp.label, t)); })[0]; if (p) got.push(p.id); });
          if (got.length === q.targets.length) { found = L.scenes[k].scene; ids = got; return true; }
          return false;
        });
        var sels = [];
        if (found && window.LessonScene) {
          var host = el('div'); area.appendChild(host);
          var spec = JSON.parse(JSON.stringify(found)); spec.states = [{ labels: 'none' }]; spec.controls = [];
          var s = LessonScene.mount(host, spec, {});
          var names = shuffle(q.targets, qid);
          q.targets.forEach(function (t, i) {
            var row = el('div', 'row');
            row.appendChild(btn('Show part ' + (i + 1), 'ghost small', function () { s.highlight([ids[i]]); }));
            var sel = el('select'); sel.setAttribute('aria-label', 'Name of part ' + (i + 1));
            sel.appendChild(new Option('Its name is…', '')); names.forEach(function (n) { sel.appendChild(new Option(n, n)); });
            row.appendChild(sel); area.appendChild(row); sels.push(sel);
          });
          read = function () {
            if (sels.some(function (x) { return !x.value; })) return null;
            var bad = 0; sels.forEach(function (x, i) { var ok = x.value === q.targets[i]; x.style.borderColor = ok ? 'var(--good)' : 'var(--bad)'; if (!ok) bad++; });
            return { ok: bad === 0, note: bad ? bad + ' of ' + sels.length + ' names are not right yet.' : '' };
          };
          show = function () { sels.forEach(function (x, i) { x.value = q.targets[i]; x.style.borderColor = 'var(--good)'; }); };
        } else {   // no matching 3D part: the student names the parts from memory and checks themselves
          var ta = el('textarea'); ta.setAttribute('aria-label', 'Your labels'); area.appendChild(ta);
          read = function () { if (!ta.value.trim()) return null; var hit = q.targets.filter(function (t) { return matches(ta.value, t); }).length; return { ok: hit === q.targets.length, note: hit + ' of ' + q.targets.length + ' labels found.' }; };
          show = function () { ta.value = q.targets.join(', '); };
        }
      },
      numeric: function () {
        var row = el('div', 'row'), inp = el('input'); inp.type = 'text'; inp.inputMode = 'decimal'; inp.setAttribute('aria-label', 'Your answer');
        row.appendChild(inp); if (q.unit) row.appendChild(el('span', '', q.unit)); area.appendChild(row);
        read = function () {
          var v = inp.value.replace(/,/g, '.').replace(/[^0-9.\-]/g, ''); if (v === '' || isNaN(+v)) return null;
          var tol = Math.max(q.tolerance || 0, Math.abs(q.answer) * 1e-9);
          var ok = Math.abs(+v - q.answer) <= tol; inp.style.borderColor = ok ? 'var(--good)' : 'var(--bad)';
          return { ok: ok, wrong: String(+v) };
        };
        show = function () { inp.value = q.answer; inp.style.borderColor = 'var(--good)'; };
      },
      short_text: function () {
        var ta = el('textarea'); ta.setAttribute('aria-label', 'Your answer'); area.appendChild(ta);
        read = function () {
          if (!ta.value.trim()) return null;
          var need = Math.max(1, Math.floor(q.accept.length / 2)), hit = q.accept.filter(function (a) { return matches(ta.value, a); }).length;
          return { ok: hit >= need, note: hit >= need ? '' : 'Your answer is missing some key ideas.', model: true };
        };
        show = function () {};
      }
    };
    (K[q.kind] || K.short_text)();

    function feedback(cls, html) { var d = el('div', 'fb ' + cls); d.innerHTML = html; fb.appendChild(d); return d; }
    function esc(s) { var d = el('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
    var check = btn('Check', 'small', function () {
      var r = read();
      fb.innerHTML = '';
      if (!r) { feedback('info', 'Answer first, then press Check.'); return; }
      tries++;
      if (tries === 1) { score.done++; if (r.ok) score.right++; updateScore(); }
      if (r.ok) {
        feedback('good', '<b>Correct.</b> ' + esc(q.explain));
      } else {
        var why = '';
        (q.mistakes || []).forEach(function (m) { if (r.wrong != null && norm(m.wrong) && (norm(r.wrong) === norm(m.wrong) || norm(r.wrong).indexOf(norm(m.wrong)) >= 0)) why = m.why; });
        feedback('bad', '<b>Not quite.</b> ' + esc(why || r.note || 'Have another look and try again.'));
        if (tries >= 1) { reveal.hidden = false; }
      }
      if (r.model && q.model_answer) feedback('info', '<b>Compare with:</b> ' + esc(q.model_answer));
    });
    var hint = btn('Hint', 'ghost small', function () { fb.innerHTML = ''; feedback('info', '<b>Hint:</b> ' + esc(q.hint)); });
    var reveal = btn('Show the answer', 'ghost small', function () { show(); fb.innerHTML = ''; feedback('info', '<b>Answer.</b> ' + esc(q.explain) + (q.model_answer ? '<br><b>Model answer:</b> ' + esc(q.model_answer) : '')); });
    reveal.hidden = true;
    var listen = btn('Read it to me', 'ghost small', function () { speak(q.say || q.prompt, $('.q-prompt', box)); });
    acts.appendChild(check); if (q.hint) acts.appendChild(hint); acts.appendChild(reveal); acts.appendChild(listen);
  });
  updateScore();
  window.LessonPractice = { score: score };
})();
