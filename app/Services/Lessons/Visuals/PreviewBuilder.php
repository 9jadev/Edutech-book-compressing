<?php

declare(strict_types=1);

namespace App\Services\Lessons\Visuals;

/**
 * Preview pages so a person can see and turn every scene before Step 6 builds the real lesson pages.
 * Each page shows its blocks in order: notes, the scene with its steps (Play/Back/Next, narrated with the
 * browser's voice), tasks that light up their target, controls, and the questions as plain text.
 *
 * Output (relative to the chapter folder):
 *   preview/index.html, preview/assets/*, preview/<part folder>/<page file>
 */
final class PreviewBuilder
{
    public const ASSETS = ['three.min.js', 'lesson-scene.js', 'lesson-scene.css'];

    /** @return array<string,string> relative path => HTML */
    public function build(array $plan, array $content, array $visuals): array
    {
        $cpages = [];
        foreach ($content['pages'] ?? [] as $p) {
            $cpages[$p['key']] = $p;
        }
        $vpages = [];
        foreach ($visuals['pages'] ?? [] as $p) {
            $vpages[$p['key']] = $p['scenes'] ?? [];
        }
        $out = [];
        $index = [];
        foreach ($plan['pages'] as $pp) {
            $cp = $cpages[$pp['key']] ?? null;
            if (!$cp) {
                continue;
            }
            $out["preview/{$pp['key']}"] = $this->page($pp, $cp, $vpages[$pp['key']] ?? []);
            $index[] = '<li><a href="' . $this->e($pp['key']) . '">' . $this->e($cp['title'] ?? $pp['title']) . '</a> <small>' . $this->e($pp['key']) . ' · ' . count($vpages[$pp['key']] ?? []) . ' scene(s)</small></li>';
        }
        $out['preview/index.html'] = $this->shell('Preview: ' . ($plan['source_pdf'] ?? 'chapter'), 'assets/',
            '<h1>Preview of the visuals</h1><p>Open a page to see and turn its scenes. This is a check before Step 6 builds the real lesson pages.</p><ol>' . implode('', $index) . '</ol>', '');
        return $out;
    }

    private function page(array $pp, array $cp, array $scenes): string
    {
        $html = ['<p class="crumb"><a href="../index.html">All pages</a></p>', '<h1>' . $this->e($cp['title'] ?? $pp['title']) . '</h1>'];
        if (!empty($cp['intro'])) {
            $html[] = '<p class="intro">' . $this->e($cp['intro']) . '</p>';
        }
        $data = [];
        foreach ($cp['blocks'] as $b) {
            $sc = $scenes[$b['id']] ?? null;
            $html[] = '<section class="blk" data-block="' . $this->e($b['id']) . '"><h2>' . $this->e($b['heading'] ?? '') . ' <small>' . $this->e($b['type']) . '</small></h2>';
            foreach ($b['paragraphs'] ?? [] as $p) {
                $html[] = '<p>' . $this->e($p['text']) . '</p>';
            }
            if (!empty($b['rule'])) {
                $html[] = '<p class="rule">' . $this->e($b['rule']) . '</p>';
            }
            if (!empty($b['intro'])) {
                $html[] = '<p>' . $this->e($b['intro']) . '</p>';
            }
            if ($sc) {
                $html[] = '<div class="stage" id="st-' . $this->e($b['id']) . '"></div><div class="ctl" id="ctl-' . $this->e($b['id']) . '"></div>';
                $data[$b['id']] = ['scene' => $sc, 'steps' => array_map(fn ($s) => ['text' => $s['text'], 'say' => $s['say'] ?? $s['text']], $b['steps'] ?? []), 'tasks' => array_map(fn ($t) => ['do' => $t['do'], 'notice' => $t['notice'] ?? '', 'say' => $t['say'] ?? $t['do']], $b['tasks'] ?? [])];
            } elseif (!empty($b['steps'])) {
                $html[] = '<ol>' . implode('', array_map(fn ($s) => '<li>' . $this->e($s['text']) . '</li>', $b['steps'])) . '</ol>';
            }
            foreach ($b['questions'] ?? [] as $q) {
                $html[] = '<p class="q"><b>' . (int) $q['n'] . '.</b> ' . $this->e($q['prompt']) . ' <small>(' . $this->e($q['kind']) . ($q['source'] === 'book' ? ', book' : '') . ')</small></p>';
            }
            $html[] = '</section>';
        }
        if (!empty($cp['remember'])) {
            $html[] = '<section class="blk remember"><h2>Remember</h2><ul>' . implode('', array_map(fn ($r) => '<li>' . $this->e($r) . '</li>', $cp['remember'])) . '</ul></section>';
        }
        $depth = substr_count($pp['key'], '/');
        $assets = str_repeat('../', $depth) . 'assets/';
        $js = 'var DATA=' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) . ';' . self::SCRIPT;
        return $this->shell($cp['title'] ?? $pp['title'], $assets, implode("\n", $html), $js);
    }

    private function shell(string $title, string $assets, string $body, string $js): string
    {
        $t = $this->e($title);
        return <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>$t</title><link rel="stylesheet" href="{$assets}lesson-scene.css">
<style>
:root{--bg:#f6f8fa;--fg:#102a43;--muted:#627d98;--card:#fff;--accent:#0b7285}
@media (prefers-color-scheme:dark){:root{--bg:#0f1720;--fg:#e3edf5;--muted:#9fb3c8;--card:#17212b;--accent:#4fd1c5}}
body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.55 system-ui,sans-serif}
main{max-width:860px;margin:0 auto;padding:16px}
h1{font-size:1.6rem;margin:.4em 0} h2{font-size:1.15rem;margin:0 0 .5em} h2 small{color:var(--muted);font-weight:500;font-size:.75rem;text-transform:uppercase;letter-spacing:.05em}
.blk{background:var(--card);border-radius:14px;padding:16px;margin:14px 0;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.rule{border-left:4px solid var(--accent);padding:6px 10px;background:rgba(11,114,133,.08);border-radius:6px}
.ctl{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px;align-items:center}
.ctl button{font:600 14px system-ui;border:0;border-radius:999px;padding:8px 14px;background:var(--accent);color:#fff;cursor:pointer}
.ctl button.ghost{background:transparent;color:var(--accent);border:1px solid var(--accent)}
.ctl label{display:flex;gap:8px;align-items:center;font-weight:600}
.steps{width:100%;margin:6px 0 0;padding-left:1.2em} .steps li{cursor:pointer;padding:2px 0} .steps li.on{color:var(--accent);font-weight:700}
.q small,.crumb{color:var(--muted)} a{color:var(--accent)}
</style></head>
<body><main>
$body
</main>
<script src="{$assets}three.min.js"></script><script src="{$assets}lesson-scene.js"></script>
<script>$js</script>
</body></html>
HTML;
    }

    private const SCRIPT = <<<'JS'
(function(){
  function say(t){ try{ speechSynthesis.cancel(); var u=new SpeechSynthesisUtterance(t); u.lang='en-GB'; speechSynthesis.speak(u);}catch(e){} }
  function btn(txt,cls,fn){var b=document.createElement('button');b.type='button';b.textContent=txt;if(cls)b.className=cls;b.onclick=fn;return b;}
  window.SC={};
  Object.keys(DATA).forEach(function(id){
    var d=DATA[id], host=document.getElementById('st-'+id), ctl=document.getElementById('ctl-'+id);
    var s=window.SC[id]=LessonScene.mount(host,d.scene,{});
    var steps=d.steps, cur=0, list=document.createElement('ol');
    var go=function(i,speak){cur=Math.max(0,Math.min(steps.length-1,i));s.state(cur);[].forEach.call(list.children,function(li,k){li.classList.toggle('on',k===cur);});if(speak)say(steps[cur].say);};
    if(steps.length){
      list.className='steps';
      steps.forEach(function(st,i){var li=document.createElement('li');li.textContent=st.text;li.onclick=function(){go(i,true);};list.appendChild(li);});
      ctl.appendChild(btn('Back','ghost',function(){go(cur-1,true);}));
      ctl.appendChild(btn('Next','',function(){go(cur+1,true);}));
      ctl.appendChild(btn('Play all','ghost',function(){var i=0;(function n(){go(i,true);i++;if(i<steps.length)setTimeout(n,4500);})();}));
      ctl.appendChild(list); go(0,false);
    }
    (d.scene.controls||[]).forEach(function(c){
      if(c.type==='buttons'){var w=document.createElement('span');w.textContent=c.label+': ';c.options.forEach(function(o){w.appendChild(btn(o.label,'ghost',function(){s.state(o.state-1);}));});ctl.appendChild(w);}
      else if(c.type==='slider'){var l=document.createElement('label');l.textContent=c.label;var r=document.createElement('input');r.type='range';r.min=c.min;r.max=c.max;r.step=c.step;r.value=c.value;r.id='c-'+id+'-'+c.id;r.oninput=function(){s.set(c.sets,+r.value);};l.appendChild(r);ctl.appendChild(l);s.set(c.sets,c.value);}
      else {var l2=document.createElement('label');var cb=document.createElement('input');cb.type='checkbox';cb.checked=!!c.value;cb.id='c-'+id+'-'+c.id;cb.onchange=function(){s.set(c.sets,cb.checked);};l2.appendChild(cb);l2.appendChild(document.createTextNode(' '+c.label));ctl.appendChild(l2);}
    });
    var targets={}; (d.scene.tasks||[]).forEach(function(t){targets[t.task]=t.target;});
    if(d.tasks.length){
      var tl=document.createElement('ol');tl.className='steps';
      d.tasks.forEach(function(t,i){var li=document.createElement('li');li.textContent=t.do+(t.notice?' — '+t.notice:'');li.onclick=function(){
        var tg=targets[i+1]; if(tg){ var el=document.getElementById('c-'+id+'-'+tg); if(el) el.focus(); else if(s.parts[tg]) s.pick(tg); else s.highlight([tg]); }
        say(t.say);};tl.appendChild(li);});
      ctl.appendChild(tl);
    }
  });
})();
JS;

    private function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
