/*
 * lesson-scene.js — draws a checked scene description (visuals.v1) with three.js (r128, global THREE).
 * Written once, used by every lesson page. The AI never writes code: it only fills in the description.
 *
 *   var s = LessonScene.mount(hostElement, spec, { onPick: function (partId) {} });
 *   s.state(i)            apply state i (0-based) of spec.states
 *   s.set('vacuole.scale', 1.3)   change one property (used by sliders/toggles)
 *   s.highlight(['wall'])  glow parts (ids or group ids)
 *   s.reset()             back to the first state and home view
 */
(function () {
  'use strict';
  var T = window.THREE;
  var errors = (window.__sceneErrors = window.__sceneErrors || []);

  function el(tag, cls, html) { var e = document.createElement(tag); if (cls) e.className = cls; if (html != null) e.innerHTML = html; return e; }
  function deg(v) { return (+v || 0) * Math.PI / 180; }
  function v3(a, d) { a = a || d || [0, 0, 0]; return new T.Vector3(+a[0] || 0, +a[1] || 0, +a[2] || 0); }
  function clamp(x, a, b) { return Math.max(a, Math.min(b, x)); }

  // ------------------------------------------------------------------ shapes
  function material(p) {
    var op = p.opacity == null ? 1 : clamp(+p.opacity, 0.02, 1);
    return new T.MeshStandardMaterial({
      color: new T.Color(p.colour || '#90a4ae'), transparent: op < 0.999, opacity: op,
      depthWrite: op >= 0.6, roughness: 0.55, metalness: 0.05, side: (p.open || op < 0.999) ? T.DoubleSide : T.FrontSide
    });
  }

  function textSprite(text, size, colour) {
    var c = document.createElement('canvas'), x = c.getContext('2d'), fs = 64;
    x.font = '600 ' + fs + 'px system-ui, sans-serif';
    var w = Math.ceil(x.measureText(text).width) + 24;
    c.width = w; c.height = fs + 24;
    x.font = '600 ' + fs + 'px system-ui, sans-serif';
    x.fillStyle = colour || '#37474f'; x.textBaseline = 'middle';
    x.fillText(text, 12, c.height / 2);
    var tex = new T.CanvasTexture(c);
    var sp = new T.Sprite(new T.SpriteMaterial({ map: tex, transparent: true, depthWrite: false }));
    var h = size || 0.4;
    sp.scale.set(h * c.width / c.height, h, 1);
    return sp;
  }

  function build(p) {
    var m, g, mat = material(p);
    switch (p.shape) {
      case 'box': g = new T.BoxGeometry((p.size || [1])[0] || 1, (p.size || [1, 1])[1] || 1, (p.size || [1, 1, 1])[2] || 1); m = new T.Mesh(g, mat); break;
      case 'sphere': g = new T.SphereGeometry(p.radius || 0.5, 32, 20); m = new T.Mesh(g, mat); break;
      case 'cylinder': g = new T.CylinderGeometry(p.radius || 0.5, p.radius || 0.5, p.height || 1, 36, 1, !!p.open); m = new T.Mesh(g, mat); break;
      case 'cone': g = new T.ConeGeometry(p.radius || 0.5, p.height || 1, 36, 1, !!p.open); m = new T.Mesh(g, mat); break;
      case 'torus': g = new T.TorusGeometry(p.radius || 1, p.tube || 0.1, 14, 64, deg(p.arc || 360)); m = new T.Mesh(g, mat); break;
      case 'plane': g = new T.PlaneGeometry((p.size || [1])[0] || 1, (p.size || [1, 1])[1] || 1); m = new T.Mesh(g, mat); break;
      case 'capsule': {   // r128 has no CapsuleGeometry: cylinder + two spheres
        m = new T.Group();
        var r = p.radius || 0.2, L = p.length || 0.6;
        m.add(new T.Mesh(new T.CylinderGeometry(r, r, L, 24), mat));
        var a = new T.Mesh(new T.SphereGeometry(r, 20, 12), mat); a.position.y = L / 2; m.add(a);
        var b = new T.Mesh(new T.SphereGeometry(r, 20, 12), mat); b.position.y = -L / 2; m.add(b);
        break;
      }
      case 'tube': {
        var pts = (p.points || [[0, 0, 0], [1, 0, 0]]).map(function (q) { return v3(q); });
        var base = v3(p.pos);
        pts = pts.map(function (q) { return q.sub(base); });   // points are world positions; mesh sits at pos
        g = new T.TubeGeometry(new T.CatmullRomCurve3(pts), Math.max(16, pts.length * 12), p.radius || 0.05, 10, false);
        m = new T.Mesh(g, mat); break;
      }
      case 'arrow': {
        m = new T.Group();
        var from = v3(p.from), to = v3(p.to, [1, 0, 0]);
        var dir = to.clone().sub(from), len = dir.length() || 1, w = p.radius || 0.06;
        var shaft = new T.Mesh(new T.CylinderGeometry(w, w, Math.max(0.01, len - w * 5), 12), mat);
        var head = new T.Mesh(new T.ConeGeometry(w * 2.6, w * 5, 16), mat);
        shaft.position.y = (len - w * 5) / 2; head.position.y = len - w * 2.5;
        m.add(shaft); m.add(head);
        m.quaternion.setFromUnitVectors(new T.Vector3(0, 1, 0), dir.normalize());
        p.pos = p.from;   // the arrow starts at "from"
        break;
      }
      case 'text': m = textSprite(String(p.text || ''), p.size, p.colour); break;
      case 'particles': {
        var n = Math.max(1, Math.min(600, p.count || 50));
        m = new T.InstancedMesh(new T.SphereGeometry(p.radius || 0.06, 10, 8), mat, 600);
        m.count = n;
        var reg = p.region || { pos: p.pos || [0, 0, 0], size: [2, 2, 2] };
        var rp = v3(reg.pos), rs = v3(reg.size, [2, 2, 2]);
        m.userData.region = { min: rp.clone().sub(rs.clone().multiplyScalar(0.5)), size: rs };
        m.userData.home = []; m.userData.vel = [];
        for (var i = 0; i < 600; i++) {
          var h = new T.Vector3(Math.random(), Math.random(), Math.random()).multiply(rs).add(m.userData.region.min);
          m.userData.home.push(h); m.userData.vel.push(new T.Vector3(Math.random() - 0.5, Math.random() - 0.5, Math.random() - 0.5));
        }
        p.pos = [0, 0, 0];   // instances use world positions
        m.frustumCulled = false;
        break;
      }
      default: errors.push('unknown shape ' + p.shape); m = new T.Group();
    }
    if (p.edges && g && p.shape !== 'tube') {
      var e = new T.LineSegments(new T.EdgesGeometry(g, 25), new T.LineBasicMaterial({ color: new T.Color(p.colour || '#607d8b'), transparent: true, opacity: 0.8 }));
      m.add(e);
    }
    m.position.copy(v3(p.pos));
    if (p.rot && p.shape !== 'arrow') m.rotation.set(deg(p.rot[0]), deg(p.rot[1]), deg(p.rot[2]));
    var sc = p.scale;
    if (Array.isArray(sc)) m.scale.set(+sc[0] || 1, +sc[1] || 1, +sc[2] || 1);
    else if (sc != null && p.shape !== 'text') m.scale.setScalar(+sc || 1);
    return m;
  }

  function eachMaterial(obj, fn) {
    obj.traverse(function (o) { if (o.material && o.material.isMeshStandardMaterial) fn(o.material); });
  }

  // ------------------------------------------------------------------ the stage
  function mount(host, spec, opts) {
    opts = opts || {};
    host.classList.add('ls-stage');
    host.innerHTML = '';
    var canvasHost = el('div', 'ls-canvas'), labelsEl = el('div', 'ls-labels'), capEl = el('div', 'ls-cap'), tools = el('div', 'ls-tools');
    tools.innerHTML = '<button type="button" class="ls-btn" data-act="home" aria-label="Reset view">Reset view</button>';
    host.appendChild(canvasHost); host.appendChild(labelsEl); host.appendChild(capEl); host.appendChild(tools);
    capEl.hidden = true;
    var api = { ok: false, state: function () {}, set: function () {}, highlight: function () {}, reset: function () {}, parts: {} };
    if (!T) { canvasHost.innerHTML = '<div class="ls-fallback">The 3D view could not load. Keep the assets folder beside this page.</div>'; return api; }
    var renderer;
    try { renderer = new T.WebGLRenderer({ antialias: true, alpha: true, preserveDrawingBuffer: !!opts.screenshot }); }
    catch (e) { canvasHost.innerHTML = '<div class="ls-fallback">This device cannot show 3D. The steps below still work.</div>'; return api; }
    api.ok = true;
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.75));
    canvasHost.appendChild(renderer.domElement);

    var scene = new T.Scene();
    var camera = new T.PerspectiveCamera(40, 1.6, 0.05, 500);
    scene.add(new T.HemisphereLight(0xffffff, 0x445566, 0.9));
    var dl = new T.DirectionalLight(0xffffff, 0.7); dl.position.set(5, 9, 7); scene.add(dl);
    var dl2 = new T.DirectionalLight(0xffffff, 0.25); dl2.position.set(-6, -3, -5); scene.add(dl2);

    var flat = spec.mode === 'panel';
    var parts = {}, groups = {}, base = {}, order = [];
    (spec.parts || []).forEach(function (p) {
      try {
        var obj = build(p);
        obj.userData.id = p.id;
        obj.traverse(function (o) { o.userData.partId = p.id; });
        scene.add(obj);
        parts[p.id] = { spec: p, obj: obj };
        order.push(p.id);
        if (p.group) (groups[p.group] = groups[p.group] || []).push(p.id);
        var op = []; eachMaterial(obj, function (mt) { op.push(mt.opacity); });
        base[p.id] = { pos: obj.position.clone(), scale: obj.scale.clone(), visible: !p.hidden, colour: new T.Color(p.colour || '#90a4ae'), opacity: p.opacity == null ? 1 : +p.opacity, speed: p.speed || 0.3, count: obj.count || 0 };
        obj.visible = !p.hidden;
      } catch (e) { errors.push('part ' + p.id + ': ' + e.message); }
    });
    api.parts = parts;

    function ids(list) {   // ids or group ids -> part ids
      var out = [];
      (list || []).forEach(function (k) {
        if (parts[k]) out.push(k);
        else if (groups[k]) out = out.concat(groups[k]);
        else errors.push('unknown part ' + k);
      });
      return out;
    }

    // ---------------------------------------------------------------- camera
    function fit() {
      var box = new T.Box3();
      order.forEach(function (k) { var o = parts[k].obj; if (o.visible && !(o.isInstancedMesh)) box.expandByObject(o); });
      if (box.isEmpty()) box.set(new T.Vector3(-2, -2, -2), new T.Vector3(2, 2, 2));
      var c = box.getCenter(new T.Vector3()), r = box.getSize(new T.Vector3()).length() / 2;
      return { target: [c.x, c.y, c.z], dist: Math.max(2.5, r * 2.3) };
    }
    var auto = fit();
    var cam = spec.camera || {};
    var home = { dist: cam.dist || auto.dist, rx: flat ? 0 : deg(cam.rx != null ? cam.rx : 18), ry: flat ? 0 : deg(cam.ry != null ? cam.ry : -25), target: v3(cam.target || auto.target) };
    var view = { dist: home.dist, rx: home.rx, ry: home.ry, target: home.target.clone() }, goal = null;
    function placeCam() {
      var d = view.dist;
      camera.position.set(view.target.x + d * Math.sin(view.ry) * Math.cos(view.rx), view.target.y + d * Math.sin(view.rx), view.target.z + d * Math.cos(view.ry) * Math.cos(view.rx));
      camera.lookAt(view.target);
    }
    placeCam();

    // ---------------------------------------------------------------- labels
    var labelDivs = {};
    var shown = {};   // part id -> true when its label is on
    function labelFor(id) {
      var p = parts[id] && parts[id].spec;
      if (!p || !p.label) return null;
      var d = labelDivs[id];
      if (!d) { d = el('div', 'ls-label'); d.textContent = p.label; labelsEl.appendChild(d); labelDivs[id] = d; }
      return d;
    }
    function anchor(id) {
      var o = parts[id].obj, p = parts[id].spec;
      if (p.anchor) return v3(p.anchor);
      if (o.isInstancedMesh) return v3(p.region ? p.region.pos : p.pos);
      if (p.shape === 'arrow') return v3(p.to).add(v3(p.from)).multiplyScalar(0.5);
      var b = new T.Box3().setFromObject(o);
      var c = b.getCenter(new T.Vector3());
      if (p.shape === 'box' || p.opacity < 0.3) c.y = b.max.y;   // big see-through shells: label their top edge
      return c;
    }
    var tmp = new T.Vector3();
    var svgNS = 'http://www.w3.org/2000/svg', lines = document.createElementNS(svgNS, 'svg');
    lines.setAttribute('class', 'ls-lines'); labelsEl.appendChild(lines);
    function placeLabels() {
      var w = canvasHost.clientWidth, h = canvasHost.clientHeight, boxes = [];
      Object.keys(labelDivs).forEach(function (id) {
        var d = labelDivs[id];
        if (!shown[id] || !parts[id].obj.visible) { d.style.display = 'none'; return; }
        d.style.display = 'block';
        tmp.copy(anchor(id)).project(camera);
        if (tmp.z > 1) { d.style.display = 'none'; return; }
        boxes.push({ d: d, x: (tmp.x * 0.5 + 0.5) * w, y: (-tmp.y * 0.5 + 0.5) * h, w: d.offsetWidth || 70, h: d.offsetHeight || 20 });
      });
      var svg = '';
      if (boxes.length > 5) {
        // Textbook style: two columns at the sides, a leader line to each part.
        var mid = w / 2, top = 6, bottom = h - (capEl.hidden ? 6 : 46);
        [boxes.filter(function (b) { return b.x < mid; }), boxes.filter(function (b) { return b.x >= mid; })].forEach(function (col, side) {
          col.sort(function (a, b) { return a.y - b.y; });
          var gap = 3, total = col.reduce(function (s, b) { return s + b.h + gap; }, 0), scale = total > bottom - top ? (bottom - top) / total : 1;
          var y = top;
          col.forEach(function (b) { b.ly = Math.max(y, Math.min(b.y - b.h / 2, bottom - b.h)); y = b.ly + (b.h + gap) * scale; });
          for (var i = col.length - 2; i >= 0; i--) if (col[i].ly + col[i].h + gap > col[i + 1].ly) col[i].ly = col[i + 1].ly - col[i].h - gap;
          col.forEach(function (b) {
            var lx = side ? w - b.w - 6 : 6, ly = Math.max(top, b.ly);
            b.d.style.transform = 'translate(' + lx.toFixed(1) + 'px,' + ly.toFixed(1) + 'px)';
            var ex = side ? lx : lx + b.w, ey = ly + b.h / 2;
            svg += '<line x1="' + ex.toFixed(1) + '" y1="' + ey.toFixed(1) + '" x2="' + b.x.toFixed(1) + '" y2="' + b.y.toFixed(1) + '"/><circle cx="' + b.x.toFixed(1) + '" cy="' + b.y.toFixed(1) + '" r="2.5"/>';
          });
        });
      } else {
        boxes.sort(function (a, b) { return a.y - b.y; });
        for (var i = 0; i < boxes.length; i++) for (var j = 0; j < i; j++) {
          var a = boxes[i], b = boxes[j];
          if (Math.abs(a.x - b.x) < (a.w + b.w) / 2 + 2 && Math.abs(a.y - b.y) < (a.h + b.h) / 2 + 2) a.y = b.y + (a.h + b.h) / 2 + 2;
        }
        boxes.forEach(function (b) {
          var x = clamp(b.x, b.w / 2 + 2, w - b.w / 2 - 2), y = clamp(b.y, b.h / 2 + 2, h - b.h / 2 - 2);
          b.d.style.transform = 'translate(' + (x - b.w / 2).toFixed(1) + 'px,' + (y - b.h / 2).toFixed(1) + 'px)';
        });
      }
      lines.setAttribute('viewBox', '0 0 ' + w + ' ' + h); lines.setAttribute('width', w); lines.setAttribute('height', h);
      lines.innerHTML = svg;
      api.visibleLabels = boxes.map(function (b) { return b.d.textContent; });
    }

    // ---------------------------------------------------------------- state
    var tweens = [];
    function tween(dur, fn) { tweens.push({ t: 0, dur: dur, fn: fn }); }
    var lit = [];
    function setGlow(list) {
      lit = ids(list);
      order.forEach(function (k) {
        var on = lit.indexOf(k) >= 0, dim = lit.length && !on;
        eachMaterial(parts[k].obj, function (mt) {
          mt.emissive = on ? base[k].colour.clone().multiplyScalar(0.45) : new T.Color(0);
          mt.opacity = dim ? Math.min(base[k].opacity, 0.2) : (parts[k].opacityNow != null ? parts[k].opacityNow : base[k].opacity);
          mt.transparent = mt.opacity < 0.999; mt.depthWrite = mt.opacity >= 0.6;
        });
      });
    }
    function setProp(target, value, animate) {
      var dot = String(target).lastIndexOf('.');
      var id = String(target).slice(0, dot), prop = String(target).slice(dot + 1);
      ids([id]).forEach(function (k) {
        var P = parts[k], o = P.obj, b = base[k];
        switch (prop) {
          case 'scale': {
            var to = Array.isArray(value) ? new T.Vector3(value[0], value[1], value[2]).multiply(b.scale) : b.scale.clone().multiplyScalar(+value || 1);
            var from = o.scale.clone();
            if (animate) tween(600, function (f) { o.scale.lerpVectors(from, to, f); }); else o.scale.copy(to);
            break;
          }
          case 'x': case 'y': case 'z': {
            var f0 = o.position[prop], t0 = b.pos[prop] + (+value || 0);
            if (animate) tween(600, function (f) { o.position[prop] = f0 + (t0 - f0) * f; }); else o.position[prop] = t0;
            break;
          }
          case 'opacity': P.opacityNow = clamp(+value, 0, 1); eachMaterial(o, function (mt) { mt.opacity = P.opacityNow; mt.transparent = mt.opacity < 0.999; }); break;
          case 'colour': eachMaterial(o, function (mt) { mt.color = new T.Color(value); }); break;
          case 'visible': o.visible = !!value; break;
          case 'speed': P.speed = +value; break;
          case 'count': if (o.isInstancedMesh) o.count = clamp(Math.round(+value), 0, 600); break;
          default: errors.push('unknown property ' + prop);
        }
      });
    }
    function resetParts() {
      order.forEach(function (k) {
        var P = parts[k], o = P.obj, b = base[k];
        o.visible = b.visible; o.position.copy(b.pos); o.scale.copy(b.scale); P.speed = b.speed; P.opacityNow = null;
        if (o.isInstancedMesh) o.count = b.count;
        eachMaterial(o, function (mt) { mt.color = b.colour.clone(); mt.opacity = b.opacity; });
      });
    }
    var current = -1;
    function applyState(i, animate) {
      var st = (spec.states || [])[i];
      if (!st) return;
      current = i;
      var keep = {};
      order.forEach(function (k) { keep[k] = { pos: parts[k].obj.position.clone(), scale: parts[k].obj.scale.clone() }; });
      resetParts();
      if (animate) order.forEach(function (k) { parts[k].obj.position.copy(keep[k].pos); parts[k].obj.scale.copy(keep[k].scale); });
      ids(st.show).forEach(function (k) { parts[k].obj.visible = true; });
      ids(st.hide).forEach(function (k) { parts[k].obj.visible = false; });
      // properties: animate from where things are now
      if (animate) order.forEach(function (k) {
        var o = parts[k].obj, b = base[k], fp = o.position.clone(), fs = o.scale.clone();
        if (!fp.equals(b.pos) || !fs.equals(b.scale)) tween(500, function (f) { o.position.lerpVectors(fp, b.pos, f); o.scale.lerpVectors(fs, b.scale, f); });
      });
      Object.keys(st.set || {}).forEach(function (t) { setProp(t, st.set[t], animate); });
      setGlow(st.highlight || []);
      shown = {};
      var lab = st.labels;
      if (lab === 'all') order.forEach(function (k) { shown[k] = true; });
      else if (Array.isArray(lab)) ids(lab).forEach(function (k) { shown[k] = true; });
      lit.forEach(function (k) { if (lab !== 'none') shown[k] = true; });
      Object.keys(shown).forEach(labelFor);
      if (!st.camera && animate) goal = { dist: home.dist, rx: home.rx, ry: home.ry, target: home.target.clone() };   // no camera given: back to the home view
      if (st.camera) goal = { dist: st.camera.dist || view.dist, rx: st.camera.rx != null ? deg(st.camera.rx) : view.rx, ry: st.camera.ry != null ? deg(st.camera.ry) : view.ry, target: st.camera.target ? v3(st.camera.target) : view.target.clone() };
      capEl.hidden = !st.caption; capEl.textContent = st.caption || '';
      kick();
    }

    // ---------------------------------------------------------------- input
    var dom = renderer.domElement, drag = null, pts = {};
    dom.style.touchAction = 'pan-y';
    dom.addEventListener('pointerdown', function (e) { pts[e.pointerId] = { x: e.clientX, y: e.clientY }; drag = { x: e.clientX, y: e.clientY, moved: 0 }; goal = null; try { dom.setPointerCapture(e.pointerId); } catch (er) {} });
    dom.addEventListener('pointermove', function (e) {
      if (!pts[e.pointerId]) return;
      var keys = Object.keys(pts);
      if (keys.length === 2) {
        var o = pts[keys[0]], p = pts[keys[1]], d0 = Math.hypot(o.x - p.x, o.y - p.y);
        pts[e.pointerId] = { x: e.clientX, y: e.clientY }; o = pts[keys[0]]; p = pts[keys[1]];
        var d1 = Math.hypot(o.x - p.x, o.y - p.y);
        if (d0 > 0 && d1 > 0) view.dist = clamp(view.dist * d0 / d1, 1.5, 120);
        placeCam(); kick(); return;
      }
      pts[e.pointerId] = { x: e.clientX, y: e.clientY };
      if (!drag) return;
      var dx = e.clientX - drag.x, dy = e.clientY - drag.y;
      drag.x = e.clientX; drag.y = e.clientY; drag.moved += Math.abs(dx) + Math.abs(dy);
      if (!flat) { view.ry -= dx * 0.008; view.rx = clamp(view.rx + dy * 0.006, -1.35, 1.35); placeCam(); kick(); }
    });
    function up(e) {
      var was = drag; delete pts[e.pointerId]; drag = null;
      if (was && was.moved < 6) pickAt(e.clientX, e.clientY);
    }
    dom.addEventListener('pointerup', up);
    dom.addEventListener('pointercancel', function (e) { delete pts[e.pointerId]; drag = null; });
    dom.addEventListener('wheel', function (e) { if (!e.ctrlKey) return; e.preventDefault(); view.dist = clamp(view.dist * (1 + Math.sign(e.deltaY) * 0.08), 1.5, 120); placeCam(); kick(); }, { passive: false });
    tools.querySelector('[data-act=home]').addEventListener('click', function () { goal = { dist: home.dist, rx: home.rx, ry: home.ry, target: home.target.clone() }; kick(); });

    var ray = new T.Raycaster();
    function pickAt(cx, cy) {
      var r = dom.getBoundingClientRect();
      ray.setFromCamera(new T.Vector2(((cx - r.left) / r.width) * 2 - 1, -((cy - r.top) / r.height) * 2 + 1), camera);
      var hits = ray.intersectObjects(scene.children, true).filter(function (h) {
        var id = h.object.userData.partId; return id && parts[id].obj.visible && parts[id].spec.label;
      });
      // prefer small solid parts over big see-through shells
      hits.sort(function (a, b) {
        var sa = parts[a.object.userData.partId].spec, sb = parts[b.object.userData.partId].spec;
        return ((sa.opacity != null && sa.opacity < 0.3) - (sb.opacity != null && sb.opacity < 0.3)) || a.distance - b.distance;
      });
      if (!hits.length) return;
      api.pick(hits[0].object.userData.partId);
    }
    api.pick = function (id) {
      if (!parts[id]) return;
      var g = parts[id].spec.group;
      var list = g ? groups[g] : [id];
      setGlow(list);
      list.forEach(function (k) { if (parts[k].spec.label) { shown[k] = true; labelFor(k); } });
      if (opts.onPick) opts.onPick(id, parts[id].spec.label);
      kick();
    };

    // ---------------------------------------------------------------- loop
    var visible = true, needs = true, last = performance.now(), clock = 0;
    var hasParticles = order.some(function (k) { return parts[k].obj.isInstancedMesh && parts[k].spec.motion && parts[k].spec.motion !== 'none'; });
    function kick() { needs = true; }
    var mtx = new T.Matrix4(), q = new T.Vector3();
    function stepParticles(dt) {
      order.forEach(function (k) {
        var o = parts[k].obj; if (!o.isInstancedMesh) return;
        var sp = parts[k].spec, u = o.userData, speed = parts[k].speed != null ? parts[k].speed : 0.3, reg = u.region;
        var dir = v3(sp.direction, [1, 0, 0]).normalize();
        for (var i = 0; i < o.count; i++) {
          var h = u.home[i];
          if (sp.motion === 'jiggle') q.copy(h).add(new T.Vector3(Math.sin(clock * 7 + i), Math.cos(clock * 6 + i * 1.3), Math.sin(clock * 5 + i * 0.7)).multiplyScalar(0.05 * speed * 3));
          else if (sp.motion === 'random') {
            h.addScaledVector(u.vel[i], dt * speed * 2);
            ['x', 'y', 'z'].forEach(function (a) { var mn = reg.min[a], mx = reg.min[a] + reg.size[a]; if (h[a] < mn || h[a] > mx) { u.vel[i][a] *= -1; h[a] = clamp(h[a], mn, mx); } });
            if (Math.random() < 0.02) u.vel[i].set(Math.random() - 0.5, Math.random() - 0.5, Math.random() - 0.5);
            q.copy(h);
          } else if (sp.motion === 'flow') {
            h.addScaledVector(dir, dt * speed * 1.5);
            ['x', 'y', 'z'].forEach(function (a) { var mn = reg.min[a], mx = reg.min[a] + reg.size[a]; if (h[a] > mx) h[a] = mn; if (h[a] < mn) h[a] = mx; });
            q.copy(h);
          } else q.copy(h);
          mtx.makeTranslation(q.x, q.y, q.z); o.setMatrixAt(i, mtx);
        }
        o.instanceMatrix.needsUpdate = true;
      });
    }
    function frame(now) {
      requestAnimationFrame(frame);
      var dt = Math.min(0.05, (now - last) / 1000); last = now; clock += dt;
      if (!visible) return;
      var busy = hasParticles || tweens.length || goal;
      if (goal) {
        var k = 0.12;
        view.dist += (goal.dist - view.dist) * k; view.rx += (goal.rx - view.rx) * k; view.ry += (goal.ry - view.ry) * k; view.target.lerp(goal.target, k);
        if (Math.abs(goal.dist - view.dist) < 0.01 && Math.abs(goal.rx - view.rx) < 0.001 && Math.abs(goal.ry - view.ry) < 0.001) goal = null;
        placeCam();
      }
      tweens = tweens.filter(function (tw) { tw.t += dt * 1000; var f = Math.min(1, tw.t / tw.dur); tw.fn(f * f * (3 - 2 * f)); return f < 1; });
      if (hasParticles) stepParticles(dt);
      if (busy || needs) { renderer.render(scene, camera); placeLabels(); needs = false; }
    }
    function resize() {
      var w = canvasHost.clientWidth || 320, h = canvasHost.clientHeight || 220;
      renderer.setSize(w, h, false); renderer.domElement.style.width = w + 'px'; renderer.domElement.style.height = h + 'px';
      camera.aspect = w / h; camera.fov = w / h < 1.1 ? 52 : 40; camera.updateProjectionMatrix(); kick();
    }
    resize();
    if (window.ResizeObserver) new ResizeObserver(resize).observe(canvasHost); else window.addEventListener('resize', resize);
    if (window.IntersectionObserver) new IntersectionObserver(function (es) { visible = es[0].isIntersecting; kick(); }, { rootMargin: '120px' }).observe(host);
    stepParticles(0);
    requestAnimationFrame(frame);

    api.state = function (i) { applyState(i, true); };
    api.set = function (t, v) { setProp(t, v, false); kick(); };
    api.highlight = function (list) { setGlow(list); kick(); };
    api.reset = function () { goal = { dist: home.dist, rx: home.rx, ry: home.ry, target: home.target.clone() }; applyState(0, false); };
    api.render = function () { renderer.render(scene, camera); placeLabels(); };
    api.current = function () { return current; };
    if ((spec.states || []).length) applyState(0, false);
    return api;
  }

  window.LessonScene = { mount: mount, errors: errors };
})();
